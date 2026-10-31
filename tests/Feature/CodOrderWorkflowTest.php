<?php

use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use App\Repositories\Reporting\MonthlySalesRepository;
use App\Repositories\Reporting\SalesReportRepository;
use App\Services\Cart\CartService;
use App\Services\Dashboard\DashboardService;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderProcessingService;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function codWorkflowOrder(ShipmentStatus $milestone = ShipmentStatus::AwaitingPreparation, OrderStatus $status = OrderStatus::Pending): Order
{
    $order = Order::factory()->cashOnDelivery()->create(['status' => $status]);
    Shipment::factory()->for($order)->withHandoffDetails($order->total_amount)->create([
        'status' => $milestone,
        'delivered_at' => $milestone === ShipmentStatus::Delivered ? now() : null,
    ]);
    $product = Product::factory()->create();
    Inventory::factory()->for($product)->create(['quantity' => 8]);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '50.00']);

    return $order->refresh();
}

/** @return array<string, mixed> */
function codCheckoutPayload(int $itemId): array
{
    return [
        'cart_item_ids' => [$itemId], 'recipient_name' => 'COD Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => 'delivery', 'payment_method' => 'cod', 'delivery_destination' => 'Sagay City',
        'delivery_barangay_code' => '1804523018', 'delivery_address_details' => '12 Mabini Street',
    ];
}

test('web and mobile checkout advertise COD for delivery only without proof or receiving account', function (string $channel) {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $item = app(CartService::class)->add($customer, Inventory::factory()->create(['quantity' => 5])->product_id, 1);
    $options = ['cart_item_ids' => [$item->id]];
    $expected = ['value' => 'cod', 'label' => 'Cash on Delivery (COD)', 'requires_proof' => false, 'payment_account' => null, 'available_for' => ['delivery']];

    if ($channel === 'web') {
        $this->actingAs($customer)->get(route('checkout.index', $options))
            ->assertInertia(fn (Assert $page) => $page->where(
                'paymentMethods',
                fn ($methods): bool => collect($methods)->firstWhere('value', 'cod') === $expected,
            ));
    } else {
        $this->withToken($customer->createToken('Phone')->plainTextToken)
            ->getJson('/api/v1/checkout?'.http_build_query($options))->assertJsonFragment($expected);
    }

    $this->assertDatabaseCount('orders', 0);
    expect(Inventory::sole()->quantity)->toBe(5);
})->with(['web', 'mobile']);

test('COD checkout places quoted delivery without proof and consumes only selected inventory and cart rows', function (string $channel, bool $legacyAddress) {
    Storage::fake('local');
    Http::preventStrayRequests();
    $customer = User::factory()->customer()->create();
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['price' => '110.00', 'discount_price' => '100.00']);
    $stock = Inventory::factory()->for($product)->create(['quantity' => 10]);
    $item = app(CartService::class)->add($customer, $product->id, 2);
    $remaining = app(CartService::class)->add($customer, Inventory::factory()->create(['quantity' => 3])->product_id, 1);
    $payload = codCheckoutPayload($item->id) + ['payment_status' => 'verified', 'status' => 'completed', 'total_amount' => '0.01', 'delivery_fee' => '0.00'];
    if ($legacyAddress) {
        unset($payload['delivery_barangay_code'], $payload['delivery_address_details']);
        $payload['delivery_address'] = 'Legacy delivery address';
    }

    if ($channel === 'web') {
        $response = $this->actingAs($customer)->post(route('orders.store'), $payload)->assertSessionHasNoErrors();
        $response->assertRedirectToRoute('orders.show', Order::sole());
    } else {
        $this->withToken($customer->createToken('Phone')->plainTextToken)->postJson('/api/v1/orders', $payload)
            ->assertCreated()->assertJsonPath('data.payment.method.value', 'cod')->assertJsonPath('data.payment.proof_submitted', false)
            ->assertJsonPath('data.payment.can_resubmit_proof', false)->assertJsonPath('data.total', '395.00');
    }

    $order = Order::sole();
    expect($order)->status->toBe(OrderStatus::Pending)->payment_status->toBe(PaymentStatus::Pending)
        ->product_subtotal->toBe('200.00')->delivery_fee->toBe('195.00')->total_amount->toBe('395.00')
        ->payment_proof_path->toBeNull()->delivery_is_demo->toBeTrue();
    expect($order->delivery_address)->toBe($legacyAddress ? 'Legacy delivery address' : '12 Mabini Street, Poblacion I, Sagay City, Negros Occidental');
    expect($order->shipment)->status->toBe(ShipmentStatus::AwaitingPreparation)->carrier->toBe('lbc');
    expect($stock->refresh()->quantity)->toBe(8);
    $this->assertModelMissing($item);
    $this->assertModelExists($remaining);
    expect($administrator->notifications()->sole()->data['event'])->toBe('order.placed');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $this->assertDatabaseCount('sales', 0);
    Http::assertNothingSent();
})->with(['web structured' => ['web', false], 'mobile structured' => ['mobile', false], 'mobile legacy' => ['mobile', true]]);

test('web and mobile reject COD pickup proof and invalid delivery data without side effects', function (string $channel, string $scenario, string $field) {
    Storage::fake('local');
    $customer = User::factory()->customer()->create();
    $item = app(CartService::class)->add($customer, Inventory::factory()->create(['quantity' => 5])->product_id, 1);
    $payload = codCheckoutPayload($item->id);
    if ($scenario === 'pickup') {
        $payload['fulfillment_method'] = 'pickup';
        unset($payload['delivery_destination'], $payload['delivery_barangay_code'], $payload['delivery_address_details']);
    } elseif ($scenario === 'proof') {
        $payload['payment_proof'] = UploadedFile::fake()->image('proof.png');
    } elseif ($scenario === 'destination') {
        $payload['delivery_destination'] = 'Unsupported City';
    } else {
        unset($payload['delivery_address_details']);
    }

    if ($channel === 'web') {
        $this->actingAs($customer)->post(route('orders.store'), $payload)->assertSessionHasErrors($field);
    } else {
        $this->withToken($customer->createToken('Phone')->plainTextToken)->post('/api/v1/orders', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('shipments', 0);
    $this->assertDatabaseCount('notifications', 0);
    $this->assertModelExists($item);
    expect(Inventory::sole()->quantity)->toBe(5);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['web', 'mobile'])->with([
    'pickup' => ['pickup', 'payment_method'], 'proof' => ['proof', 'payment_proof'],
    'destination' => ['destination', 'delivery_destination'], 'address' => ['address', 'delivery_address_details'],
]);

test('the shared service refuses unquoted COD placement even with otherwise valid delivery input', function () {
    $customer = User::factory()->customer()->create();
    $item = app(CartService::class)->add($customer, Inventory::factory()->create(['quantity' => 5])->product_id, 1);
    $checkout = [
        'cart_item_ids' => [$item->id], 'recipient_name' => 'Customer', 'contact_number' => '09171234567',
        'fulfillment_method' => FulfillmentMethod::Delivery, 'delivery_address' => 'Submitted address',
        'payment_method' => PaymentMethod::Cod, 'payment_proof_path' => null,
    ];

    expect(fn () => app(OrderPlacementService::class)->execute($customer, $checkout))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('orders', 0);
    $this->assertModelExists($item);
    expect(Inventory::sole()->quantity)->toBe(5);
});

test('COD progresses through shipment milestones and separates collection from remittance', function () {
    $this->travelTo('2026-10-12 09:00:00');
    $order = codWorkflowOrder();
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.payment.status.value', 'pending')
        ->where('order.allowed_status_transitions', [
            ['value' => 'processing', 'label' => 'Processing'],
            ['value' => 'cancelled', 'label' => 'Cancelled'],
        ]));
    $this->patch(route('administration.orders.status.update', $order), ['status' => 'processing', 'products_handed_over_confirmed' => false])->assertSessionHasNoErrors();
    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    expect($order->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $shipmentSnapshot = $order->refresh()->shipment->getAttributes();

    foreach ([ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $milestone) {
        $this->travel(1)->hours();
        $payload = ['status' => $milestone->value];
        if ($milestone === ShipmentStatus::HandedToLbc) {
            $payload['tracking_reference'] = ' LBC-COD-REFERENCE ';
        }
        $this->patch(route('administration.orders.shipment.status.update', $order), $payload)->assertSessionHasNoErrors();
        expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
        expect($order->shipment->getAttribute($milestone->timestampColumn()))->not->toBeNull();
        expect(Inventory::sole()->quantity)->toBe(8);
        $this->assertDatabaseCount('sales', 0);
    }

    expect($order->shipment)->tracking_reference->toBe('LBC-COD-REFERENCE')->eta_anchor_date->not->toBeNull();
    foreach (['carrier', 'preparation_days', 'transit_min_days', 'transit_max_days', 'eta_min_days', 'eta_max_days'] as $field) {
        expect($order->shipment->getAttribute($field))->toBe($shipmentSnapshot[$field]);
    }
    $deliveredSnapshot = $order->shipment->getAttributes();
    $this->patch(route('administration.orders.shipment.reference.update', $order), ['tracking_reference' => 'LBC-COD-CORRECTED'])
        ->assertSessionHasErrors(['tracking_reference' => 'The LBC tracking number is read-only once assigned.']);
    expect($order->refresh()->shipment->getAttributes())->toBe($deliveredSnapshot);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.payment.can_confirm_cod_collection', true)->where('order.allowed_status_transitions', [])
        ->where('order.payment.status.label', 'COD — Awaiting Collection'));
    $this->post(route('administration.orders.cod-collection.store', $order), ['cod_collection_confirmed' => true])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'LBC COD collection recorded. The order remains unpaid until remittance is reconciled.');

    expect($order->refresh())->status->toBe(OrderStatus::Processing)->payment_status->toBe(PaymentStatus::Pending);
    expect($order->shipment)->carrier_cod_collected_amount->toBe('250.00')->carrier_cod_collected_at->not->toBeNull();
    expect($order->sale)->toBeNull();
    $this->assertDatabaseCount('sales', 0);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.payment.can_confirm_cod_collection', false)
        ->where('order.payment.can_confirm_cod_remittance', true));
    $this->post(route('administration.orders.cod-remittance.store', $order), [
        'cod_remittance_confirmed' => true,
        'carrier_cod_remittance_reference' => 'LBC-REMITTANCE-REFERENCE',
    ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'LBC COD remittance reconciled. Order completed and sale recorded.');

    expect($order->refresh())->status->toBe(OrderStatus::Completed)->payment_status->toBe(PaymentStatus::Verified);
    expect($order->sale)->amount->toBe('100.00')->sale_date->toDateString()->toBe('2026-10-12');
    expect($order->total_amount)->toBe('250.00');
    expect($order->shipment->delivered_at->toDateTimeString())->toBe($deliveredSnapshot['delivered_at']);
    expect(Inventory::sole()->quantity)->toBe(8);
    expect($order->user->notifications()->pluck('data')->pluck('event')->sort()->values()->all())->toBe([
        'payment.verified', 'shipment.delivered', 'shipment.handed_to_lbc', 'shipment.in_transit',
        'shipment.out_for_delivery', 'shipment.preparing', 'shipment.ready_for_dispatch',
    ]);
    $this->post(route('administration.orders.cod-collection.store', $order), ['cod_collection_confirmed' => true])->assertSessionHasErrors('cod_collection_confirmed');
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('notifications', 7);
});

test('COD collection is rejected before delivery or after cancellation with no payment sales or notification changes', function (ShipmentStatus $milestone, OrderStatus $status) {
    $order = codWorkflowOrder($milestone, $status);

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();
    $before = $order->getAttributes();

    $this->actingAs($administrator)->post(route('administration.orders.cod-collection.store', $order), ['cod_collection_confirmed' => true])
        ->assertSessionHasErrors('cod_collection_confirmed');

    expect($order->refresh()->getAttributes())->toBe($before);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with([
    'pending' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Pending],
    'awaiting preparation' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Processing],
    'preparing' => [ShipmentStatus::Preparing, OrderStatus::Processing],
    'ready' => [ShipmentStatus::ReadyForDispatch, OrderStatus::Processing],
    'handoff' => [ShipmentStatus::HandedToLbc, OrderStatus::Processing],
    'transit' => [ShipmentStatus::InTransit, OrderStatus::Processing],
    'out for delivery' => [ShipmentStatus::OutForDelivery, OrderStatus::Processing],
    'cancelled' => [ShipmentStatus::Cancelled, OrderStatus::Cancelled],
]);

test('COD collection requires explicit accepted confirmation', function (mixed $confirmation) {
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    $payload = $confirmation === null ? [] : ['cod_collection_confirmed' => $confirmation];

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->post(route('administration.orders.cod-collection.store', $order), $payload)
        ->assertSessionHasErrors(['cod_collection_confirmed' => 'Confirm that you manually verified LBC collected the COD payment.']);

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with([null, false, 'no']);

test('COD collection cannot be performed by guests customers or bearer-only web callers', function (string $caller) {
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    if ($caller === 'customer') {
        $this->actingAs($order->user);
    } elseif ($caller === 'bearer') {
        $this->withToken($order->user->createToken('Phone')->plainTextToken);
    }

    $response = $this->post(route('administration.orders.cod-collection.store', $order), ['cod_collection_confirmed' => true]);
    $caller === 'customer' ? $response->assertForbidden() : $response->assertRedirectToRoute('login');

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    $this->assertDatabaseCount('sales', 0);
})->with(['guest', 'customer', 'bearer']);

test('ordinary payment decisions and order completion cannot bypass COD collection', function (string $operation) {
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator);

    if ($operation === 'completed') {
        $this->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])->assertSessionHasErrors('status');
    } else {
        $this->patch(route('administration.orders.payment-status.update', $order), ['payment_status' => $operation, 'manual_verification_confirmed' => true])
            ->assertSessionHasErrors('payment_status');
    }

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with(['verified', 'rejected', 'completed']);

test('sale recording failure rolls back COD remittance while preserving recorded collection and delivery', function () {
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    app(OrderProcessingService::class)->confirmCodCollection($order, true);
    $shipment = $order->shipment->getAttributes();
    Event::listen('eloquent.created: '.Sale::class, fn (): never => throw new RuntimeException('Sale failure.'));

    try {
        expect(fn () => app(OrderProcessingService::class)->confirmCodRemittance($order, true, 'LBC-REMITTANCE-REFERENCE'))->toThrow(RuntimeException::class, 'Sale failure.');
    } finally {
        Event::forget('eloquent.created: '.Sale::class);
    }

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    expect($order->shipment->getAttributes())->toBe($shipment);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('notifications', 0);
});

test('collection rechecks persisted shipment state and rejects stale duplicate confirmation', function () {
    $order = codWorkflowOrder(ShipmentStatus::OutForDelivery, OrderStatus::Processing);
    $stale = Order::with('shipment')->findOrFail($order->id);
    $processing = app(OrderProcessingService::class);

    expect(fn () => $processing->confirmCodCollection($stale, true))->toThrow(ValidationException::class);
    $processing->updateShipmentStatus($order, ShipmentStatus::Delivered);
    $processing->confirmCodCollection($stale, true);
    expect(fn () => $processing->confirmCodCollection($order, true))->toThrow(ValidationException::class);
    expect(fn () => $processing->updateShipmentStatus($stale, ShipmentStatus::Delivered))->toThrow(ValidationException::class);

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    $this->assertDatabaseCount('sales', 0);
    $processing->confirmCodRemittance($order, true, 'LBC-REMITTANCE-REFERENCE');
    $this->assertDatabaseCount('sales', 1);
    expect($order->refresh())->payment_status->toBe(PaymentStatus::Verified)->status->toBe(OrderStatus::Completed);
});

test('customer web mobile and chatbot present delivered unpaid COD accurately then show verified completion', function () {
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.status.value', 'processing')->where('order.status.label', 'Delivered — awaiting COD collection')
        ->where('order.payment.status.value', 'pending')->where('order.payment.proof_submitted', false)->where('order.can_cancel', false));
    $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->where('orders.data.0.status.label', 'Delivered — awaiting COD collection'));
    $this->withToken($order->user->createToken('Phone')->plainTextToken)->getJson('/api/v1/orders/'.$order->id)
        ->assertJsonPath('data.shipment.status.value', 'delivered')->assertJsonPath('data.payment.status.label', 'COD — Awaiting Collection');
    expect(app(ResolveOrderContext::class)->execute($order->user, 'COD payment for '.$order->reference)['orders'][0]['payment']['status']['label'])
        ->toBe('COD — Awaiting Collection');
    expect(app(DashboardService::class)->customer($order->user)['latest_order']['status']['label'])->toBe('Delivered — awaiting COD collection');

    app(OrderProcessingService::class)->confirmCodCollection($order, true);

    $this->getJson('/api/v1/orders/'.$order->id)->assertJsonPath('data.status.value', 'processing')
        ->assertJsonPath('data.payment.status.value', 'pending');
    app(OrderProcessingService::class)->confirmCodRemittance($order, true, 'LBC-REMITTANCE-REFERENCE');

    $this->getJson('/api/v1/orders/'.$order->id)->assertJsonPath('data.status.value', 'completed')
        ->assertJsonPath('data.status.label', 'Delivered / Completed')->assertJsonPath('data.payment.status.value', 'verified')
        ->assertJsonPath('data.payment.notice', 'Battlefront has manually reconciled the LBC COD remittance for your order.');
});

test('COD enters the payment attention queue and sales reporting only at the appropriate milestones', function () {
    $this->travelTo('2026-10-12 09:00:00');
    $order = codWorkflowOrder(ShipmentStatus::OutForDelivery, OrderStatus::Processing);
    $productId = $order->items->sole()->product_id;
    $months = [['start' => '2026-10-01', 'end_exclusive' => '2026-11-01']];
    $dashboard = app(DashboardService::class);
    $administrator = User::factory()->administrator()->create();
    $reports = app(SalesReportRepository::class);
    $monthly = app(MonthlySalesRepository::class);
    $processing = app(OrderProcessingService::class);
    expect($dashboard->administration($administrator)['needs_attention']['pending_payment_reviews'])->toBe(0);
    expect($monthly->aggregateProduct($productId, $months))->toBe([]);

    $processing->updateShipmentStatus($order, ShipmentStatus::Delivered);

    expect($dashboard->administration($administrator)['needs_attention'])->pending_payment_reviews->toBe(1)
        ->payment_orders->toHaveCount(1);
    expect((int) $reports->summary(['2026-10-01', '2026-11-01'], null, null)->total_sales)->toBe(0);
    expect($monthly->aggregateProduct($productId, $months))->toBe([]);

    $processing->confirmCodCollection($order, true);

    expect($dashboard->administration($administrator)['needs_attention']['pending_payment_reviews'])->toBe(1);
    expect((int) $reports->summary(['2026-10-01', '2026-11-01'], null, null)->total_sales)->toBe(0);
    expect($monthly->aggregateProduct($productId, $months))->toBe([]);
    $processing->confirmCodRemittance($order, true, 'LBC-REMITTANCE-REFERENCE');

    expect($dashboard->administration($administrator)['needs_attention']['pending_payment_reviews'])->toBe(0);
    $summary = $reports->summary(['2026-10-01', '2026-11-01'], null, null);
    expect((int) $summary->total_sales)->toBe(1);
    expect((float) $summary->total_revenue)->toBe(100.0);
    expect($dashboard->administration($administrator)['kpis'])->toMatchArray(['recorded_revenue' => '100.00', 'recorded_sales' => 1]);
    expect($monthly->aggregateProduct($productId, $months))->toBe([['start' => '2026-10-01', 'quantity_sold' => 2]]);
});

test('customers can cancel Pending COD orders through web and mobile and stock is restored only once', function (string $channel) {
    $order = codWorkflowOrder();
    if ($channel === 'web') {
        $this->actingAs($order->user)->post(route('orders.cancel.store', $order))->assertSessionHasNoErrors();
        $this->post(route('orders.cancel.store', $order))->assertSessionHasErrors('status');
    } else {
        $this->withToken($order->user->createToken('Phone')->plainTextToken)->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertUnprocessable();
    }

    expect($order->refresh())->status->toBe(OrderStatus::Cancelled)->payment_status->toBe(PaymentStatus::Pending);
    expect($order->shipment->status)->toBe(ShipmentStatus::Cancelled);
    expect(Inventory::sole()->quantity)->toBe(10);
    $this->assertDatabaseCount('sales', 0);
    expect($order->user->notifications()->count())->toBe(1);
})->with(['web', 'mobile']);

test('Processing COD cannot be cancelled by its customer and administrators cannot cancel after handoff', function (ShipmentStatus $milestone) {
    $order = codWorkflowOrder($milestone, OrderStatus::Processing);
    $this->actingAs($order->user)->post(route('orders.cancel.store', $order))->assertSessionHasErrors('status');
    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled']);

    $canCancel = in_array($milestone, [ShipmentStatus::AwaitingPreparation, ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch], true);
    expect($order->refresh()->status)->toBe($canCancel ? OrderStatus::Cancelled : OrderStatus::Processing);
    expect(Inventory::sole()->quantity)->toBe($canCancel ? 10 : 8);
    expect($order->payment_status)->toBe(PaymentStatus::Pending);
    $this->assertDatabaseCount('sales', 0);
})->with([ShipmentStatus::AwaitingPreparation, ShipmentStatus::Preparing, ShipmentStatus::ReadyForDispatch, ShipmentStatus::HandedToLbc, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered]);

test('COD collection does not accept prepaid orders or an internal unconfirmed action', function (bool $cod) {
    $order = $cod ? codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing) : Order::factory()->create(['status' => OrderStatus::Processing]);

    expect(fn () => app(OrderProcessingService::class)->confirmCodCollection($order, ! $cod))->toThrow(ValidationException::class);

    expect($order->refresh())->payment_status->toBe(PaymentStatus::Pending)->status->toBe(OrderStatus::Processing);
    $this->assertDatabaseCount('sales', 0);
})->with([true, false]);

test('chatbot rules distinguish proof-free COD delivery from pickup payment', function () {
    config(['services.paymongo.enabled' => true, 'services.paymongo.hosted_methods' => ['qrph', 'card', 'gcash', 'paymaya']]);
    $rules = app(ResolveChatbotRuleGuidance::class);

    expect($rules->execute('payment_methods')['guidance'])->toContain(
        'Pickup orders support Cash (Pay at Pickup), Debit/Credit Card (Pay at Store), Pay online.',
        'Delivery orders support Cash on Delivery (COD), Pay online.',
        'PayMongo Hosted Checkout for QR Ph, online card, GCash, Maya.',
    );
    expect($rules->execute('payment_proof')['guidance'])->toContain(
        'COD is delivery-only and requires no upload.',
        'Collection after delivery and full remittance reconciliation are separate; collection alone does not complete payment.',
        'New online payments require no receipt or screenshot upload.',
    );
});

test('COD completion cannot bypass collection when a shipment is missing even if payment was marked verified outside the workflow', function () {
    $order = Order::factory()->cashOnDelivery()->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Verified]);

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->patch(route('administration.orders.status.update', $order), ['status' => 'completed'])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
    expect(app(OrderProcessingService::class)->allowedOrderTransitions($order))->not->toContain(OrderStatus::Completed);
    $this->assertDatabaseCount('sales', 0);
});

test('COD sale date follows remittance confirmation rather than collection or delivery date', function () {
    $this->travelTo('2026-09-30 12:00:00');
    $order = codWorkflowOrder(ShipmentStatus::Delivered, OrderStatus::Processing);
    app(OrderProcessingService::class)->confirmCodCollection($order, true);
    $this->travelTo('2026-10-01 12:00:00');

    app(OrderProcessingService::class)->confirmCodRemittance($order, true, 'LBC-REMITTANCE-REFERENCE');

    expect($order->refresh()->shipment->delivered_at->toDateString())->toBe('2026-09-30');
    expect($order->sale->sale_date->toDateString())->toBe('2026-10-01');
});

test('COD shipment progression rejects pending orders and skipped repeated or backward milestones', function (ShipmentStatus $current, OrderStatus $orderStatus, ShipmentStatus $requested) {
    $order = codWorkflowOrder($current, $orderStatus);
    $before = $order->shipment->getAttributes();

    $administrator = User::factory()->administrator()->create();
    $order->assigned_handler_id = $administrator->id;
    $order->save();

    $this->actingAs($administrator)->patch(route('administration.orders.shipment.status.update', $order), ['status' => $requested->value])
        ->assertSessionHasErrors('status');

    expect($order->refresh()->shipment->getAttributes())->toBe($before);
    expect($order->payment_status)->toBe(PaymentStatus::Pending);
    expect(Inventory::sole()->quantity)->toBe(8);
    $this->assertDatabaseCount('sales', 0);
})->with([
    'order still pending' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Pending, ShipmentStatus::Preparing],
    'skip' => [ShipmentStatus::AwaitingPreparation, OrderStatus::Processing, ShipmentStatus::ReadyForDispatch],
    'repeat' => [ShipmentStatus::Preparing, OrderStatus::Processing, ShipmentStatus::Preparing],
    'backwards' => [ShipmentStatus::ReadyForDispatch, OrderStatus::Processing, ShipmentStatus::Preparing],
    'delivered repeat' => [ShipmentStatus::Delivered, OrderStatus::Processing, ShipmentStatus::Delivered],
]);
