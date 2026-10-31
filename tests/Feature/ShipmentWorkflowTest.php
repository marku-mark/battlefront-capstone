<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentArrangement;
use App\Enums\ShipmentStatus;
use App\Enums\ShippingProfile;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function shipmentWorkflowOrder(OrderStatus $status = OrderStatus::Processing, PaymentStatus $payment = PaymentStatus::Verified): Order
{
    $order = Order::factory()->withDeliverySnapshot('Bacolod City')->create(['status' => $status, 'payment_status' => $payment]);
    Shipment::factory()->for($order)->withHandoffDetails($order->total_amount)->create();
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '50.00']);

    return $order->refresh();
}

function advanceShipmentForTest(Order $order, ShipmentStatus $target): void
{
    $processing = app(OrderProcessingService::class);
    while ($order->refresh()->shipment->status !== $target) {
        $processing->updateShipmentStatus($order, $order->shipment->status->next());
    }
}

function unconfiguredShipmentTestOrder(
    PaymentMethod $paymentMethod = PaymentMethod::GCash,
    OrderStatus $status = OrderStatus::Pending,
    PaymentStatus $paymentStatus = PaymentStatus::Pending,
): Order {
    return Order::factory()->delivery()->create([
        'recipient_name' => 'Manual Shipment Customer',
        'product_subtotal' => '100.00',
        'delivery_destination' => 'City of Makati',
        'delivery_base_fee' => '0.00',
        'shipping_profile' => ShippingProfile::Standard,
        'handling_surcharge' => '0.00',
        'delivery_fee' => '0.00',
        'delivery_origin_city' => 'Sagay City',
        'delivery_is_demo' => false,
        'delivery_quote_metadata' => [
            'pricing_status' => 'not_configured',
            'payment_methods' => ['gcash', 'maya', 'cod'],
        ],
        'delivery_assumption_label' => 'No delivery fee is included; no LBC quotation or booking exists.',
        'total_amount' => '100.00',
        'status' => $status,
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentMethod,
        'payment_proof_path' => $paymentMethod->requiresPaymentProof()
            ? 'payment-proofs/manual-shipment-'.$paymentMethod->value.'.png'
            : null,
    ]);
}

function manualShipmentPayload(array $overrides = []): array
{
    return array_merge([
        'arrangement_confirmed' => true,
        'carrier' => 'lbc',
    ], $overrides);
}

function recordManualShipmentOperationsForTest(Order $order, User $actor): void
{
    app(OrderProcessingService::class)->updateShipmentOperations($order, [
        'package_count' => '1',
        'package_weight_kg' => '1.000',
        'package_length_cm' => '10.00',
        'package_width_cm' => '10.00',
        'package_height_cm' => '10.00',
        'declared_value' => $order->total_amount,
        'handoff_mode' => 'branch_dropoff',
    ], $actor);
}

test('shipment operations accept decimal numbers from JSON requests', function () {
    $order = shipmentWorkflowOrder();
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.operations.update', $order), [
            'package_count' => 1,
            'package_weight_kg' => 0.1,
            'package_length_cm' => 0.1,
            'package_width_cm' => 0.1,
            'package_height_cm' => 0.09,
            'declared_value' => 350,
            'handoff_mode' => 'branch_dropoff',
            'actual_carrier_cost' => 349,
            'carrier_cost_reference' => 'RECEIPT-62',
            'carrier_cost_confirmed' => true,
            'shipment_exception_code' => null,
            'shipment_exception_note' => null,
            'shipment_exception_resolved' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Shipment operations updated.');

    $shipment = $order->refresh()->shipment;
    expect($shipment->package_weight_kg)->toBe('0.100')
        ->and($shipment->package_length_cm)->toBe('0.10')
        ->and($shipment->package_height_cm)->toBe('0.09')
        ->and($shipment->actual_carrier_cost)->toBe('349.00')
        ->and($shipment->carrier_cost_reference)->toBe('RECEIPT-62');
});

test('administrators advance the full manual lifecycle and complete one sale without deducting stock', function () {
    $this->travelTo('2026-10-08 09:00:00');
    Storage::fake('local');
    $order = shipmentWorkflowOrder(OrderStatus::Pending, PaymentStatus::Pending);
    Storage::disk('local')->put($order->payment_proof_path, 'evidence');
    $stock = Inventory::sole();
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);

    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => 'verified', 'manual_verification_confirmed' => true,
    ])->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing'])->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);

    $milestones = [
        'preparing' => 'preparing_at',
        'ready_for_dispatch' => 'ready_for_dispatch_at',
        'handed_to_lbc' => 'handed_to_carrier_at',
        'in_transit' => 'in_transit_at',
        'out_for_delivery' => 'out_for_delivery_at',
        'delivered' => 'delivered_at',
    ];
    foreach ($milestones as $status => $timestamp) {
        $this->travel(1)->hours();
        $submission = ['status' => $status];
        if ($status === 'handed_to_lbc') {
            $submission['tracking_reference'] = ' ACTUAL-LBC-REFERENCE ';
        }
        $this->patch(route('administration.orders.shipment.status.update', $order), $submission)
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Shipment status updated.');
        expect($order->refresh()->shipment->status->value)->toBe($status);
        expect($order->shipment->getAttribute($timestamp)->toDateTimeString())->toBe(now()->toDateTimeString());
        expect($order->status)->toBe($status === 'delivered' ? OrderStatus::Completed : OrderStatus::Processing);
    }

    expect($order->shipment->tracking_reference)->toBe('ACTUAL-LBC-REFERENCE');
    expect($stock->refresh()->quantity)->toBe(5);
    expect($order->sale)->amount->toBe('100.00');
    expect($order->total_amount)->toBe('250.00');
    $this->assertDatabaseCount('sales', 1);
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])->assertSessionHasErrors('status');
    $this->assertDatabaseCount('sales', 1);
});

test('shipment preparation requires both verified payment and a processing order', function (OrderStatus $status, PaymentStatus $payment) {
    $order = shipmentWorkflowOrder($status, $payment);
    $before = $order->shipment->getAttributes();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'preparing'])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    expect($order->status)->toBe($status);
    expect(Inventory::sole()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'payment pending' => [OrderStatus::Processing, PaymentStatus::Pending],
    'payment rejected' => [OrderStatus::Processing, PaymentStatus::Rejected],
    'order pending' => [OrderStatus::Pending, PaymentStatus::Verified],
    'order cancelled' => [OrderStatus::Cancelled, PaymentStatus::Verified],
    'previously completed order' => [OrderStatus::Completed, PaymentStatus::Verified],
]);

test('shipment rejects skips backwards repeats and terminal transitions', function (ShipmentStatus $initial, string $requested) {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, $initial);
    $before = $order->shipment->getAttributes();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.status.update', $order), ['status' => $requested])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with([
    'skip' => [ShipmentStatus::AwaitingPreparation, 'ready_for_dispatch'],
    'backwards' => [ShipmentStatus::ReadyForDispatch, 'preparing'],
    'repeat' => [ShipmentStatus::Preparing, 'preparing'],
    'manual cancellation outside order workflow' => [ShipmentStatus::Preparing, 'cancelled'],
    'terminal backwards' => [ShipmentStatus::Delivered, 'out_for_delivery'],
    'terminal repeat' => [ShipmentStatus::Delivered, 'delivered'],
    'unknown' => [ShipmentStatus::AwaitingPreparation, 'booked'],
]);

test('pickup and legacy orders reject shipment mutations without creating shipment records', function (bool $delivery, string $endpoint) {
    $factory = Order::factory();
    $order = ($delivery ? $factory->delivery() : $factory)->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified]);
    $payload = $endpoint === 'status' ? ['status' => 'preparing'] : ['tracking_reference' => 'SUPPLIED'];

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.'.$endpoint.'.update', $order), $payload)
        ->assertSessionHasErrors($endpoint === 'status' ? 'status' : 'tracking_reference');

    $this->assertDatabaseCount('shipments', 0);
    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
})->with(['pickup' => [false], 'legacy delivery' => [true]])->with(['status', 'reference']);

test('shipment endpoints reject unauthenticated and customer mutations', function (string $access, string $endpoint) {
    $order = shipmentWorkflowOrder();
    $before = $order->shipment->getAttributes();
    if ($access === 'customer') {
        $this->actingAs($order->user);
    } elseif ($access === 'bearer') {
        $this->withToken($order->user->createToken('Phone')->plainTextToken);
    }
    $response = $this->patch(route('administration.orders.shipment.'.$endpoint.'.update', $order), [
        'status' => 'preparing', 'tracking_reference' => 'SUPPLIED',
    ]);
    if ($access === 'customer') {
        $response->assertForbidden();
    } else {
        $response->assertRedirectToRoute('login');
    }

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with(['guest', 'customer', 'bearer'])->with(['status', 'reference']);

test('direct order completion cannot bypass delivery milestones', function () {
    $order = shipmentWorkflowOrder();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])
        ->assertSessionHasErrors(['status' => 'Complete delivery through the shipment Delivered milestone.']);

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);
    $this->assertDatabaseCount('sales', 0);
});

test('cancelling an eligible delivery terminates its shipment and restores stock exactly once', function (ShipmentStatus $milestone, OrderStatus $status) {
    $order = shipmentWorkflowOrder($status);
    advanceShipmentForTest($order, $milestone);
    $shipment = $order->shipment->getAttributes();
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->shipment)->status->toBe(ShipmentStatus::Cancelled)->cancelled_at->not->toBeNull();
    expect($order->shipment->handed_to_carrier_at?->toDateTimeString())->toBe($shipment['handed_to_carrier_at']);
    expect(Inventory::sole()->quantity)->toBe(7);

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'delivered'])->assertSessionHasErrors('status');
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'SUPPLIED'])->assertSessionHasErrors('tracking_reference');
    expect(Inventory::sole()->quantity)->toBe(7);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'pending before packing' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Pending],
    'processing before packing' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Processing],
    'preparing' => [ShipmentStatus::Preparing, OrderStatus::Processing],
    'ready' => [ShipmentStatus::ReadyForDispatch, OrderStatus::Processing],
]);

test('sale failure rolls back shipment delivery order completion and timestamps together', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::OutForDelivery);
    Event::listen('eloquent.created: '.Sale::class, fn (): never => throw new RuntimeException('Sale failure.'));

    try {
        expect(fn () => app(OrderProcessingService::class)->updateShipmentStatus($order, ShipmentStatus::Delivered))
            ->toThrow(RuntimeException::class, 'Sale failure.');
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment)->status->toBe(ShipmentStatus::OutForDelivery)->delivered_at->toBeNull();
    expect(Inventory::sole()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 0);
});

test('administrator cancellation after LBC handoff preserves stock payment shipment history and sales', function (ShipmentStatus $milestone) {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, $milestone);
    $originalShipment = $order->shipment->getAttributes();
    $notificationCount = $order->user->notifications()->count();
    $salesCount = Sale::query()->count();
    $message = 'This shipment has been handed to LBC. Normal order cancellation is no longer available.';
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $originalOrder = $order->refresh()->getAttributes();

    $this->actingAs($administrator);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.allowed_status_transitions', [])
        ->where('order.cancellation_blocked_reason', $message));
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])
        ->assertSessionHasErrors(['status' => $message]);
    expect($order->refresh()->getAttributes())->toBe($originalOrder);
    expect($order->shipment->getAttributes())->toBe($originalShipment);
    expect(Inventory::sole()->quantity)->toBe(5);
    expect($order->user->notifications()->count())->toBe($notificationCount);
    expect(Sale::query()->count())->toBe($salesCount);
})->with([ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);

test('cancellation and LBC handoff recheck persisted shipment state rather than a cached relation', function (bool $cancellationFirst) {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::ReadyForDispatch);
    $staleOrder = Order::query()->with('shipment')->findOrFail($order->id);
    $service = app(OrderProcessingService::class);
    $notificationCount = $order->user->notifications()->count();
    if ($cancellationFirst) {
        $service->updateStatus($order, OrderStatus::Cancelled);
        expect(fn () => $service->updateShipmentStatus($staleOrder, ShipmentStatus::HandedToLbc))
            ->toThrow(ValidationException::class);
        expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::Cancelled)->handed_to_carrier_at->toBeNull();
        expect(Inventory::sole()->quantity)->toBe(7);
    } else {
        $service->updateShipmentStatus($order, ShipmentStatus::HandedToLbc);
        expect(fn () => $service->updateStatus($staleOrder, OrderStatus::Cancelled))
            ->toThrow(ValidationException::class, 'This shipment has been handed to LBC.');
        expect($order->refresh()->status)->toBe(OrderStatus::Processing);
        expect($order->shipment)->status->toBe(ShipmentStatus::HandedToLbc)->cancelled_at->toBeNull();
        expect(Inventory::sole()->quantity)->toBe(5);
    }
    expect($order->payment_status)->toBe(PaymentStatus::Verified);
    expect($order->user->notifications()->count())->toBe($notificationCount + 1);
    $this->assertDatabaseCount('sales', 0);
})->with([true, false]);

test('shipment cancellation failure rolls back order stock and shipment together', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::ReadyForDispatch);
    Event::listen('eloquent.updated: '.Shipment::class, fn (): never => throw new RuntimeException('Shipment failure.'));

    try {
        expect(fn () => app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Cancelled))
            ->toThrow(RuntimeException::class, 'Shipment failure.');
    } finally {
        Event::forget('eloquent.updated: '.Shipment::class);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect($order->shipment)->status->toBe(ShipmentStatus::ReadyForDispatch)->cancelled_at->toBeNull();
    expect(Inventory::sole()->quantity)->toBe(5);
});

test('operational ETA is persisted at preparation and survives later time and configuration changes', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo('2028-02-27 17:00:00 UTC');
    $order = shipmentWorkflowOrder();
    $processing = app(OrderProcessingService::class);

    $processing->updateShipmentStatus($order, ShipmentStatus::Preparing);
    expect($order->refresh()->shipment->eta_anchor_date->toDateString())->toBe('2028-02-28');
    expect($order->shipment->estimated_delivery_start->toDateString())->toBe('2028-03-01');
    expect($order->shipment->estimated_delivery_end->toDateString())->toBe('2028-03-02');
    expect($order->shipment->eta_timezone)->toBe('Asia/Manila');
    config(['app.timezone' => 'UTC', 'battlefront.delivery.carrier' => 'changed']);
    $this->travel(10)->days();
    $processing->updateShipmentStatus($order, ShipmentStatus::ReadyForDispatch);

    expect($order->refresh()->shipment->estimated_delivery_start->toDateString())->toBe('2028-03-01');
    expect($order->shipment->eta_timezone)->toBe('Asia/Manila');
    expect($order->shipment->carrier)->toBe('lbc');
    expect(fn () => $order->shipment->update(['estimated_delivery_start' => '2028-03-20']))
        ->toThrow(DomainException::class, 'The operational shipment estimate cannot be changed.');
});

test('real references supplied at handoff cannot be replaced or cleared', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::ReadyForDispatch);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'handed_to_lbc', 'tracking_reference' => ' REAL-REFERENCE '])
        ->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->tracking_reference)->toBe('REAL-REFERENCE');
    $handoff = $order->shipment->handed_to_carrier_at->toDateTimeString();
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.shipment_actions.can_update_reference', false));

    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'CORRECTED'])->assertSessionHasErrors('tracking_reference');
    expect($order->refresh()->shipment)->tracking_reference->toBe('REAL-REFERENCE')->status->toBe(ShipmentStatus::HandedToLbc);
    expect($order->shipment->handed_to_carrier_at->toDateTimeString())->toBe($handoff);
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => '  '])->assertSessionHasErrors('tracking_reference');
    expect($order->refresh()->shipment->tracking_reference)->toBe('REAL-REFERENCE');

    advanceShipmentForTest($order, ShipmentStatus::Delivered);
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'REAL-LATE-REFERENCE'])->assertSessionHasErrors('tracking_reference');
    expect($order->refresh()->shipment)->tracking_reference->toBe('REAL-REFERENCE')->status->toBe(ShipmentStatus::Delivered);
    $this->assertDatabaseCount('sales', 1);
});

test('references reject early entry and malformed values', function (mixed $reference, bool $handoff) {
    $order = shipmentWorkflowOrder();
    if ($handoff) {
        advanceShipmentForTest($order, ShipmentStatus::HandedToLbc);
    }

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => $reference])
        ->assertSessionHasErrors('tracking_reference');

    expect($order->refresh()->shipment->tracking_reference)->toBeNull();
})->with([
    'before handoff' => ['REAL-REFERENCE', false],
    'too long' => [str_repeat('x', 256), true],
    'array' => [['forged'], true],
]);

test('shipment progression ignores submitted carrier timestamps ETA and order status', function () {
    $order = shipmentWorkflowOrder();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.status.update', $order), [
            'status' => 'preparing', 'carrier' => 'forged', 'preparing_at' => '2000-01-01',
            'estimated_delivery_start' => '2000-01-01', 'order_status' => 'completed', 'delivery_fee' => '0.00',
        ])->assertSessionHasNoErrors();

    expect($order->refresh())->status->toBe(OrderStatus::Processing)->delivery_fee->toBe('150.00');
    expect($order->shipment)->carrier->toBe('lbc')->status->toBe(ShipmentStatus::Preparing);
    expect($order->shipment->preparing_at->toDateString())->toBe(now()->toDateString());
    $this->assertDatabaseCount('sales', 0);
});

test('a reference supplied before handoff rejects the entire milestone update', function () {
    $order = shipmentWorkflowOrder();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->patch(route('administration.orders.shipment.status.update', $order), [
            'status' => 'preparing', 'tracking_reference' => 'SUPPLIED-TOO-EARLY',
        ])->assertSessionHasErrors('tracking_reference');

    expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation)
        ->tracking_reference->toBeNull()->preparing_at->toBeNull()->eta_anchor_date->toBeNull();
});

test('customer mobile API has no shipment mutation endpoint', function (string $endpoint) {
    $order = shipmentWorkflowOrder();
    $before = $order->shipment->getAttributes();

    $this->withToken($order->user->createToken('Phone')->plainTextToken)
        ->patch('/api/v1/orders/'.$order->id.'/shipment/'.$endpoint, ['status' => 'delivered', 'tracking_reference' => 'FORGED'])
        ->assertNotFound();

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
})->with(['status', 'reference']);

test('manual arrangement availability follows prepaid processing and preserves pending COD', function (PaymentMethod $method, OrderStatus $status, PaymentStatus $paymentStatus, bool $eligible, string $nextStep) {
    $order = unconfiguredShipmentTestOrder($method, $status, $paymentStatus);
    if ($method === PaymentMethod::Online) {
        $order->payment_origin = 'paymongo';
        $order->save();
    }
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $this->actingAs($administrator);

    $this->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.can_create_manual_shipment', $eligible)
            ->where('order.delivery_next_step.title', $nextStep));

    $before = $order->refresh()->getAttributes();
    $response = $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload());

    if ($eligible) {
        $response->assertRedirect()->assertSessionHasNoErrors();
        expect($order->refresh()->shipment)->arrangement_type->toBe(ShipmentArrangement::Manual);
        expect($order->user->notifications()->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors('carrier');
        expect($order->refresh()->shipment)->toBeNull();
        expect($order->user->notifications()->count())->toBe(0);
    }

    expect($order->getAttributes())->toBe($before);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'pending GCash awaiting verification' => [PaymentMethod::GCash, OrderStatus::Pending, PaymentStatus::Pending, false, 'Verify payment'],
    'pending GCash already verified' => [PaymentMethod::GCash, OrderStatus::Pending, PaymentStatus::Verified, false, 'Move order to Processing'],
    'processing GCash' => [PaymentMethod::GCash, OrderStatus::Processing, PaymentStatus::Verified, true, 'Confirm delivery arrangement'],
    'pending Maya awaiting verification' => [PaymentMethod::Maya, OrderStatus::Pending, PaymentStatus::Pending, false, 'Verify payment'],
    'pending Maya already verified' => [PaymentMethod::Maya, OrderStatus::Pending, PaymentStatus::Verified, false, 'Move order to Processing'],
    'processing Maya' => [PaymentMethod::Maya, OrderStatus::Processing, PaymentStatus::Verified, true, 'Confirm delivery arrangement'],
    'pending PayMongo awaiting confirmation' => [PaymentMethod::Online, OrderStatus::Pending, PaymentStatus::Pending, false, 'Await payment confirmation'],
    'pending PayMongo already verified' => [PaymentMethod::Online, OrderStatus::Pending, PaymentStatus::Verified, false, 'Move order to Processing'],
    'processing PayMongo' => [PaymentMethod::Online, OrderStatus::Processing, PaymentStatus::Verified, true, 'Confirm delivery arrangement'],
    'pending COD' => [PaymentMethod::Cod, OrderStatus::Pending, PaymentStatus::Pending, true, 'Confirm delivery arrangement'],
    'processing COD' => [PaymentMethod::Cod, OrderStatus::Processing, PaymentStatus::Pending, true, 'Confirm delivery arrangement'],
]);

test('administrators register a manual shipment without changing commercial snapshots', function () {
    $order = unconfiguredShipmentTestOrder(status: OrderStatus::Processing, paymentStatus: PaymentStatus::Verified);
    $administrator = User::factory()->administrator()->create();
    $originalCommercialSnapshot = [
        $order->product_subtotal,
        $order->delivery_fee,
        $order->total_amount,
        $order->delivery_quote_metadata,
    ];

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload([
            'tracking_reference' => 'LBC-BOOKING-REFERENCE',
            'eta_min_days' => 2,
            'eta_max_days' => 5,
            'delivery_fee' => '250.00',
            'total_amount' => '350.00',
        ]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Manual LBC shipment recorded. The order amount remains unchanged.');

    $shipment = $order->refresh()->shipment;
    expect($shipment)
        ->arrangement_type->toBe(ShipmentArrangement::Manual)
        ->carrier->toBe('lbc')
        ->status->toBe(ShipmentStatus::AwaitingPreparation)
        ->tracking_reference->toBe('LBC-BOOKING-REFERENCE')
        ->preparation_days->toBeNull()
        ->transit_min_days->toBeNull()
        ->transit_max_days->toBeNull()
        ->eta_min_days->toBe(2)
        ->eta_max_days->toBe(5)
        ->eta_anchor_date->toBeNull()
        ->estimated_delivery_start->toBeNull();
    expect([
        $order->product_subtotal,
        $order->delivery_fee,
        $order->total_amount,
        $order->delivery_quote_metadata,
    ])->toBe($originalCommercialSnapshot);
    expect($order->user->notifications()->sole()->data['event'])->toBe('shipment.manual_created');
    $this->assertDatabaseCount('shipments', 1);
    $this->assertDatabaseCount('sales', 0);
});

test('manual shipment setup leaves unknown estimates null through preparation', function () {
    $order = unconfiguredShipmentTestOrder(
        paymentMethod: PaymentMethod::GCash,
        status: OrderStatus::Processing,
        paymentStatus: PaymentStatus::Verified,
    );
    $administrator = User::factory()->administrator()->create();

    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
        ->assertSessionHasNoErrors();

    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'preparing'])
        ->assertSessionHasNoErrors();

    $shipment = $order->refresh()->shipment;
    expect($shipment)->status->toBe(ShipmentStatus::Preparing)
        ->eta_min_days->toBeNull()
        ->eta_max_days->toBeNull()
        ->eta_anchor_date->toBeNull()
        ->eta_timezone->toBeNull()
        ->estimated_delivery_start->toBeNull()
        ->estimated_delivery_end->toBeNull();
    expect($order->user->notifications()->pluck('data')->pluck('event')->sort()->values()->all())
        ->toBe(['shipment.manual_created', 'shipment.preparing']);
});

test('manual shipment setup rejects duplicate and ineligible orders without side effects', function (string $scenario) {
    $order = match ($scenario) {
        'configured delivery' => Order::factory()->withDeliverySnapshot()->create(),
        'legacy delivery' => Order::factory()->delivery()->create(),
        'pickup' => Order::factory()->create(),
        'duplicate' => unconfiguredShipmentTestOrder(status: OrderStatus::Processing, paymentStatus: PaymentStatus::Verified),
        'rejected payment' => unconfiguredShipmentTestOrder(
            paymentMethod: PaymentMethod::GCash,
            paymentStatus: PaymentStatus::Rejected,
        ),
    };
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);

    if ($scenario === 'duplicate') {
        $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
            ->assertSessionHasNoErrors();
    }

    $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
        ->assertSessionHasErrors('carrier');

    if ($scenario === 'duplicate') {
        expect($order->refresh()->shipment)->not->toBeNull();
    } else {
        expect($order->refresh()->shipment)->toBeNull();
    }
    $this->assertDatabaseCount('shipments', $scenario === 'duplicate' ? 1 : 0);
    $this->assertDatabaseCount('notifications', $scenario === 'duplicate' ? 1 : 0);
})->with([
    'configured delivery' => ['configured delivery'],
    'legacy delivery' => ['legacy delivery'],
    'pickup' => ['pickup'],
    'rejected payment' => ['rejected payment'],
    'duplicate' => ['duplicate'],
]);

test('manual shipment input requires explicit LBC confirmation and complete valid estimates', function (array $overrides, string $field) {
    $order = unconfiguredShipmentTestOrder(status: OrderStatus::Processing, paymentStatus: PaymentStatus::Verified);

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)
        ->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload($overrides))
        ->assertSessionHasErrors($field);

    expect($order->refresh()->shipment)->toBeNull();
    expect($order->user->notifications()->count())->toBe(0);
})->with([
    'arrangement not confirmed' => [['arrangement_confirmed' => false], 'arrangement_confirmed'],
    'unsupported carrier' => [['carrier' => 'other carrier'], 'carrier'],
    'minimum without maximum' => [['eta_min_days' => 2], 'eta_max_days'],
    'maximum without minimum' => [['eta_max_days' => 2], 'eta_min_days'],
    'reversed estimate range' => [['eta_min_days' => 5, 'eta_max_days' => 2], 'eta_max_days'],
    'negative estimate' => [['eta_min_days' => -1, 'eta_max_days' => 2], 'eta_min_days'],
    'tracking reference too long' => [['tracking_reference' => str_repeat('x', 256)], 'tracking_reference'],
]);

test('manual shipment registration is administrator-only', function (string $access) {
    $order = unconfiguredShipmentTestOrder(status: OrderStatus::Processing, paymentStatus: PaymentStatus::Verified);
    $response = $this->post(
        route('administration.orders.shipment.manual.store', $order),
        manualShipmentPayload(),
    );

    if ($access === 'administrator') {
        $administrator = User::factory()->administrator()->create();
        $order->assigned_handler_id = $administrator->id;
        $order->save();

        $this->actingAs($administrator)
            ->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
            ->assertSessionHasNoErrors();

        return;
    }

    if ($access === 'customer') {
        $this->actingAs($order->user);
        $response = $this->post(
            route('administration.orders.shipment.manual.store', $order),
            manualShipmentPayload(),
        );
        $response->assertForbidden();
    } else {
        $response->assertRedirectToRoute('login');
    }

    $this->assertDatabaseCount('shipments', 0);
})->with(['guest', 'customer', 'administrator']);

test('unconfigured delivery cannot complete without a delivered manual shipment', function (PaymentMethod $method, ?ShipmentStatus $milestone) {
    $order = unconfiguredShipmentTestOrder(
        paymentMethod: $method,
        status: OrderStatus::Processing,
        paymentStatus: $method === PaymentMethod::Cod ? PaymentStatus::Pending : PaymentStatus::Verified,
    );
    $inventory = Inventory::factory()->create(['quantity' => 5]);
    OrderItem::factory()->for($order)->for($inventory->product)->create([
        'quantity' => 2,
        'price_at_time' => '50.00',
    ]);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $this->actingAs($administrator);

    if ($milestone !== null) {
        $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $order->refresh()->shipment->update(['status' => $milestone]);
    }

    $before = $order->refresh()->getAttributes();
    $shipmentBefore = $order->shipment?->getAttributes();
    $notificationCount = $order->user->notifications()->count();

    expect(app(OrderProcessingService::class)->allowedOrderTransitions($order))
        ->not->toContain(OrderStatus::Completed);

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->getAttributes())->toBe($before);
    expect($order->shipment?->getAttributes())->toBe($shipmentBefore);
    expect($inventory->refresh()->quantity)->toBe(5);
    expect($order->user->notifications()->count())->toBe($notificationCount);
    $this->assertDatabaseCount('sales', 0);
})->with([PaymentMethod::GCash, PaymentMethod::Maya, PaymentMethod::Cod])->with([
    'no shipment' => [null],
    'awaiting preparation' => [ShipmentStatus::AwaitingPreparation],
    'preparing' => [ShipmentStatus::Preparing],
    'ready for dispatch' => [ShipmentStatus::ReadyForDispatch],
    'handed to LBC' => [ShipmentStatus::HandedToLbc],
    'in transit' => [ShipmentStatus::InTransit],
    'out for delivery' => [ShipmentStatus::OutForDelivery],
    'cancelled shipment' => [ShipmentStatus::Cancelled],
]);

test('manual GCash and Maya shipments require verified payment and complete once', function (PaymentMethod $method) {
    Storage::fake('local');
    $order = unconfiguredShipmentTestOrder($method);
    Storage::disk('local')->put($order->payment_proof_path, 'proof');
    $inventory = Inventory::factory()->create(['quantity' => 5]);
    OrderItem::factory()->for($order)->for($inventory->product)->create([
        'quantity' => 2,
        'price_at_time' => '50.00',
    ]);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);

    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'preparing'])
        ->assertSessionHasErrors('status');
    expect($order->refresh()->shipment)->toBeNull();
    $this->assertDatabaseCount('sales', 0);

    $this->patch(route('administration.orders.payment-status.update', $order), [
        'payment_status' => 'verified',
        'manual_verification_confirmed' => true,
    ])->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing'])
        ->assertSessionHasNoErrors();
    $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload([
        'eta_min_days' => 1,
        'eta_max_days' => 3,
    ]))->assertSessionHasNoErrors();
    recordManualShipmentOperationsForTest($order, $administrator);
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])
        ->assertSessionHasErrors('status');
    expect($order->refresh()->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation);
    $this->assertDatabaseCount('sales', 0);

    foreach (['preparing', 'ready_for_dispatch', 'handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered'] as $status) {
        $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    expect($order->refresh())->status->toBe(OrderStatus::Completed)
        ->payment_status->toBe(PaymentStatus::Verified)
        ->delivery_fee->toBe('0.00')
        ->total_amount->toBe('100.00');
    expect($order->shipment)->status->toBe(ShipmentStatus::Delivered);
    expect($order->sale)->amount->toBe('100.00');
    expect($inventory->refresh()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 1);
    $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => 'delivered'])
        ->assertSessionHasErrors('status');
    $this->assertDatabaseCount('sales', 1);
})->with([PaymentMethod::GCash, PaymentMethod::Maya]);

test('manual shipment cancellation is blocked after handoff to LBC', function () {
    $order = unconfiguredShipmentTestOrder(
        paymentMethod: PaymentMethod::GCash,
        status: OrderStatus::Processing,
        paymentStatus: PaymentStatus::Verified,
    );
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
        ->assertSessionHasNoErrors();
    recordManualShipmentOperationsForTest($order, $administrator);
    foreach (['preparing', 'ready_for_dispatch', 'handed_to_lbc'] as $status) {
        $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    $this->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])
        ->assertSessionHasErrors('status');

    expect($order->refresh())->status->toBe(OrderStatus::Processing);
    expect($order->shipment)->status->toBe(ShipmentStatus::HandedToLbc);
    $this->assertDatabaseCount('sales', 0);
});

test('manual COD delivery still waits for explicit collection confirmation', function () {
    $order = unconfiguredShipmentTestOrder(
        paymentMethod: PaymentMethod::Cod,
        status: OrderStatus::Pending,
        paymentStatus: PaymentStatus::Pending,
    );
    $inventory = Inventory::factory()->create(['quantity' => 5]);
    OrderItem::factory()->for($order)->for($inventory->product)->create([
        'quantity' => 2,
        'price_at_time' => '50.00',
    ]);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    $this->post(route('administration.orders.shipment.manual.store', $order), manualShipmentPayload())
        ->assertSessionHasNoErrors();
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing'])
        ->assertSessionHasNoErrors();
    recordManualShipmentOperationsForTest($order, $administrator);

    foreach (['preparing', 'ready_for_dispatch', 'handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered'] as $status) {
        $this->patch(route('administration.orders.shipment.status.update', $order), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    expect($order->refresh())->status->toBe(OrderStatus::Processing)
        ->payment_status->toBe(PaymentStatus::Pending)
        ->delivery_fee->toBe('0.00')
        ->total_amount->toBe('100.00');
    $this->assertDatabaseCount('sales', 0);
    $this->post(route('administration.orders.cod-collection.store', $order), [
        'cod_collection_confirmed' => true,
    ])->assertSessionHasNoErrors();

    expect($order->refresh())->status->toBe(OrderStatus::Processing)
        ->payment_status->toBe(PaymentStatus::Pending);
    $this->post(route('administration.orders.cod-remittance.store', $order), [
        'cod_remittance_confirmed' => true,
        'carrier_cod_remittance_reference' => 'LBC-REMITTANCE-REFERENCE',
    ])->assertSessionHasNoErrors();

    expect($order->refresh())->status->toBe(OrderStatus::Completed)
        ->payment_status->toBe(PaymentStatus::Verified);
    expect($order->sale)->amount->toBe('100.00');
    expect($inventory->refresh()->quantity)->toBe(5);
    $this->assertDatabaseCount('sales', 1);
});

test('a missing LBC reference may be assigned once after handoff', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::HandedToLbc);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $staleOrder = $order->fresh();

    $this->actingAs($administrator)->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => ' LATE-REFERENCE '])->assertRedirect()->assertSessionHasNoErrors();
    expect($order->refresh()->shipment->tracking_reference)->toBe('LATE-REFERENCE');
    expect(fn () => app(OrderProcessingService::class)->updateShipmentReference($staleOrder, 'REPLACEMENT', actor: $administrator))
        ->toThrow(ValidationException::class, 'The LBC tracking number is read-only once assigned.');
    expect($order->fresh()->shipment->tracking_reference)->toBe('LATE-REFERENCE');
});

test('handoff cannot replace a reference already assigned to a manual shipment', function () {
    $order = shipmentWorkflowOrder();
    advanceShipmentForTest($order, ShipmentStatus::ReadyForDispatch);
    $order->shipment->update(['tracking_reference' => 'ASSIGNED-REFERENCE']);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->patch(route('administration.orders.shipment.status.update', $order), [
        'status' => 'handed_to_lbc', 'tracking_reference' => 'REPLACEMENT',
    ])->assertSessionHasErrors('tracking_reference');

    expect($order->refresh()->shipment)->tracking_reference->toBe('ASSIGNED-REFERENCE')->status->toBe(ShipmentStatus::ReadyForDispatch);
});
