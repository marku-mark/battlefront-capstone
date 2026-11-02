<?php

use App\Actions\Inventory\DecideInventoryAdjustment;
use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Actions\Inventory\HandleDamage;
use App\Actions\Inventory\HandleSupplierReturn;
use App\Actions\Inventory\RequestInventoryAdjustment;
use App\Actions\Order\AssignOrderHandler;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderNotificationOccurred;
use App\Jobs\RetryOrderNotifications;
use App\Jobs\SendExpoNotification;
use App\Listeners\PersistOrderNotifications;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\Notifications\NotificationHistory;
use App\Services\Notifications\OrderNotificationPublisher;
use App\Services\Order\OrderProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function workflowEmployee(string $role = 'manager', bool $active = true): User
{
    return User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
        'is_active' => $active,
    ]);
}

function notificationSupplier(): int
{
    return DB::table('suppliers')->insertGetId([
        'name' => 'Notification supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{Order, OrderItem} */
function notificationPurchase(): array
{
    test()->fakeIsolatedStorage('local');
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $inventory = Inventory::factory()->create(['quantity' => 5, 'quarantined_quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($inventory->product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);

    return [$order, $item];
}

test('return intake follows staff receipt then independent review and reaches only the owning customer', function () {
    [$order, $item] = notificationPurchase();
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $inactive = workflowEmployee('staff', false);
    $it = workflowEmployee('it');
    $other = User::factory()->customer()->create();
    $returns = app(HandleCustomerReturnRequest::class);

    $request = $returns->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective']);

    expect($staff->notifications()->sole()->data)->toMatchArray([
        'event' => 'customer_return.requested', 'subject' => ['type' => 'customer_return_request', 'id' => $request->id],
    ]);
    expect($manager->notifications()->count())->toBe(0);
    expect($order->user->notifications()->count())->toBe(0);

    $returns->receive($staff, $request->id, 'opened', [UploadedFile::fake()->image('item.jpg')]);

    expect($manager->notifications()->sole()->data['event'])->toBe('customer_return.received');
    expect($staff->notifications()->count())->toBe(1);
    expect($order->user->notifications()->sole()->data['title'])->toBe('Returned item received');
    expect($inactive->notifications()->count())->toBe(0);
    expect($it->notifications()->count())->toBe(0);
    expect($other->notifications()->count())->toBe(0);
});

test('physical return decisions notify the correct customer outcome and complete manual resolutions once', function (string $outcome) {
    [$order, $item] = notificationPurchase();
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $returns = app(HandleCustomerReturn::class);
    $id = $returns->receive($staff, $item->id, 1, 'opened', 'Reported fault', 'defective', photos: [UploadedFile::fake()->image('item.jpg')]);
    $accept = $outcome !== 'rejected';
    $monetary = in_array($outcome, ['refund', 'supplier_refund'], true);
    $supplierId = $outcome === 'supplier_refund' ? notificationSupplier() : null;
    $owner = $supplierId === null ? null : workflowEmployee('owner');

    $returns->decide($id, $manager, $accept, $accept ? ($supplierId !== null ? 'supplier_return' : ($outcome === 'repair' ? 'return_to_customer' : 'write_off')) : '', $monetary ? '100.00' : '0.00', 'Reviewed the item carefully.', supplierId: $supplierId, resolution: $accept ? ['type' => $monetary ? 'refund' : $outcome] : []);

    $decision = $order->user->notifications()->where('data->event', $accept ? 'customer_return.accepted' : 'customer_return.rejected')->sole();
    expect($decision->data['title'])->toBe($monetary ? 'Refund processing' : ($accept ? 'Return approved' : 'Return rejected'));
    expect($staff->notifications()->where('data->event', $decision->data['event'])->count())->toBe(1);
    expect($order->user->notifications()->count())->toBe(2);
    if ($owner !== null) {
        expect($owner->notifications()->where('data->event', 'supplier_return.requested')->count())->toBe(1);
        expect($manager->notifications()->where('data->event', 'supplier_return.requested')->count())->toBe(0);
    }
    if ($accept && ! $monetary) {
        $returns->completeResolution($id, $staff, 'Actual customer resolution completed.', $outcome === 'repair');
        expect($order->user->notifications()->where('data->event', 'customer_return.resolution_completed')->count())->toBe(1);
        expect(fn () => $returns->completeResolution($id, $staff, 'Repeated completion.', true))->toThrow(ValidationException::class);
        expect($order->user->notifications()->count())->toBe(3);
    }
})->with(['refund', 'supplier_refund', 'repair', 'replacement', 'other', 'rejected']);

test('partial confirmed refunds stay silent and full confirmation notifies once despite payment replay', function (bool $legacy) {
    [$order, $item] = notificationPurchase();
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $returns = app(HandleCustomerReturn::class);
    $id = $returns->receive($staff, $item->id, 1, 'opened', 'Reported fault', 'defective', photos: [UploadedFile::fake()->image('item.jpg')]);
    $returns->decide($id, $manager, true, 'write_off', '100.00', 'Approved monetary refund.', resolution: ['type' => 'refund']);
    $refundId = DB::table('customer_refunds')->where('customer_return_id', $id)->value('id');
    $returns->recordPayment($refundId, $manager, '40.00', 'cash', (string) Str::uuid(), true);

    expect($order->user->notifications()->where('data->event', 'refund.completed')->count())->toBe(0);

    if ($legacy) {
        $paymentId = $this->recordLegacyRefundPayment($refundId, $manager, '60.00', 'cash', 'Legacy payment');
        $reviewer = workflowEmployee('owner');
        $returns->reviewPayment($paymentId, $reviewer, 'verified', 'Confirmed legacy payment receipt.');
        expect(fn () => $returns->reviewPayment($paymentId, $reviewer, 'verified', 'Repeated verification.'))->toThrow(ValidationException::class);
    } else {
        $key = (string) Str::uuid();
        $returns->recordPayment($refundId, $manager, '60.00', 'cash', $key, true);
        $returns->recordPayment($refundId, $manager, '60.00', 'cash', $key, true);
    }

    expect($order->user->notifications()->where('data->event', 'refund.completed')->sole()->data['title'])->toBe('Refund completed');
    $this->assertDatabaseCount('customer_refund_payments', 2);
})->with([true, false]);

test('wallet cancellation review sends one truthful decision notice and targets eligible reviewers', function (bool $approve, string $received, string $title) {
    $order = Order::factory()->paidWithGCash()->create(['total_amount' => '100.00']);
    $manager = workflowEmployee();
    $staff = workflowEmployee('staff');
    $it = workflowEmployee('it');
    $inactive = workflowEmployee('owner', false);
    $processing = app(OrderProcessingService::class);

    $processing->requestCancellation($order->user, $order->id);

    expect($manager->notifications()->sole()->data['event'])->toBe('cancellation.requested');
    expect($order->user->notifications()->sole()->data['body'])->toContain('received your cancellation request');
    expect($staff->notifications()->count())->toBe(0);
    expect($it->notifications()->count())->toBe(0);
    expect($inactive->notifications()->count())->toBe(0);

    $processing->reviewCancellation($order, $manager, $approve, $received, 'Checked the actual wallet account.', true);

    expect($order->user->notifications()->count())->toBe(2);
    expect($order->user->notifications()->where('data->event', $approve ? 'cancellation.approved' : 'cancellation.rejected')->sole()->data['title'])->toBe($title);
    expect($order->user->notifications()->where('data->event', 'order.cancelled')->count())->toBe(0);
    expect($manager->notifications()->count())->toBe(1);
})->with([[true, '100.00', 'Refund processing'], [true, '0.00', 'Cancellation approved'], [false, '100.00', 'Cancellation request rejected']]);

test('assignment informs new and former handlers while noops claims and rollbacks remain silent', function () {
    $manager = workflowEmployee();
    $former = workflowEmployee('staff');
    $next = workflowEmployee('staff');
    $other = workflowEmployee('staff');
    $order = Order::factory()->create(['assigned_handler_id' => $former->id]);
    $assign = app(AssignOrderHandler::class);

    $assign->execute($order, $manager, $next->id);
    $assign->execute($order, $manager, $next->id);

    expect($next->notifications()->sole()->data['title'])->toBe('Order assigned to you');
    expect($former->notifications()->sole()->data['title'])->toBe('Order responsibility transferred');
    expect($manager->notifications()->count())->toBe(0);
    expect($other->notifications()->count())->toBe(0);
    $claim = Order::factory()->create();
    $assign->execute($claim, $other, $other->id, true);
    expect($other->notifications()->count())->toBe(0);

    expect(fn () => DB::transaction(function () use ($assign, $order, $manager, $former): void {
        $assign->execute($order, $manager, $former->id);
        throw new RuntimeException('Rollback assignment');
    }))->toThrow(RuntimeException::class, 'Rollback assignment');
    expect($order->refresh()->assigned_handler_id)->toBe($next->id);
    expect($former->notifications()->count())->toBe(1);
    expect($next->notifications()->count())->toBe(1);
});

test('stock request decisions notify only independent reviewers and their requester', function (bool $approve) {
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $other = workflowEmployee('staff');
    $it = workflowEmployee('it');
    $inactive = workflowEmployee('manager', false);
    $inventory = Inventory::factory()->create(['quantity' => 10]);

    $id = app(RequestInventoryAdjustment::class)->execute($inventory->product, $staff, 12, 'Found two additional units.', reasonCode: 'found_stock');

    expect($manager->notifications()->sole()->data['event'])->toBe('inventory.adjustment.requested');
    foreach ([$staff, $other, $it, $inactive] as $excluded) {
        expect($excluded->notifications()->count())->toBe(0);
    }

    app(DecideInventoryAdjustment::class)->execute($id, $manager, $approve, 'Independently checked stock.');

    expect($staff->notifications()->sole()->data['event'])->toBe($approve ? 'inventory.adjustment.approved' : 'inventory.adjustment.rejected');
    expect($manager->notifications()->count())->toBe(1);
})->with([true, false]);

test('supplier handoffs and final resolution target responsible staff without duplicate task notices', function () {
    $staff = workflowEmployee('staff');
    $otherStaff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $supplierId = notificationSupplier();
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $returns = app(HandleSupplierReturn::class);

    $id = $returns->request($staff, $supplierId, $inventory->product_id, 1, 'Faulty supplier unit.', 'defective');
    $returns->decide($id, $manager, true, 'Return approved.');
    $returns->dispatch($id, $staff, 'Shipment reference');

    expect($staff->notifications()->sole()->data['event'])->toBe('supplier_return.approved');
    expect($otherStaff->notifications()->count())->toBe(0);
    expect($manager->notifications()->where('data->event', 'supplier_return.dispatched')->count())->toBe(1);

    $owner = workflowEmployee('owner');
    $returns->acknowledge($id, $manager, 'Supplier acknowledgment');
    expect($owner->notifications()->sole()->data['event'])->toBe('supplier_return.closed');
    $returns->resolve($id, $owner, 'replacement', 'Replacement reference', 'Replacement received.');

    expect($staff->notifications()->where('data->event', 'supplier_return.resolved')->count())->toBe(1);
    expect($staff->notifications()->count())->toBe(2);
});

test('damage disposition publishes its generated supplier request to another reviewer', function () {
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $owner = workflowEmployee('owner');
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $supplierId = notificationSupplier();
    $damage = app(HandleDamage::class);

    $id = $damage->report($inventory->product, $staff, 1, 'Faulty unit found.', 'defective');
    $damage->decide($id, $manager, 'supplier_return', 'Send the faulty unit back.', $supplierId);

    expect($staff->notifications()->sole()->data['event'])->toBe('damage.decided');
    expect($manager->notifications()->where('data->event', 'supplier_return.requested')->count())->toBe(0);
    expect($owner->notifications()->where('data->event', 'supplier_return.requested')->count())->toBe(1);
});

test('linked supplier rejection alerts an independent follow-up reviewer and retry creates new actionable work', function (bool $retry) {
    $staff = workflowEmployee('staff');
    $manager = workflowEmployee();
    $owner = workflowEmployee('owner');
    $followUpReviewer = workflowEmployee();
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $supplierId = notificationSupplier();
    $damage = app(HandleDamage::class);
    $damageId = $damage->report($inventory->product, $staff, 1, 'Faulty unit found.', 'defective');
    $damage->decide($damageId, $manager, 'supplier_return', 'Supplier should inspect it.', $supplierId);
    $id = DB::table('supplier_returns')->where('damage_report_id', $damageId)->value('id');
    $returns = app(HandleSupplierReturn::class);

    $returns->decide($id, $owner, false, 'Supplier declined this request.');

    expect($manager->notifications()->where('data->event', 'supplier_return.rejected_follow_up')->count())->toBe(1);
    expect($followUpReviewer->notifications()->where('data->event', 'supplier_return.rejected_follow_up')->count())->toBe(1);
    expect($owner->notifications()->where('data->event', 'supplier_return.rejected_follow_up')->count())->toBe(0);
    expect($staff->notifications()->where('data->event', 'supplier_return.rejected_follow_up')->count())->toBe(0);

    $returns->followUp($id, $followUpReviewer, $retry ? 'retry' : 'release', 'Independently assessed the follow-up.', $retry ? $supplierId : null, ! $retry);

    expect($manager->notifications()->where('data->event', $retry ? 'supplier_return.requested' : 'supplier_return.follow_up_resolved')->count())->toBe(1);
    if ($retry) {
        $this->assertDatabaseCount('supplier_returns', 2);
        expect($followUpReviewer->notifications()->where('data->event', 'supplier_return.requested')->count())->toBe(1);
    }
})->with([true, false]);

test('operational rollback creates no history and notification failures do not undo valid requests', function () {
    $staff = workflowEmployee('staff');
    workflowEmployee();
    $inventory = Inventory::factory()->create(['quantity' => 10]);
    $requests = app(RequestInventoryAdjustment::class);

    expect(fn () => DB::transaction(function () use ($requests, $inventory, $staff): void {
        $requests->execute($inventory->product, $staff, 12, 'Physical count checked.', reasonCode: 'found_stock');
        throw new RuntimeException('Rollback request');
    }))->toThrow(RuntimeException::class, 'Rollback request');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('inventory_adjustment_requests', 0);

    Bus::fake([RetryOrderNotifications::class]);
    Event::listen('eloquent.creating: '.DatabaseNotification::class, fn (): never => throw new RuntimeException('Notification unavailable'));
    try {
        $id = $requests->execute($inventory->product, $staff, 12, 'Physical count checked.', reasonCode: 'found_stock');
    } finally {
        Event::forget('eloquent.creating: '.DatabaseNotification::class);
    }

    $this->assertDatabaseHas('inventory_adjustment_requests', ['id' => $id, 'status' => 'pending']);
    Bus::assertDispatchedTimes(RetryOrderNotifications::class, 1);
    app()->call([Bus::dispatched(RetryOrderNotifications::class)->sole(), 'handle']);
    $this->assertDatabaseCount('notifications', 1);
});

test('operational replay preserves read state and inventory-only history stays scoped and readable', function () {
    $this->freezeTime();
    $staff = workflowEmployee('staff');
    $other = workflowEmployee('staff');
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'inventory.adjustment.approved', null, null, null, now()->toIso8601String(), context: ['subject_id' => 7, 'requester_id' => $staff->id]);
    $listener = app(PersistOrderNotifications::class);
    $listener->handle($event);
    $notice = $staff->notifications()->sole();
    $notice->markAsRead();
    $readAt = $notice->read_at;

    $listener->handle($event);

    expect($staff->notifications()->sole()->read_at)->toEqual($notice->read_at);
    $permissionId = DB::table('administration_permissions')->where('code', 'orders.view')->value('id');
    DB::table('administration_role_permissions')->where('administration_role_id', $staff->administration_role_id)->where('administration_permission_id', $permissionId)->delete();
    $this->actingAs($staff)->getJson(route('administration.notifications.summary'))->assertOk()
        ->assertJsonPath('data.recent.0.subject.type', 'stock_adjustment')->assertJsonPath('data.recent.0.subject.id', 7);
    app(OrderNotificationPublisher::class)->operationalAfterCommit('inventory.adjustment.rejected', 8, $staff->id, $other->id);
    $this->getJson(route('administration.notifications.summary'))->assertJsonPath('data.unread_count', 1);
    $this->travel(1)->minute();
    $this->from(route('administration.notifications.index'))->patch(route('administration.notifications.read-all'))
        ->assertRedirect(route('administration.notifications.index'));
    $this->getJson(route('administration.notifications.summary'))->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.recent.0.is_read', true)->assertJsonPath('data.recent.1.is_read', true);
    expect($notice->refresh()->read_at)->toEqual($readAt);
    expect($staff->notifications()->whereNull('read_at')->count())->toBe(0);
    $this->actingAs($other)->patch(route('administration.notifications.update', $notice->id))->assertNotFound();

    $permissionId = DB::table('administration_permissions')->where('code', 'inventory.view')->value('id');
    DB::table('administration_role_permissions')->where('administration_role_id', $staff->administration_role_id)->where('administration_permission_id', $permissionId)->delete();
    $this->actingAs($staff)->getJson(route('administration.notifications.summary'))->assertJsonCount(0, 'data.recent');
});

test('proof alerts follow handler ownership and inactive handlers require supervisor attention', function () {
    $handler = workflowEmployee('staff');
    $other = workflowEmployee('staff');
    $manager = workflowEmployee();
    $order = Order::factory()->create(['assigned_handler_id' => $handler->id]);

    app(OrderNotificationPublisher::class)->afterCommit($order, 'payment.proof_submitted');

    expect($handler->notifications()->count())->toBe(1);
    expect($other->notifications()->count())->toBe(0);
    expect($manager->notifications()->count())->toBe(0);
    $handler->forceFill(['is_active' => false])->save();

    app(OrderNotificationPublisher::class)->afterCommit($order, 'payment.proof_submitted');

    expect($manager->notifications()->count())->toBe(1);
    expect($handler->notifications()->count())->toBe(1);
    expect($other->notifications()->count())->toBe(0);
});

test('notification persistence refreshes role grants between events and retries', function () {
    $manager = workflowEmployee();
    $order = Order::factory()->create();
    app(OrderNotificationPublisher::class)->afterCommit($order, 'order.placed');
    $permissionIds = DB::table('administration_permissions')->whereIn('code', ['orders.process', 'orders.assign'])->pluck('id');
    DB::table('administration_role_permissions')->where('administration_role_id', $manager->administration_role_id)->whereIn('administration_permission_id', $permissionIds)->delete();
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'order.placed', $order->id, $order->user_id, $order->reference, now()->toIso8601String());

    app()->call([new RetryOrderNotifications($event), 'handle']);

    expect($manager->notifications()->count())->toBe(1);
});

test('explicit self assignment still notifies its new handler while self claims stay silent', function () {
    $manager = workflowEmployee();
    $order = Order::factory()->create();

    app(AssignOrderHandler::class)->execute($order, $manager, $manager->id);

    expect($manager->notifications()->sole()->data['title'])->toBe('Order assigned to you');
});

test('new return events preserve customer push and deep links without administrator push', function () {
    config(['services.expo.enabled' => true]);
    Bus::fake([SendExpoNotification::class]);
    $order = Order::factory()->create();
    PushDevice::factory()->for($order->user)->create();
    $manager = workflowEmployee();
    $event = new OrderNotificationOccurred((string) Str::uuid(), 'customer_return.received', $order->id, $order->user_id, $order->reference, now()->toIso8601String());

    app(PersistOrderNotifications::class)->handle($event);
    app(PersistOrderNotifications::class)->handle($event);

    expect($order->user->notifications()->count())->toBe(1);
    expect($manager->notifications()->count())->toBe(1);
    $this->assertDatabaseCount('notification_push_deliveries', 1);
    expect(app(NotificationHistory::class)->summary($order->user)['recent'][0]['order']['deep_link'])->toBe(['screen' => 'order_detail', 'order_id' => $order->id]);
    Bus::assertDispatched(SendExpoNotification::class);
});
