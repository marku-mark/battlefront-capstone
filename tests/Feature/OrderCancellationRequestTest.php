<?php

use App\Actions\Order\ResubmitPaymentProof;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AdministrationWorkQueue;
use App\Services\Order\OrderProcessingService;
use App\Services\Reporting\RefundPeriodReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{Order, Inventory, User} */
function walletCancellationOrder(string $method = 'gcash', string $payment = 'pending', bool $shipment = true): array
{
    test()->fakeIsolatedStorage('local');
    Storage::disk('local')->put('payment-proofs/original.jpg', 'original proof');
    $manager = User::factory()->administrator()->create();
    $order = Order::factory()->withDeliverySnapshot(subtotal: '100.00')->create([
        'payment_method' => $method, 'payment_status' => $payment, 'payment_proof_path' => 'payment-proofs/original.jpg',
        'assigned_handler_id' => $manager->id,
    ]);
    $inventory = Inventory::factory()->create(['quantity' => 3]);
    OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2, 'price_at_time' => '50.00']);
    if ($shipment) {
        Shipment::factory()->for($order)->create();
    }

    return [$order->refresh(), $inventory, $manager];
}

/** @return array<string, mixed> */
function cancellationDecision(string $amount = '100.00', string $decision = 'approve'): array
{
    return ['decision' => $decision, 'amount_received' => $amount, 'decision_note' => 'Checked the actual wallet transaction.', 'account_checked' => true];
}

test('order presentation handles absent cancellation requests and unavailable request timestamps', function () {
    [$order] = walletCancellationOrder();
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.cancellation', null)->where('order.can_request_cancellation', true));
    app(OrderProcessingService::class)->requestCancellation($order->user, $order->id);
    DB::table('order_cancellation_requests')->where('order_id', $order->id)->update(['created_at' => null]);

    $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.cancellation.status.value', 'requested')->where('order.cancellation.requested_at', null));
});

test('web and mobile customers request cancellation without changing payment stock or order history', function (string $method, string $payment, bool $mobile) {
    [$order, $stock] = walletCancellationOrder($method, $payment);
    $original = $order->getAttributes();
    if ($mobile) {
        $this->withToken($order->user->createToken('phone')->plainTextToken)
            ->postJson(route('api.v1.orders.cancellation-requests.store', $order), ['customer_note' => 'Please cancel.'])
            ->assertJsonPath('data.cancellation.status.value', 'requested')->assertJsonPath('data.can_request_cancellation', false)
            ->assertJsonPath('data.status.value', 'pending')->assertJsonPath('data.payment.can_resubmit_proof', false);
    } else {
        $this->actingAs($order->user)->post(route('orders.cancellation-requests.store', $order), ['customer_note' => 'Please cancel.'])->assertRedirect(route('orders.show', $order));
        $this->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.cancellation.status.label', 'Cancellation requested'));
    }
    expect($order->fresh()->getAttributes())->toBe($original);
    expect($stock->fresh()->quantity)->toBe(3);
    $this->assertDatabaseHas('order_cancellation_requests', ['order_id' => $order->id, 'status' => 'pending']);
    $this->assertDatabaseCount('customer_refunds', 0);
    expect($order->user->notifications()->where('data->event', 'cancellation.requested')->count())->toBe(1);
})->with(['gcash', 'maya'])->with(['pending', 'verified', 'rejected'])->with([false, true]);

test('request holds block processing payment shipment changes and proof replacement under stale callers', function () {
    [$order, $stock, $manager] = walletCancellationOrder(payment: 'rejected', shipment: false);
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $this->actingAs($manager)->patch(route('administration.orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasErrors('status');
    expect(fn () => $processing->updatePaymentStatus($order, PaymentStatus::Verified, actor: $manager))->toThrow(ValidationException::class);
    expect(fn () => $processing->createManualShipment($order, 'lbc', null, null, null, true, $manager))->toThrow(ValidationException::class);
    expect(fn () => app(ResubmitPaymentProof::class)->execute($order->user, $order, 'payment-proofs/replacement.jpg'))->toThrow(ValidationException::class);
    expect(fn () => $processing->cancelForCustomer($order->user, $order->id))->toThrow(ValidationException::class);
    expect($stock->fresh()->quantity)->toBe(3);
    Storage::disk('local')->assertExists('payment-proofs/original.jpg');
    $queue = app(AdministrationWorkQueue::class);
    expect($queue->counts($manager))->toHaveKey('cancellation_review')->not->toHaveKey('order');
    expect($queue->paginate($manager)->items()[0]['href'])->toBe(route('administration.orders.show', $order));
});

test('rejection preserves payment and stock resumes normal handling and prevents another request', function () {
    [$order, $stock, $manager] = walletCancellationOrder(payment: 'verified');
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $this->actingAs($manager)->patch(route('administration.orders.cancellation-request.update', $order), cancellationDecision('280.00', 'reject'))->assertSessionHasNoErrors();
    $processing->updateStatus($order, OrderStatus::Processing, $manager);
    expect($order->fresh()->status)->toBe(OrderStatus::Processing);
    expect($stock->fresh()->quantity)->toBe(3);
    expect(fn () => $processing->requestCancellation($order->user, $order->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('customer_refunds', 0);
});

test('approval restores stock once and caps refunds at the original delivery inclusive total', function (string $received, ?string $expected) {
    [$order, $stock, $manager] = walletCancellationOrder();
    $original = $order->getAttributes();
    $order->items->first()->product->update(['price' => '9999.00']);
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $this->actingAs($manager)->patch(route('administration.orders.cancellation-request.update', $order), cancellationDecision($received))->assertSessionHasNoErrors();
    expect($order->fresh()->getAttributes())->toBe(array_replace($original, ['status' => 'cancelled']));
    expect($stock->fresh()->quantity)->toBe(5);
    expect($order->fresh()->shipment->status)->toBe(ShipmentStatus::Cancelled);
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('customer_returns', 0);
    if ($expected === null) {
        $this->assertDatabaseCount('customer_refunds', 0);
    } else {
        $this->assertDatabaseHas('customer_refunds', ['approved_amount' => $expected, 'paid_amount' => '0.00', 'customer_return_id' => null]);
    }
    $this->patch(route('administration.orders.cancellation-request.update', $order), cancellationDecision($received))->assertSessionHasErrors('status');
    expect($stock->fresh()->quantity)->toBe(5);
    Storage::disk('local')->assertExists('payment-proofs/original.jpg');
})->with(['not received' => ['0.00', null], 'partial' => ['50.25', '50.25'], 'full' => ['250.00', '250.00'], 'excess' => ['300.00', '250.00']]);

test('only an authorized active Manager or Owner can review cancellation', function (string $role) {
    [$order, $stock] = walletCancellationOrder();
    app(OrderProcessingService::class)->requestCancellation($order->user, $order->id);
    $actor = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id')]);
    $response = $this->actingAs($actor)->patch(route('administration.orders.cancellation-request.update', $order), cancellationDecision());
    if (in_array($role, ['manager', 'owner'], true)) {
        $response->assertSessionHasNoErrors();
        expect($stock->fresh()->quantity)->toBe(5);
    } else {
        $response->assertForbidden();
        expect($stock->fresh()->quantity)->toBe(3);
    }
})->with(['staff', 'it', 'manager', 'owner']);

test('review requires an actual account check and a valid verified amount', function (array $overrides, string $error) {
    [$order, $stock, $manager] = walletCancellationOrder();
    app(OrderProcessingService::class)->requestCancellation($order->user, $order->id);
    $this->actingAs($manager)->patch(route('administration.orders.cancellation-request.update', $order), array_replace(cancellationDecision(), $overrides))->assertSessionHasErrors($error);
    expect($stock->fresh()->quantity)->toBe(3);
    $this->assertDatabaseHas('order_cancellation_requests', ['status' => 'pending']);
})->with(['unchecked' => [['account_checked' => false], 'account_checked'], 'negative' => [['amount_received' => '-1'], 'amount_received'], 'precision' => [['amount_received' => '1.001'], 'amount_received'], 'missing reason' => [['decision_note' => ''], 'decision_note']]);

test('confirmed cancellation transfers are bounded idempotent and privately visible on web and mobile', function () {
    [$order, $stock, $manager] = walletCancellationOrder();
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $processing->reviewCancellation($order, $manager, true, '100.00', 'Verified receipt; cancellation approved.', true);
    $refund = DB::table('customer_refunds')->sole();
    $payload = ['amount' => '40.25', 'payment_method' => 'gcash', 'submission_key' => (string) Str::uuid(), 'external_reference' => 'GCASH-TRANSFER-01', 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('refund.jpg')];
    $this->actingAs($manager)->post(route('administration.customer-refunds.payments.store', $refund->id), $payload)->assertSessionHasNoErrors();
    $this->post(route('administration.customer-refunds.payments.store', $refund->id), $payload)->assertSessionHasNoErrors();
    $this->post(route('administration.customer-refunds.payments.store', $refund->id), [...$payload, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('external_reference');
    $this->post(route('administration.customer-refunds.payments.store', $refund->id), [...$payload, 'amount' => '40.26'])->assertSessionHasErrors('submission_key');
    $this->assertDatabaseCount('customer_refund_payments', 1);
    $this->assertDatabaseCount('customer_refund_payment_reviews', 1);
    $this->get(route('administration.orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.cancellation.can_record_payment', true)->where('order.cancellation.refund.unrecorded_amount', '59.75'));
    $proof = DB::table('administration_evidence')->sole();
    $payment = DB::table('customer_refund_payments')->sole();
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.cancellation.status.label', 'Refund processing')->where('order.cancellation.refund.confirmed_paid_amount', '40.25'));
    $url = route('orders.refund-payments.proof.show', [$order->id, $payment->id, $proof->id]);
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(User::factory()->customer()->create())->get($url)->assertNotFound();
    $this->withToken($order->user->createToken('phone')->plainTextToken)->getJson(route('api.v1.orders.refund-payments.proof.show', [$order->id, $payment->id, $proof->id]))->assertOk();
    $this->actingAs($manager)->post(route('administration.customer-refunds.payments.store', $refund->id), [...$payload, 'amount' => '59.75', 'submission_key' => (string) Str::uuid(), 'external_reference' => 'GCASH-TRANSFER-02'])->assertSessionHasNoErrors();
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.cancellation.status.label', 'Refund completed'));
    expect($order->user->notifications()->where('data->event', 'refund.completed')->count())->toBe(1);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);
    expect($stock->fresh()->quantity)->toBe(5);
    $report = app(RefundPeriodReport::class)->generate(['from' => today()->toDateString(), 'to' => today()->toDateString(), 'product_id' => null, 'category_id' => null], '0.00');
    expect($report['refund_payments'])->toBe('0.00');
});

test('cancellation refunds require their approver actual transfer proof wallet reference and bounded amount', function (string $failure, string $error) {
    [$order, $stock, $manager] = walletCancellationOrder();
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $processing->reviewCancellation($order, $manager, true, '100.00', 'Checked actual transaction.', true);
    $refund = DB::table('customer_refunds')->sole();
    $actor = $failure === 'other manager' ? User::factory()->administrator()->create() : $manager;
    $payload = ['amount' => '100.00', 'payment_method' => 'maya', 'external_reference' => 'OUTGOING-001', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('refund.jpg')];
    $payload = match ($failure) {
        'confirmation' => [...$payload, 'payment_confirmed' => false],
        'proof' => [...$payload, 'proof' => null],
        'reference' => [...$payload, 'external_reference' => ''],
        'cash' => [...$payload, 'payment_method' => 'cash', 'proof' => null],
        'overpaid' => [...$payload, 'amount' => '100.01'],
        default => $payload,
    };
    $response = $this->actingAs($actor)->post(route('administration.customer-refunds.payments.store', $refund->id), $payload);
    if ($failure === 'other manager') {
        $response->assertForbidden();
    } else {
        $response->assertSessionHasErrors($error);
    }
    $this->assertDatabaseCount('customer_refund_payments', 0);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with(['confirmation' => ['confirmation', 'payment_confirmed'], 'proof' => ['proof', 'proof'], 'reference' => ['reference', 'external_reference'], 'cash' => ['cash', 'payment_method'], 'overpaid' => ['overpaid', 'amount'], 'other manager' => ['other manager', '']]);

test('request endpoints require customer authentication and conceal foreign orders', function () {
    [$order] = walletCancellationOrder();
    $this->post(route('orders.cancellation-requests.store', $order))->assertRedirect(route('login'));
    $this->postJson(route('api.v1.orders.cancellation-requests.store', $order))->assertUnauthorized();
    $this->actingAs(User::factory()->administrator()->create())->post(route('orders.cancellation-requests.store', $order))->assertForbidden();
    $other = User::factory()->customer()->create();
    $this->actingAs($other)->post(route('orders.cancellation-requests.store', $order))->assertNotFound();
    $this->withToken($other->createToken('phone')->plainTextToken)->postJson(route('api.v1.orders.cancellation-requests.store', $order))->assertNotFound();
    $this->assertDatabaseCount('order_cancellation_requests', 0);
});

test('ineligible wallet cancellation requests cannot hold or restore stock', function (string $failure) {
    [$order, $stock, $manager] = walletCancellationOrder();
    match ($failure) {
        'processing' => $order->update(['status' => OrderStatus::Processing]),
        'completed' => $order->update(['status' => OrderStatus::Completed]),
        'cancelled' => $order->update(['status' => OrderStatus::Cancelled]),
        'preparing' => $order->shipment->update(['status' => ShipmentStatus::Preparing]),
        'handoff' => $order->shipment->update(['status' => ShipmentStatus::HandedToLbc]),
        'no proof' => $order->update(['payment_proof_path' => null]),
        'duplicate' => app(OrderProcessingService::class)->requestCancellation($order->user, $order->id),
    };
    $this->actingAs($order->user)->post(route('orders.cancellation-requests.store', $order))->assertSessionHasErrors('status');
    expect($stock->fresh()->quantity)->toBe(3);
    $this->assertDatabaseCount('order_cancellation_requests', $failure === 'duplicate' ? 1 : 0);
})->with(['processing', 'completed', 'cancelled', 'preparing', 'handoff', 'no proof', 'duplicate']);

test('cancellation approval rolls back request inventory shipment and notifications on failure', function () {
    [$order, $stock, $manager] = walletCancellationOrder();
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $notificationCount = DB::table('notifications')->count();
    Event::listen('eloquent.updated: '.Order::class, fn (): never => throw new RuntimeException('Cancellation approval failed.'));
    try {
        expect(fn () => $processing->reviewCancellation($order, $manager, true, '100.00', 'Actual transaction checked.', true))
            ->toThrow(RuntimeException::class, 'Cancellation approval failed.');
    } finally {
        Event::forget('eloquent.updated: '.Order::class);
    }
    expect($stock->fresh()->quantity)->toBe(3);
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    expect($order->fresh()->shipment->status)->toBe(ShipmentStatus::AwaitingPreparation);
    $this->assertDatabaseHas('order_cancellation_requests', ['order_id' => $order->id, 'status' => 'pending', 'reviewed_by' => null]);
    $this->assertDatabaseCount('customer_refunds', 0);
    $this->assertDatabaseCount('notifications', $notificationCount);
});

test('cancelled refund completion cannot be inferred from a recorded amount without confirmed reviews', function () {
    [$order, $stock, $manager] = walletCancellationOrder();
    $processing = app(OrderProcessingService::class);
    $processing->requestCancellation($order->user, $order->id);
    $processing->reviewCancellation($order, $manager, true, '100.00', 'Actual transaction checked.', true);
    $refund = DB::table('customer_refunds')->sole();
    DB::table('customer_refunds')->where('id', $refund->id)->update(['paid_amount' => '100.00']);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.cancellation.status.value', 'refund_processing')->where('order.cancellation.refund.confirmed_paid_amount', '0.00'));
    expect($order->user->notifications()->where('data->event', 'refund.completed')->exists())->toBeFalse();
});

test('inactive reviewers and missing approval permissions cannot decide cancellation', function (bool $inactive) {
    [$order, $stock, $manager] = walletCancellationOrder();
    app(OrderProcessingService::class)->requestCancellation($order->user, $order->id);
    if ($inactive) {
        $manager->forceFill(['is_active' => false])->save();
    } else {
        $permissionId = DB::table('administration_permissions')->where('code', 'returns.approve')->value('id');
        DB::table('administration_role_permissions')->where('administration_role_id', $manager->administration_role_id)->where('administration_permission_id', $permissionId)->delete();
    }
    $response = $this->actingAs($manager)->patch(route('administration.orders.cancellation-request.update', $order), cancellationDecision());
    $response->assertForbidden();
    expect($stock->fresh()->quantity)->toBe(3);
    $this->assertDatabaseHas('order_cancellation_requests', ['order_id' => $order->id, 'status' => 'pending']);
})->with([true, false]);
