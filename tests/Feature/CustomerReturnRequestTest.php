<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Actions\Product\DeleteProduct;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AdministrationEvidenceService;
use App\Services\AdministrationWorkQueue;
use App\Services\AuditLogPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{Order, OrderItem, Inventory} */
function returnRequestPurchase(int $quantity = 3): array
{
    Storage::fake('local');
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $inventory = Inventory::factory()->create(['quantity' => 0, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($inventory->product)->create([
        'quantity' => $quantity, 'price_at_time' => '100.00', 'product_name_at_time' => 'Purchased hardware',
    ]);
    $order->sale()->create(['amount' => '300.00', 'sale_date' => today()]);

    return [$order->refresh(), $item, $inventory];
}

/** @return array{order_item_id: int, quantity: int, reason_code: string} */
function returnRequestPayload(OrderItem $item, int $quantity = 1): array
{
    return ['order_item_id' => $item->id, 'quantity' => $quantity, 'reason_code' => 'defective'];
}

function returnRequestEmployee(string $role): User
{
    return User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);
}

test('completed purchase request is shared by web and API without stock or financial changes', function () {
    [$order, $item, $inventory] = returnRequestPurchase();
    $beforeOrder = $order->getAttributes();
    $beforeSale = $order->sale->refresh()->getAttributes();
    $this->withToken($order->user->createToken('Phone')->plainTextToken);

    $response = $this->postJson(route('api.v1.orders.return-requests.store', $order), [
        ...returnRequestPayload($item), 'user_id' => 999, 'customer_return_id' => 999, 'status' => 'accepted',
    ])->assertCreated()->assertJsonPath('data.status.value', 'submitted')->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.product.name', 'Purchased hardware')->assertJsonPath('data.can_withdraw', true);
    $record = $response->json('data');
    $this->getJson(route('api.v1.orders.return-requests.index', $order))->assertExactJson(['data' => [$record]]);
    $this->getJson(route('api.v1.orders.return-requests.show', [$order, $record['id']]))->assertExactJson(['data' => $record]);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.return_requests.0', $record)->where('order.can_request_return', false)
        ->where('order.items.0.remaining_returnable', 2));
    expect($inventory->refresh()->quantity)->toBe(0);
    expect($inventory->quarantined_quantity)->toBe(0);
    expect($order->refresh()->getAttributes())->toBe($beforeOrder);
    expect($order->sale->refresh()->getAttributes())->toBe($beforeSale);
    foreach (['customer_returns', 'customer_return_items', 'customer_refunds', 'customer_refund_payments', 'inventory_movements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $order->user_id, 'action' => 'customer_return_request.submitted']);
});

test('validated numeric form inputs are normalized for customer return submission', function (string $channel) {
    [$order, $item] = returnRequestPurchase();
    $payload = ['order_item_id' => (string) $item->id, 'quantity' => '2', 'reason_code' => 'defective'];

    if ($channel === 'api') {
        $this->withToken($order->user->createToken('Phone')->plainTextToken)
            ->postJson(route('api.v1.orders.return-requests.store', $order), $payload)
            ->assertCreated()->assertJsonPath('data.order_item_id', $item->id)->assertJsonPath('data.quantity', 2);
    } else {
        $this->actingAs($order->user)->post(route('orders.return-requests.store', $order), $payload)
            ->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();
    }

    $this->assertDatabaseHas('customer_return_requests', ['order_item_id' => $item->id, 'quantity' => 2, 'reason' => null]);
})->with(['web', 'api']);

test('web customers submit and withdraw before receipt while preserving request history', function () {
    [$order, $item, $inventory] = returnRequestPurchase();
    $this->actingAs($order->user)->post(route('orders.return-requests.store', $order), returnRequestPayload($item))
        ->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();
    $record = CustomerReturnRequest::query()->sole();
    $this->post(route('orders.return-requests.withdraw', [$order, $record->id]))->assertRedirect(route('orders.show', $order));
    expect($record->refresh()->withdrawn_at)->not->toBeNull();
    $this->post(route('orders.return-requests.withdraw', [$order, $record->id]))->assertSessionHasErrors('return_request');
    $this->post(route('orders.return-requests.store', $order), returnRequestPayload($item))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customer_return_requests', 2);
    $this->assertDatabaseCount('inventory_movements', 0);
    expect($inventory->refresh()->quarantined_quantity)->toBe(0);
    expect(fn () => DB::table('customer_return_requests')->where('id', $record->id)->delete())->toThrow(QueryException::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_return_request.withdrawn']);
});

test('API withdrawal returns the updated shared resource', function () {
    [$order, $item] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $this->withToken($order->user->createToken('Phone')->plainTextToken)
        ->postJson(route('api.v1.orders.return-requests.withdraw', [$order, $record]))->assertOk()
        ->assertJsonPath('data.status.value', 'withdrawn')->assertJsonPath('data.can_withdraw', false);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.can_request_return', true);
});

test('mobile return endpoints require customer bearer authentication', function (string $access, int $status) {
    [$order, $item] = returnRequestPurchase();
    if ($access === 'session') {
        $this->actingAs($order->user);
    } elseif ($access === 'invalid') {
        $this->withToken('invalid');
    } elseif ($access === 'administrator') {
        $this->withToken(User::factory()->administrator()->create()->createToken('Phone')->plainTextToken);
    }
    $this->postJson(route('api.v1.orders.return-requests.store', $order), returnRequestPayload($item))->assertStatus($status);
    $this->getJson(route('api.v1.orders.return-requests.index', $order))->assertStatus($status);
    $this->assertDatabaseCount('customer_return_requests', 0);
})->with([['guest', 401], ['session', 401], ['invalid', 401], ['administrator', 403]]);

test('web guests and administrators cannot submit customer requests', function (bool $administrator) {
    [$order, $item] = returnRequestPurchase();
    if ($administrator) {
        $this->actingAs(User::factory()->administrator()->create());
    }
    $response = $this->post(route('orders.return-requests.store', $order), returnRequestPayload($item));
    $administrator ? $response->assertForbidden() : $response->assertRedirect(route('login'));
    $this->assertDatabaseCount('customer_return_requests', 0);
})->with([false, true]);

test('foreign orders items and requests remain concealed', function () {
    [$order, $item] = returnRequestPurchase();
    [$foreignOrder, $foreignItem] = returnRequestPurchase();
    $foreign = app(HandleCustomerReturnRequest::class)->submit($foreignOrder->user, $foreignOrder->id, returnRequestPayload($foreignItem));
    $this->withToken($order->user->createToken('Phone')->plainTextToken);
    $this->postJson(route('api.v1.orders.return-requests.store', $foreignOrder), returnRequestPayload($foreignItem))->assertNotFound();
    $this->postJson(route('api.v1.orders.return-requests.store', $order), returnRequestPayload($foreignItem))->assertNotFound();
    $this->getJson(route('api.v1.orders.return-requests.index', $foreignOrder))->assertNotFound();
    $this->getJson(route('api.v1.orders.return-requests.show', [$order, $foreign]))->assertNotFound();
    $this->postJson(route('api.v1.orders.return-requests.withdraw', [$order, $foreign]))->assertNotFound();
    $this->assertDatabaseCount('customer_return_requests', 1);
});

test('incomplete cancelled and unsold orders cannot create requests', function (string $state) {
    [$order, $item] = returnRequestPurchase();
    if ($state === 'unsold') {
        $unsold = Order::factory()->create(['status' => OrderStatus::Completed]);
        $item = OrderItem::factory()->for($unsold)->create();
        $order = $unsold;
    } else {
        $order->status = OrderStatus::from($state);
        $order->save();
    }
    $this->actingAs($order->user)->post(route('orders.return-requests.store', $order), returnRequestPayload($item))
        ->assertSessionHasErrors('order_item_id');
    $this->assertDatabaseCount('customer_return_requests', 0);
})->with(['pending', 'processing', 'cancelled', 'unsold']);

test('invalid quantities reasons and explanations cannot create requests', function (array $override, string $field) {
    [$order, $item] = returnRequestPurchase();
    $this->withToken($order->user->createToken('Phone')->plainTextToken)
        ->postJson(route('api.v1.orders.return-requests.store', $order), array_replace(returnRequestPayload($item), $override))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('customer_return_requests', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
})->with([
    'zero' => [['quantity' => 0], 'quantity'], 'negative' => [['quantity' => -1], 'quantity'],
    'fractional' => [['quantity' => 1.5], 'quantity'], 'excessive' => [['quantity' => 4], 'quantity'],
    'unknown reason' => [['reason_code' => 'warranty'], 'reason_code'],
    'Other absent' => [['reason_code' => 'other'], 'reason'], 'Other blank' => [['reason_code' => 'other', 'reason' => '   '], 'reason'],
    'short notes' => [['reason' => 'four'], 'reason'], 'long notes' => [['reason' => str_repeat('a', 2001)], 'reason'],
]);

test('predefined notes are optional and Other explanations retain existing limits', function (string $code, ?string $notes) {
    [$order, $item] = returnRequestPurchase();
    $this->actingAs($order->user)->post(route('orders.return-requests.store', $order), [
        ...returnRequestPayload($item), 'reason_code' => $code, 'reason' => $notes,
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customer_return_requests', ['reason_code' => $code, 'reason' => $notes === '' ? null : $notes]);
})->with([['wrong_item', null], ['defective', ''], ['transit_damage', null], ['not_as_described', null], ['changed_mind', null], ['other', 'Other'], ['other', str_repeat('a', 2000)]]);

test('one open request blocks duplicates and legacy receipt respects its reservation', function () {
    [$order, $item] = returnRequestPurchase(2);
    $requests = app(HandleCustomerReturnRequest::class);
    $record = $requests->submit($order->user, $order->id, returnRequestPayload($item));
    expect(fn () => $requests->submit($order->user, $order->id, returnRequestPayload($item)))->toThrow(ValidationException::class);
    $staff = returnRequestEmployee('staff');
    expect(fn () => app(HandleCustomerReturn::class)->receive($staff, $item->id, 2, 'opened', 'Defective', 'defective', photos: [UploadedFile::fake()->image('receipt.jpg')]))->toThrow(ValidationException::class);
    app(HandleCustomerReturn::class)->receive($staff, $item->id, 1, 'opened', 'Defective', 'defective', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    $requests->receive($staff, $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    $this->assertDatabaseCount('customer_returns', 2);
    expect(fn () => $requests->submit($order->user, $order->id, returnRequestPayload($item)))->toThrow(ValidationException::class);
});

test('staff receipt quarantines once and keeps receiving and approving employees separate', function (string $reviewRole) {
    [$order, $item, $inventory] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $staff = returnRequestEmployee('staff');
    $this->actingAs($staff)->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('receipt.jpg')]])->assertSessionHasNoErrors();
    $record->refresh();
    $this->assertDatabaseHas('customer_returns', ['id' => $record->customer_return_id, 'requester_id' => $staff->id, 'status' => 'pending']);
    expect($inventory->refresh()->quarantined_quantity)->toBe(1);
    expect($inventory->quantity)->toBe(0);
    $this->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('duplicate.jpg')]])->assertSessionHasErrors('return_request');
    $this->actingAs($order->user)->post(route('orders.return-requests.withdraw', [$order, $record]))->assertSessionHasErrors('return_request');
    Storage::fake('local');
    app(AdministrationEvidenceService::class)->store($staff, 'customer_return', $record->customer_return_id, [UploadedFile::fake()->image('fit-for-sale.jpg')]);
    $decision = ['decision' => 'accept', 'resolution_type' => 'replacement', 'resolution_summary' => 'Manual replacement arranged with customer', 'disposition' => 'restock', 'approved_amount' => '0.00', 'reason' => 'Inspected in person', 'inspection_confirmed' => true, 'inspection_notes' => 'Hardware tested and confirmed fit for sale'];
    $reviewer = returnRequestEmployee($reviewRole);
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $record->customer_return_id), $decision)->assertSessionHasNoErrors();
    expect($inventory->refresh()->quantity)->toBe(1);
    expect($inventory->quarantined_quantity)->toBe(0);
    $this->assertDatabaseCount('customer_returns', 1);
    expect(DB::table('inventory_movements')->where('kind', 'customer_return_received')->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'customer_return_request.received']);
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.return_requests.0.status.value', 'approved')->where('order.can_request_return', true));
})->with(['manager', 'owner']);

test('a legacy receiving manager cannot approve their own converted return', function () {
    [$order, $item] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $manager = returnRequestEmployee('manager');
    $id = app(HandleCustomerReturnRequest::class)->receive(returnRequestEmployee('staff'), $record->id, 'sealed', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    DB::table('customer_returns')->where('id', $id)->update(['requester_id' => $manager->id]);
    expect(fn () => app(HandleCustomerReturn::class)->decide($id, $manager, false, '', '0.00', 'Not eligible after inspection'))
        ->toThrow(ValidationException::class, 'You cannot decide your own return.');
});

test('withdrawn requests and invalid conditions cannot be physically received', function () {
    [$order, $item, $inventory] = returnRequestPurchase();
    $requests = app(HandleCustomerReturnRequest::class);
    $record = $requests->submit($order->user, $order->id, returnRequestPayload($item));
    $this->actingAs(returnRequestEmployee('staff'))->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'invented'])->assertSessionHasErrors('condition');
    $requests->withdraw($order->user, $order->id, $record->id);
    $this->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('withdrawn.jpg')]])->assertSessionHasErrors('return_request');
    $this->assertDatabaseCount('customer_returns', 0);
    expect($inventory->refresh()->quarantined_quantity)->toBe(0);
});

test('IT and customers cannot receive customer requests', function (string $role) {
    [$order, $item] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $actor = $role === 'customer' ? $order->user : returnRequestEmployee($role);
    $this->actingAs($actor)->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'opened'])->assertForbidden();
    $this->assertDatabaseCount('customer_returns', 0);
})->with(['it', 'customer']);

test('receipt rechecks current order eligibility and physical quantities without partial effects', function (string $conflict) {
    [$order, $item, $inventory] = returnRequestPurchase(1);
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $staff = returnRequestEmployee('staff');
    if ($conflict === 'status') {
        $order->status = OrderStatus::Cancelled;
        $order->save();
    } else {
        $legacy = DB::table('customer_returns')->insertGetId([
            'order_id' => $order->id, 'requester_id' => $staff->id, 'status' => 'pending',
            'reason' => 'Previously physically received', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_return_items')->insert(['customer_return_id' => $legacy, 'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened']);
    }
    $beforeReturns = DB::table('customer_returns')->count();

    $this->actingAs($staff)->post(route('administration.customer-return-requests.receive', $record), ['condition' => 'opened', 'files' => [UploadedFile::fake()->image('receipt.jpg')]])
        ->assertSessionHasErrors($conflict === 'status' ? 'order_item_id' : 'quantity');

    expect($record->refresh()->customer_return_id)->toBeNull();
    expect($inventory->refresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseCount('customer_returns', $beforeReturns);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'customer_return_request.received']);
})->with(['status', 'quantity']);

test('request constraints reject invalid data and migration rollback preserves posted history', function () {
    [$order, $item] = returnRequestPurchase();
    $record = CustomerReturnRequest::factory()->create(['order_item_id' => $item->id]);
    expect(fn () => CustomerReturnRequest::factory()->create(['order_item_id' => $item->id, 'quantity' => 0]))->toThrow(QueryException::class);
    expect(fn () => DB::table('customer_return_requests')->where('id', $record->id)->update(['quantity' => 0]))->toThrow(QueryException::class);
    expect(fn () => DB::table('customer_return_requests')->where('id', $record->id)->update(['reason_code' => 'other', 'reason' => null]))->toThrow(QueryException::class);
    expect(fn () => $record->update(['quantity' => 2]))->toThrow(DomainException::class);
    $migration = require database_path('migrations/2026_10_23_055709_create_customer_return_requests_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot remove customer return request history.');
});

test('customer progress uses latest verified payments and never exposes internal review data', function (string $approvedAmount, string $firstPayment, string $remainingPayment) {
    [$order, $item] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    $staff = returnRequestEmployee('staff');
    $reviewer = returnRequestEmployee('manager');
    $returns = app(HandleCustomerReturn::class);
    $id = app(HandleCustomerReturnRequest::class)->receive($staff, $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    Storage::fake('local');
    app(AdministrationEvidenceService::class)->store($staff, 'customer_return', $id, [UploadedFile::fake()->image('tested-device.jpg')]);
    $this->withToken($order->user->createToken('Phone')->plainTextToken);
    $url = route('api.v1.orders.return-requests.show', [$order, $record]);
    $this->getJson($url)->assertJsonPath('data.status.value', 'under_review');
    $returns->decide($id, $reviewer, true, 'restock', $approvedAmount, 'Private employee review notes', inspectionConfirmed: true, resolution: ['type' => 'refund', 'summary' => 'Customer agreed to the documented partial amount', 'inspection_notes' => 'Device tested and confirmed suitable for sale']);
    $refundId = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $this->getJson($url)->assertJsonPath('data.status.value', 'refund_processing')->assertJsonPath('data.refund.verified_paid_amount', '0.00')
        ->assertDontSee('Private employee review notes')->assertJsonMissingPath('data.requester_id')->assertJsonMissingPath('data.customer_return_id');
    $verifier = returnRequestEmployee('owner');
    $this->recordLegacyRefundPayment($refundId, $reviewer, $approvedAmount, 'cash', 'PRIVATE-REF');
    $payment = DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->value('id');
    $this->getJson($url)->assertJsonPath('data.status.value', 'refund_processing')->assertDontSee('PRIVATE-REF');
    $returns->reviewPayment($payment, $verifier, 'disputed', 'Private reconciliation reason');
    $this->getJson($url)->assertJsonPath('data.refund.verified_paid_amount', '0.00');
    $returns->reviewPayment($payment, $verifier, 'voided', 'Wrong payment was recorded');
    $this->recordLegacyRefundPayment($refundId, $reviewer, $firstPayment, 'cash', 'PART-1');
    $first = DB::table('customer_refund_payments')->where('external_reference', 'PART-1')->value('id');
    $returns->reviewPayment($first, $verifier, 'verified', 'Confirmed actual manual payment');
    $this->getJson($url)->assertJsonPath('data.status.value', 'refund_processing')->assertJsonPath('data.refund.remaining_amount', $remainingPayment);
    $this->recordLegacyRefundPayment($refundId, $reviewer, $remainingPayment, 'cash', 'PART-2');
    $second = DB::table('customer_refund_payments')->where('external_reference', 'PART-2')->value('id');
    $returns->reviewPayment($second, $verifier, 'verified', 'Confirmed remaining manual payment');
    $this->getJson($url)->assertJsonPath('data.status.value', 'refunded')->assertJsonPath('data.refund.verified_paid_amount', $approvedAmount)
        ->assertJsonPath('data.refund.remaining_amount', '0.00')->assertDontSee('Private reconciliation reason');
})->with([
    'whole peso amounts' => ['100.00', '40.00', '60.00'],
    'fractional peso amounts' => ['99.95', '39.90', '60.05'],
]);

test('rejected progress preserves quarantine and releases eligibility only after handover', function () {
    [$order, $item, $inventory] = returnRequestPurchase(1);
    $requests = app(HandleCustomerReturnRequest::class);
    $record = $requests->submit($order->user, $order->id, returnRequestPayload($item));
    $staff = returnRequestEmployee('staff');
    $id = $requests->receive($staff, $record->id, 'opened', photos: [UploadedFile::fake()->image('receipt.jpg')]);
    $returns = app(HandleCustomerReturn::class);
    $returns->decide($id, returnRequestEmployee('owner'), false, '', '0.00', 'Private rejection notes');
    $this->withToken($order->user->createToken('Phone')->plainTextToken)->getJson(route('api.v1.orders.show', $order))
        ->assertJsonPath('data.return_requests.0.status.value', 'rejected')->assertJsonPath('data.can_request_return', false)->assertDontSee('Private rejection notes');
    expect($inventory->refresh()->quarantined_quantity)->toBe(1);
    $returns->handBack($id, $staff);
    $this->getJson(route('api.v1.orders.show', $order))->assertJsonPath('data.can_request_return', true);
});

test('pending requests appear in eligible Tasks and audits and block product archival', function () {
    [$order, $item, $inventory] = returnRequestPurchase();
    $record = app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, returnRequestPayload($item));
    foreach (['staff', 'manager', 'owner'] as $role) {
        $employee = returnRequestEmployee($role);
        $tasks = app(AdministrationWorkQueue::class)->paginate($employee, ['kind' => 'customer_request']);
        expect($tasks->total())->toBe($role === 'staff' ? 1 : 0);
        if ($role === 'staff') {
            expect($tasks->items()[0]['href'])->toContain('request_id='.$record->id);
        }
        $this->actingAs($employee)->get(route('administration.customer-returns.index', ['request_id' => $record->id]))
            ->assertInertia(fn (Assert $page) => $page->where('customerRequests.data.0.customer_name', $order->user->name));
    }
    $it = returnRequestEmployee('it');
    expect(app(AdministrationWorkQueue::class)->paginate($it, ['kind' => 'customer_request'])->total())->toBe(0);
    $audit = DB::table('audit_logs')->where('action', 'customer_return_request.submitted')->first();
    $audit->actor_type = 'customer';
    $audit->actor_name = $order->user->name;
    expect(app(AuditLogPresenter::class)->present($audit, returnRequestEmployee('manager'))['details_visible'])->toBeTrue();
    expect(app(AuditLogPresenter::class)->present($audit, $it)['details_visible'])->toBeFalse();
    expect(fn () => app(DeleteProduct::class)->execute($inventory->product))->toThrow(ValidationException::class);
    app(HandleCustomerReturnRequest::class)->withdraw($order->user, $order->id, $record->id);
    expect(app(AdministrationWorkQueue::class)->paginate(returnRequestEmployee('staff'), ['kind' => 'customer_request'])->total())->toBe(0);
});
