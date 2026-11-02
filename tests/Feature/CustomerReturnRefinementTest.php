<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Actions\Product\DeleteProduct;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use App\Services\AdministrationWorkQueue;
use App\Services\CustomerReturnEligibility;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function refinementEmployee(string $role = 'staff'): User
{
    return User::factory()->administrator()->create(['administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id'))]);
}

/** @return array{Order, OrderItem, Inventory} */
function refinementPurchase(bool $delivery = false, ?string $receipt = null): array
{
    Storage::fake('local');
    $factory = $delivery ? Order::factory()->withDeliverySnapshot() : Order::factory();
    $order = $factory->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    if ($delivery) {
        Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered, 'delivered_at' => $receipt]);
    } elseif ($receipt !== null) {
        $order->pickup_handed_over_at = $receipt;
        $order->save();
    }
    $stock = Inventory::factory()->create(['quantity' => 0, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 3, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '300.00', 'sale_date' => today()]);

    return [$order->refresh(), $item, $stock];
}

/** @return array{int, Order, OrderItem, Inventory, User, User} */
function refinementReceived(string $reason = 'changed_mind'): array
{
    [$order, $item, $stock] = refinementPurchase();
    $receiver = refinementEmployee();
    $id = app(HandleCustomerReturn::class)->receive($receiver, $item->id, 1, 'opened', 'Item returned for inspection', $reason, photos: [UploadedFile::fake()->image('receipt.jpg')]);

    return [$id, $order, $item, $stock, $receiver, refinementEmployee('manager')];
}

test('acceptance requires an explicit resolution and valid monetary outcome', function (array $resolution, string $amount, string $field) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();

    try {
        app(HandleCustomerReturn::class)->decide($id, $reviewer, true, 'return_to_customer', $amount, 'Reviewed actual item', resolution: $resolution);
        $this->fail('Invalid customer resolution was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseHas('customer_returns', ['id' => $id, 'status' => 'pending']);
    $this->assertDatabaseCount('customer_return_resolutions', 0);
    $this->assertDatabaseCount('customer_refunds', 0);
    expect($stock->refresh()->quarantined_quantity)->toBe(1);
})->with([
    'missing' => [[], '0.00', 'type'],
    'zero monetary' => [['type' => 'refund'], '0.00', 'approved_amount'],
    'negative monetary' => [['type' => 'refund'], '-1.00', 'approved_amount'],
    'excessive monetary' => [['type' => 'refund'], '100.01', 'approved_amount'],
    'invalid repair summary' => [['type' => 'repair', 'summary' => 'bad'], '0.00', 'summary'],
    'paid repair' => [['type' => 'repair', 'summary' => 'Repair and original item handback'], '10.00', 'approved_amount'],
]);

test('positive and partial refund approvals record a resolution without recording a payment', function (string $amount) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    Storage::fake('local');
    app(AdministrationEvidenceService::class)->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('inspection.jpg')]);
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), [
        'decision' => 'accept', 'resolution_type' => 'refund', 'disposition' => 'write_off',
        'approved_amount' => $amount, 'reason' => 'Verified fault during inspection', 'resolution_summary' => 'Customer agreed to this refund amount',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customer_return_resolutions', ['customer_return_id' => $id, 'type' => 'refund', 'completed_at' => null]);
    $this->assertDatabaseHas('customer_refunds', ['customer_return_id' => $id, 'approved_amount' => $amount, 'paid_amount' => '0.00']);
    $this->assertDatabaseCount('customer_refund_payments', 0);
    expect($order->sale->amount)->toBe('300.00');
})->with(['100.00', '35.25']);

test('repair acceptance stays pending and quarantined until exactly one authorized handback', function () {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived('defective');
    $action = app(HandleCustomerReturn::class);
    $action->decide($id, $reviewer, true, 'return_to_customer', '0.00', 'Repair agreed with customer', resolution: ['type' => 'repair', 'summary' => 'Repair and return the original item', 'reference' => 'PRIVATE-REPAIR']);
    expect($stock->refresh()->quarantined_quantity)->toBe(1);
    expect($stock->quantity)->toBe(0);
    $this->assertDatabaseCount('customer_refunds', 0);
    expect(app(AdministrationWorkQueue::class)->paginate($receiver, ['kind' => 'customer_resolution'])->total())->toBe(1);
    $this->actingAs($receiver)->post(route('administration.customer-returns.resolution-completion', $id), ['completion_notes' => 'Repair tested and handed over'])->assertSessionHasErrors('handover_confirmed');
    $this->post(route('administration.customer-returns.resolution-completion', $id), ['completion_notes' => 'Repair tested and handed over', 'handover_confirmed' => true])->assertSessionHasNoErrors();
    $this->post(route('administration.customer-returns.resolution-completion', $id), ['completion_notes' => 'Repeated handover attempt', 'handover_confirmed' => true])->assertSessionHasErrors('resolution');
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
    expect($stock->quantity)->toBe(0);
    expect(DB::table('inventory_movements')->where('kind', 'customer_resolution_handed_back')->count())->toBe(1);
    expect(app(CustomerReturnEligibility::class)->remaining($item))->toBe(2);
    expect(app(AdministrationWorkQueue::class)->paginate($receiver, ['kind' => 'customer_resolution'])->total())->toBe(0);
});

test('legacy acceptance without a resolution displays unknown while financial history stays untouched', function () {
    [$order, $item] = refinementPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'changed_mind']);
    $id = app(HandleCustomerReturnRequest::class)->receive(refinementEmployee(), $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    DB::table('customer_returns')->where('id', $id)->update(['status' => 'accepted']);
    $before = DB::table('customer_returns')->where('id', $id)->first();
    $this->withToken($order->user->createToken('Phone')->plainTextToken)->getJson(route('api.v1.orders.return-requests.show', [$order, $record]))
        ->assertJsonPath('data.status.value', 'approved')->assertJsonPath('data.resolution.status', 'unknown')->assertJsonPath('data.refund', null);
    expect(DB::table('customer_returns')->where('id', $id)->first())->toEqual($before);
    $this->assertDatabaseCount('customer_return_resolutions', 0);
});

test('pending manual replacement prevents product archival even after the original stock is written off', function () {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived('defective');
    Storage::fake('local');
    app(AdministrationEvidenceService::class)->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('fault.jpg')]);
    $action = app(HandleCustomerReturn::class);
    $action->decide($id, $reviewer, true, 'write_off', '0.00', 'Faulty item written off', resolution: ['type' => 'replacement', 'summary' => 'Manually arrange an agreed replacement']);
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
    $this->actingAs($reviewer);
    expect(fn () => app(DeleteProduct::class)->execute($item->product))->toThrow(ValidationException::class);
    $action->completeResolution($id, $receiver, 'Replacement handed over under reference REPL-1');
    app(DeleteProduct::class)->execute($item->product);
    expect($item->product->refresh()->trashed())->toBeTrue();
});

test('defective restock requires findings and accessible evidence before returning stock to sale', function () {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived('defective');
    Storage::fake('local');
    $resolution = ['type' => 'refund', 'summary' => 'Partial refund agreed after inspection', 'inspection_notes' => 'Device retested and confirmed fit for sale'];
    $action = app(HandleCustomerReturn::class);
    expect(fn () => $action->decide($id, $reviewer, true, 'restock', '50.00', 'Customer accepted partial refund', inspectionConfirmed: true, resolution: $resolution))->toThrow(ValidationException::class);
    app(AdministrationEvidenceService::class)->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('tested.jpg')]);
    $path = DB::table('administration_evidence')->where('subject_id', $id)->where('original_name', 'tested.jpg')->value('path');
    Storage::disk('local')->delete($path);
    expect(fn () => $action->decide($id, $reviewer, true, 'restock', '50.00', 'Customer accepted partial refund', inspectionConfirmed: true, resolution: $resolution))->toThrow(ValidationException::class);
    app(AdministrationEvidenceService::class)->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('retested.jpg')]);
    expect(fn () => $action->decide($id, $reviewer, true, 'restock', '50.00', 'Customer accepted partial refund', resolution: $resolution))->toThrow(ValidationException::class);
    $action->decide($id, $reviewer, true, 'restock', '50.00', 'Customer accepted partial refund', inspectionConfirmed: true, resolution: $resolution);
    expect($stock->refresh()->quantity)->toBe(1);
    expect($stock->quarantined_quantity)->toBe(0);
    expect(DB::table('customer_returns')->where('id', $id)->value('decision_reason'))->toContain('Device retested');
});

test('approved discretionary window uses exact original receipt deadline and inclusive boundary', function (bool $delivery, string $now, bool $allowed) {
    config(['battlefront.returns.window_approved' => true, 'battlefront.returns.window_days' => 7]);
    $this->travelTo(new DateTimeImmutable($now));
    [$order, $item, $stock] = refinementPurchase($delivery, '2026-10-23 06:30:00');
    $this->withToken($order->user->createToken('Phone')->plainTextToken);
    $detail = $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.return_eligibility.deadline_at', '2026-10-30T06:30:00+00:00');
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.return_eligibility', $detail->json('data.return_eligibility')));
    $response = $this->postJson(route('api.v1.orders.return-requests.store', $order), ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'changed_mind']);
    if ($allowed) {
        $response->assertCreated()->assertJsonPath('data.eligibility_path', 'discretionary');
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $this->assertDatabaseCount('customer_return_requests', 0);
    }
    expect($stock->refresh()->quarantined_quantity)->toBe(0);
})->with([
    [false, '2026-10-30 06:29:59 UTC', true], [false, '2026-10-30 06:30:00 UTC', true], [false, '2026-10-30 06:30:01 UTC', false],
    [true, '2026-10-30 06:29:59 UTC', true], [true, '2026-10-30 06:30:00 UTC', true], [true, '2026-10-30 06:30:01 UTC', false],
]);

test('manual claims and missing receipt timestamps do not fabricate or expire eligibility', function (string $reason, ?string $receipt) {
    config(['battlefront.returns.window_approved' => true]);
    $this->travelTo(new DateTimeImmutable('2026-12-01 UTC'));
    [$order, $item] = refinementPurchase(false, $receipt);
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => $reason, 'reason' => 'Customer requests manual assessment']);
    expect($record->eligibility_path)->toBe('manual_review');
    if ($receipt === null) {
        expect($record->eligibility_receipt_at)->toBeNull();
        expect($record->eligibility_deadline_at)->toBeNull();
    }
})->with([['defective', '2026-10-23 06:30:00'], ['other', '2026-10-23 06:30:00'], ['wrong_item', '2026-10-23 06:30:00'], ['changed_mind', null]]);

test('proposed policy is disabled by default and configurable without rewriting pending requests', function () {
    expect(config('battlefront.returns.window_approved'))->toBeFalse();
    $this->travelTo(new DateTimeImmutable('2026-12-01 UTC'));
    [$order, $item] = refinementPurchase(false, '2026-10-23 06:30:00');
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'changed_mind']);
    $snapshot = $record->eligibility_deadline_at->toIso8601String();
    config(['battlefront.returns.window_days' => 14, 'battlefront.returns.window_approved' => true]);
    expect(app(CustomerReturnEligibility::class)->window($order)['deadline_at'])->toBe('2026-11-06T06:30:00+00:00');
    app(HandleCustomerReturnRequest::class)->receive(refinementEmployee(), $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    expect($record->refresh()->eligibility_deadline_at->toIso8601String())->toBe($snapshot);
});

test('a timely request remains receivable after expiry and later units keep the original deadline', function () {
    config(['battlefront.returns.window_approved' => true]);
    $this->travelTo(new DateTimeImmutable('2026-10-24 UTC'));
    [$order, $item] = refinementPurchase(false, '2026-10-23 06:30:00');
    $action = app(HandleCustomerReturnRequest::class);
    $record = $action->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'changed_mind']);
    $this->travelTo(new DateTimeImmutable('2026-11-01 UTC'));
    $id = $action->receive(refinementEmployee(), $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    app(HandleCustomerReturn::class)->decide($id, refinementEmployee('owner'), true, 'return_to_customer', '0.00', 'Manual repair agreed', resolution: ['type' => 'repair', 'summary' => 'Repair and return the original item']);
    expect(app(CustomerReturnEligibility::class)->remaining($item))->toBe(2);
    expect(fn () => $action->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'changed_mind']))->toThrow(ValidationException::class);
});

test('Cash Card and wallet pickup record immutable actual handover and only one sale', function (string $method) {
    $this->travelTo(new DateTimeImmutable('2026-10-23 06:30:00 UTC'));
    $staff = refinementEmployee();
    $order = Order::factory()->create(['status' => OrderStatus::Processing, 'payment_method' => $method, 'payment_status' => $method === 'maya' ? PaymentStatus::Verified : PaymentStatus::Pending, 'assigned_handler_id' => $staff->id]);
    OrderItem::factory()->for($order)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $service = app(OrderProcessingService::class);
    if ($method === 'maya') {
        expect(fn () => $service->updateStatus($order, OrderStatus::Completed, actor: $staff))->toThrow(ValidationException::class);
        $service->updateStatus($order, OrderStatus::Completed, actor: $staff, productsHandedOver: true);
    } else {
        $service->confirmPickup($order, true, true, actor: $staff);
    }
    expect($order->refresh()->pickup_handed_over_at->toIso8601String())->toBe('2026-10-23T06:30:00+00:00');
    $this->assertDatabaseCount('sales', 1);
    $order->pickup_handed_over_at = now()->addDay();
    expect(fn () => $order->save())->toThrow(DomainException::class);
})->with(['cash', 'card_at_store', 'maya']);

test('return evidence supports multiple Staff inspectors while reviewers have read-only access', function (bool $accept) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    Storage::fake('local');
    $service = app(AdministrationEvidenceService::class);
    $service->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('receiver.jpg')]);
    $this->actingAs($reviewer)->post(route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $id]), ['files' => [UploadedFile::fake()->image('reviewer.jpg')]])->assertForbidden();
    $service->store(refinementEmployee(), 'customer_return', $id, [UploadedFile::fake()->image('second-inspector.jpg')]);
    app(HandleCustomerReturn::class)->decide($id, $reviewer, $accept, $accept ? 'return_to_customer' : '', '0.00', 'Independent review completed', resolution: $accept ? ['type' => 'repair', 'summary' => 'Manual repair agreed with customer'] : []);
    $this->actingAs($receiver)->post(route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $id]), ['files' => [UploadedFile::fake()->image('late.jpg')]])->assertSessionHasErrors('files');
    $this->assertDatabaseCount('administration_evidence', 3);
    $file = DB::table('administration_evidence')->where('original_name', 'receiver.jpg')->first();
    $this->get(route('administration.evidence.show', $file->id))->assertOk();
})->with([true, false]);

test('only the payment recorder adds proof before independent final verification or voiding', function (string $finalDecision) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    Storage::fake('local');
    $evidence = app(AdministrationEvidenceService::class);
    $evidence->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('case.jpg')]);
    $action = app(HandleCustomerReturn::class);
    $action->decide($id, $reviewer, true, 'write_off', '50.00', 'Refund approved after inspection', resolution: ['type' => 'refund', 'summary' => 'Partial refund agreed with the customer']);
    $refund = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $this->recordLegacyRefundPayment($refund, $reviewer, '50.00', 'cash', 'REFUND-1');
    $verifier = refinementEmployee('owner');
    $payment = DB::table('customer_refund_payments')->value('id');
    $url = route('administration.evidence.store', ['subjectType' => 'refund_payment', 'subjectId' => $payment]);
    $this->actingAs($receiver)->post($url, ['files' => [UploadedFile::fake()->image('foreign.jpg')]])->assertForbidden();
    $this->actingAs($reviewer)->post($url, ['files' => [UploadedFile::fake()->image('proof.jpg')]])->assertSessionHasNoErrors();
    expect(fn () => $action->reviewPayment($payment, $reviewer, 'verified', 'Own payment cannot be verified'))->toThrow(ValidationException::class);
    $action->reviewPayment($payment, $verifier, 'disputed', 'Need supplementary proof');
    $this->post($url, ['files' => [UploadedFile::fake()->image('supplement.jpg')]])->assertSessionHasNoErrors();
    $action->reviewPayment($payment, $verifier, $finalDecision, 'Independent review completed');
    $this->post($url, ['files' => [UploadedFile::fake()->image('closed.jpg')]])->assertSessionHasErrors('files');
    expect(DB::table('administration_evidence')->where('subject_type', 'refund_payment')->count())->toBe(2);
})->with(['verified', 'voided']);

test('saved customer resolution summaries stay hidden with references and review evidence', function () {
    [$order, $item] = refinementPurchase();
    $request = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective']);
    $staff = refinementEmployee();
    $id = app(HandleCustomerReturnRequest::class)->receive($staff, $request->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    app(HandleCustomerReturn::class)->decide($id, refinementEmployee('manager'), true, 'return_to_customer', '0.00', 'PRIVATE INSPECTION NOTES', resolution: ['type' => 'repair', 'summary' => 'Repair and return original hardware', 'reference' => 'PRIVATE REFERENCE', 'public_feedback' => 'Repair approved after inspection']);
    $this->withToken($order->user->createToken('Phone')->plainTextToken)->getJson(route('api.v1.orders.return-requests.show', [$order, $request]))
        ->assertJsonPath('data.return_decision', 'accepted')->assertJsonPath('data.resolution.status', 'pending')->assertJsonPath('data.resolution.summary', null)->assertDontSee('Repair and return original hardware')
        ->assertJsonPath('data.feedback', 'Repair approved after inspection')->assertDontSee('PRIVATE');
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.return_requests.0.resolution.summary', null));
    app(HandleCustomerReturn::class)->completeResolution($id, $staff, 'PRIVATE HANDOVER RECEIPT', true);
    $this->getJson(route('api.v1.orders.return-requests.show', [$order, $request]))->assertJsonPath('data.resolution.status', 'completed')->assertDontSee('PRIVATE');
});

test('different orders of the same product retain independent receipt windows and unit balances', function () {
    config(['battlefront.returns.window_approved' => true]);
    $this->travelTo(new DateTimeImmutable('2026-10-30 06:30:00 UTC'));
    [$old, $oldItem] = refinementPurchase(false, '2026-10-20 06:30:00');
    $recent = Order::factory()->for($old->user)->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $recent->pickup_handed_over_at = '2026-10-29 06:30:00';
    $recent->save();
    $recentItem = OrderItem::factory()->for($recent)->for($oldItem->product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $recent->sale()->create(['amount' => '200.00', 'sale_date' => today()]);
    $action = app(HandleCustomerReturnRequest::class);
    expect(fn () => $action->submit($old->user, $old->id, ['order_item_id' => $oldItem->id, 'quantity' => 1, 'reason_code' => 'changed_mind']))->toThrow(ValidationException::class);
    $action->submit($recent->user, $recent->id, ['order_item_id' => $recentItem->id, 'quantity' => 2, 'reason_code' => 'changed_mind']);
    expect(app(CustomerReturnEligibility::class)->remaining($oldItem))->toBe(3);
    expect(app(CustomerReturnEligibility::class)->remaining($recentItem))->toBe(0);
});

test('historical manager or owner payments retain their independent review rules', function (string $role) {
    [$id, $order, $item, $stock, $receiver, $approver] = refinementReceived();
    Storage::fake('local');
    app(AdministrationEvidenceService::class)->store($receiver, 'customer_return', $id, [UploadedFile::fake()->image('fault.jpg')]);
    $action = app(HandleCustomerReturn::class);
    $recorder = refinementEmployee($role);
    $action->decide($id, $recorder, true, 'write_off', '100.00', 'Refund approved after inspection', resolution: ['type' => 'refund']);
    expect($recorder->can('returns.record_payment'))->toBeTrue();
    expect($receiver->can('returns.approve'))->toBeFalse();
    expect($receiver->can('returns.reconcile'))->toBeFalse();
    $refund = DB::table('customer_refunds')->value('id');
    $this->recordLegacyRefundPayment($refund, $recorder, '100.00', 'cash', 'MANUAL-ACCOUNTABILITY');
    $payment = DB::table('customer_refund_payments')->value('id');
    expect(fn () => $action->reviewPayment($payment, $recorder, 'verified', 'Attempted self-verification'))->toThrow(ValidationException::class);
    $action->reviewPayment($payment, refinementEmployee($role === 'owner' ? 'manager' : 'owner'), 'verified', 'Independent manual cross-check');
    $this->assertDatabaseCount('customer_refund_payment_reviews', 1);
    $this->assertDatabaseHas('customer_refund_payment_reviews', ['customer_refund_payment_id' => $payment, 'decision' => 'verified']);
})->with(['manager', 'owner']);

test('legacy IT assignments and customers cannot confirm nonmonetary fulfillment', function (string $role) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    app(HandleCustomerReturn::class)->decide($id, $reviewer, true, 'return_to_customer', '0.00', 'Repair agreed with customer', resolution: ['type' => 'repair', 'summary' => 'Repair and hand back original item']);
    $actor = $role === 'customer' ? $order->user : refinementEmployee('it');
    $this->actingAs($actor)->post(route('administration.customer-returns.resolution-completion', $id), ['completion_notes' => 'Attempted unauthorized fulfillment', 'handover_confirmed' => true])->assertForbidden();
    $this->assertDatabaseHas('customer_return_resolutions', ['customer_return_id' => $id, 'completed_at' => null]);
})->with(['it', 'customer']);

test('partial monetary refunds can be approved without a customer resolution summary', function () {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    $payload = ['decision' => 'accept', 'resolution_type' => 'refund', 'approved_amount' => '35.25',
        'disposition' => 'restock', 'inspection_confirmed' => true, 'inspection_notes' => 'Unopened spare part checked fit for sale', 'reason' => 'Partial amount agreed'];
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customer_refunds', ['customer_return_id' => $id, 'approved_amount' => '35.25']);
});

test('calculated refund uses purchased unit price and return quantity without adding shipping', function () {
    $order = Order::factory()->withDeliverySnapshot()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified,
        'product_subtotal' => '4499.85', 'delivery_base_fee' => '35.00', 'delivery_fee' => '35.00', 'total_amount' => '4534.85']);
    $stock = Inventory::factory()->create(['quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 3, 'price_at_time' => '1499.95']);
    $order->sale()->create(['amount' => '4534.85', 'sale_date' => today()]);
    $staff = refinementEmployee();
    $approver = refinementEmployee('manager');
    $id = app(HandleCustomerReturn::class)->receive($staff, $item->id, 2, 'opened', 'Wrong item delivered', 'wrong_item', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    $this->actingAs($approver)->get(route('administration.customer-returns.index', ['case' => $id]))
        ->assertInertia(fn (Assert $page) => $page->where('returns.data.0.refundable_item_value', '2999.90'));
    $payload = ['decision' => 'accept', 'resolution_type' => 'refund', 'approved_amount' => '3034.90',
        'disposition' => 'restock', 'inspection_confirmed' => true, 'inspection_notes' => 'Sealed items tested fit for sale', 'reason' => 'Wrong items returned'];
    $this->post(route('administration.customer-returns.decide', $id), $payload)->assertSessionHasErrors('approved_amount');
    $this->post(route('administration.customer-returns.decide', $id), [...$payload, 'approved_amount' => '2999.90'])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customer_refunds', ['customer_return_id' => $id, 'approved_amount' => '2999.90', 'paid_amount' => '0.00']);
    expect($order->refresh()->delivery_fee)->toBe('35.00');
});

test('only the original approver can record a new refund payment', function (string $role) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    app(HandleCustomerReturn::class)->decide($id, $reviewer, true, 'restock', '100.00', 'Full refund approved', inspectionConfirmed: true,
        resolution: ['type' => 'refund', 'inspection_notes' => 'Hardware checked and fit for sale']);
    $refund = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $actor = $role === 'customer' ? $order->user : refinementEmployee($role);
    $this->actingAs($actor)->post(route('administration.customer-refunds.payments.store', $refund), [
        'amount' => '100.00', 'payment_method' => 'cash', 'external_reference' => 'FOREIGN-PAYMENT',
    ])->assertForbidden();
    $this->assertDatabaseCount('customer_refund_payments', 0);
    $this->assertDatabaseHas('customer_refunds', ['id' => $refund, 'paid_amount' => '0.00']);
    expect(app(AdministrationWorkQueue::class)->paginate($actor, ['kind' => 'refund'])->total())->toBe(0);
})->with(['staff', 'manager', 'owner', 'it', 'customer']);

test('recorded cent refund cannot be recorded twice and only reopens after disputed payment is voided', function () {
    [$id, $order, $item, $stock, $receiver, $approver] = refinementReceived();
    $action = app(HandleCustomerReturn::class);
    $action->decide($id, $approver, true, 'restock', '0.01', 'Partial refund agreed', inspectionConfirmed: true,
        resolution: ['type' => 'refund', 'summary' => 'Customer agreed to a one-cent adjustment', 'inspection_notes' => 'Hardware inspected fit for sale']);
    $refund = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $this->recordLegacyRefundPayment($refund, $approver, '0.01', 'gcash', 'CENT-1');
    $this->actingAs($approver);
    $this->get(route('administration.customer-returns.index', ['case' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.refund_balance', '0.01')->where('returns.data.0.unrecorded_refund_amount', '0.00')
        ->where('returns.data.0.can_record_payment', false)->where('returns.data.0.refund_fully_verified', false));
    $this->post(route('administration.customer-refunds.payments.store', $refund), [
        'amount' => '0.01', 'payment_method' => 'gcash', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('proof.jpg'),
    ])->assertSessionHasErrors('amount');
    expect(app(AdministrationWorkQueue::class)->paginate($approver, ['kind' => 'refund'])->total())->toBe(0);
    $payment = DB::table('customer_refund_payments')->where('customer_refund_id', $refund)->value('id');
    $verifier = refinementEmployee('owner');
    $action->reviewPayment($payment, $verifier, 'disputed', 'Transfer could not be located');
    $this->get(route('administration.customer-returns.index', ['case' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.unrecorded_refund_amount', '0.00')->where('returns.data.0.can_record_payment', false));
    $action->reviewPayment($payment, $verifier, 'voided', 'Confirmed no actual transfer occurred');
    $this->get(route('administration.customer-returns.index', ['case' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.unrecorded_refund_amount', '0.01')->where('returns.data.0.can_record_payment', true));
});

test('partial recorded payments leave the exact unrecorded amount available only to the approver', function () {
    [$id, $order, $item, $stock, $receiver, $approver] = refinementReceived();
    $action = app(HandleCustomerReturn::class);
    $action->decide($id, $approver, true, 'restock', '99.95', 'Partial refund agreed', inspectionConfirmed: true,
        resolution: ['type' => 'refund', 'summary' => 'Customer keeps the agreed accessory value', 'inspection_notes' => 'Hardware checked and fit for sale']);
    $refund = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $this->recordLegacyRefundPayment($refund, $approver, '39.90', 'cash', 'PARTIAL-CASH-1');
    $this->actingAs($approver)->get(route('administration.customer-returns.index', ['case' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.unrecorded_refund_amount', '60.05')->where('returns.data.0.can_record_payment', true)
        ->where('returns.data.0.refund_balance', '99.95'));
    $this->actingAs(refinementEmployee('owner'))->get(route('administration.customer-returns.index', ['case' => $id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('returns.data.0.unrecorded_refund_amount', '60.05')->where('returns.data.0.can_record_payment', false));
});

test('manual return outcomes can be approved without a customer resolution summary', function (string $outcome) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();

    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), [
        'decision' => 'accept', 'resolution_type' => $outcome, 'approved_amount' => '0.00',
        'disposition' => $outcome === 'replacement' ? 'write_off' : 'return_to_customer', 'reason' => 'Outcome agreed after inspection',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customer_return_resolutions', ['customer_return_id' => $id, 'type' => $outcome, 'summary' => 'Outcome agreed after inspection']);
    $this->assertDatabaseHas('customer_returns', ['id' => $id, 'status' => 'accepted']);
})->with(['repair', 'replacement', 'other']);

test('return decision preserves conditional HTTP validation without changing the case', function (array $payload, array $fields) {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();

    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $id), [
        'decision' => 'reject', 'reason' => 'Reviewed actual returned item', ...$payload,
    ])->assertSessionHasErrors($fields);

    $this->assertDatabaseHas('customer_returns', ['id' => $id, 'status' => 'pending']);
    $this->assertDatabaseCount('customer_refunds', 0);
    $this->assertDatabaseCount('customer_return_resolutions', 0);
    expect($stock->refresh()->quarantined_quantity)->toBe(1);
})->with([
    'acceptance requires outcome and amount' => [['decision' => 'accept'], ['disposition', 'approved_amount', 'resolution_type']],
    'reject prohibits disposition' => [['disposition' => 'write_off'], ['disposition']],
    'reject prohibits amount' => [['approved_amount' => '1.00'], ['approved_amount']],
    'reject prohibits supplier' => [['supplier_id' => 1], ['supplier_id']],
    'reject prohibits outcome' => [['resolution_type' => 'repair'], ['resolution_type']],
    'reject prohibits reference' => [['resolution_reference' => 'CASE-1'], ['resolution_reference']],
    'reject prohibits inspection notes' => [['inspection_notes' => 'Item inspected today'], ['inspection_notes']],
    'present inspection confirmation must be accepted' => [['inspection_confirmed' => false], ['inspection_confirmed']],
    'supplier return requires supplier' => [[
        'decision' => 'accept', 'disposition' => 'supplier_return', 'approved_amount' => '0.00', 'resolution_type' => 'repair',
    ], ['supplier_id']],
    'restock requires inspection notes' => [[
        'decision' => 'accept', 'disposition' => 'restock', 'approved_amount' => '100.00', 'resolution_type' => 'refund',
        'inspection_confirmed' => true,
    ], ['inspection_notes']],
]);

test('order detail preserves return totals and permission based profitability visibility', function () {
    [$id, $order, $item, $stock, $receiver, $reviewer] = refinementReceived();
    app(HandleCustomerReturn::class)->decide($id, $reviewer, true, 'write_off', '100.00', 'Returned product written off',
        resolution: ['type' => 'refund']);

    $this->actingAs($reviewer)->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('handler_options', [])
            ->where('order.profitability', [
                'sales_returns' => '100',
                'cost_of_goods_sold' => '0.00',
                'gross_profit' => null,
                'available' => false,
            ]));
    $this->actingAs($receiver)->get(route('administration.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->missing('order.profitability'));
});
