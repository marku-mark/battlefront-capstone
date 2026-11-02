<?php

use App\Actions\Order\AssignOrderHandler;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdministrationWorkQueue;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('work queue is oldest first and excludes tasks outside existing permissions', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $requestId = DB::table('inventory_adjustment_requests')->insertGetId([
        'product_id' => $product->id,
        'requester_id' => $staff->id,
        'observed_quantity' => $product->inventory->quantity,
        'target_quantity' => $product->inventory->quantity + 1,
        'status' => 'pending',
        'reason' => 'Physical count requires review',
        'created_at' => '2026-10-01 10:00:00',
        'updated_at' => '2026-10-01 10:00:00',
    ]);
    $order = Order::factory()->create(['created_at' => '2026-10-02 10:00:00']);

    $this->actingAs($owner)->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/WorkQueue')
            ->where('entries.total', 2)
            ->where('workQueueCounts.stock_adjustment', 1)
            ->where('workQueueCounts.order', 1)
            ->where('entries.data.0.id', $requestId)
            ->where('entries.data.0.age_days', 19)
            ->where('entries.data.0.href', route('administration.inventory-adjustments.index', ['case' => $requestId]))
            ->where('entries.data.1.id', $order->id));

    $this->actingAs($staff)->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('workQueueCounts', ['order' => 1])
            ->where('entries.data.0.kind', 'order'));
});

test('customers cannot open the administration work queue', function () {
    $this->actingAs(User::factory()->customer()->create())
        ->get(route('administration.work-queue.index'))->assertForbidden();
});

test('supervisors retain claimed orders in the shared queue until completion or cancellation', function (string $role, string $ending) {
    $supervisor = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
    $handler = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->paidWithGCash()->create(['status' => 'processing', 'payment_status' => 'verified']);
    app(AssignOrderHandler::class)->execute($order, $handler, $handler->id, claimOnly: true);

    $this->actingAs($supervisor)->get(route('administration.work-queue.index', ['kind' => 'order']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.id', $order->id)->where('entries.data.0.responsible_name', $handler->name));
    $handler->is_active = false;
    $handler->save();
    $this->get(route('administration.work-queue.index', ['kind' => 'order']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.required_action', 'Reassign inactive handler'));
    $handler->is_active = true;
    $handler->save();
    app(OrderProcessingService::class)->updateStatus($order, OrderStatus::from($ending), actor: $handler, productsHandedOver: $ending === 'completed');

    $this->get(route('administration.work-queue.index', ['kind' => 'order']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)
            ->where('workQueueCounts', []));
})->with(['manager', 'owner'])->with(['completed', 'cancelled']);

test('legacy IT assignments cannot access the business work queue', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    Order::factory()->create();

    $this->actingAs($it)->get(route('administration.work-queue.index'))
        ->assertForbidden();
});

test('sidebar counts use one aggregate query and remain independent of queue pagination', function () {
    $owner = User::factory()->administrator()->create();
    Order::factory()->count(26)->create();
    $queue = app(AdministrationWorkQueue::class);
    $queue->counts($owner);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $counts = $queue->counts($owner);

    expect($counts)->toBe(['order' => 26]);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    $this->actingAs($owner)->get(route('administration.work-queue.index', ['kind' => 'damage']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)
            ->where('workQueueCounts.order', 26));
});

test('unrelated partial reloads do not resolve sidebar work counts', function () {
    $owner = User::factory()->administrator()->create();
    $initial = $this->actingAs($owner)->get(route('administration.work-queue.index'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($owner)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Administration/WorkQueue',
        'X-Inertia-Partial-Data' => 'auth',
    ])->get(route('administration.work-queue.index'))
        ->assertOk()
        ->assertJsonPath('props.auth.user.id', $owner->id)
        ->assertJsonMissingPath('props.workQueueCounts')
        ->assertJsonMissingPath('props.entries');

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'work_queue')
        && str_contains($query['query'], 'group by')))->toHaveCount(0);
    DB::disableQueryLog();
});

test('work queue includes damage supplier handover and unpaid refund actions for an owner', function () {
    $owner = User::factory()->administrator()->create();
    $requester = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => 'completed']);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'price_at_time' => '100.00',
    ]);
    DB::table('damage_reports')->insert([
        'product_id' => $product->id, 'requester_id' => $requester->id,
        'quantity' => 1, 'status' => 'pending', 'reason' => 'Physical damage identified',
        'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
    ]);
    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Test supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('supplier_returns')->insert([
        'supplier_id' => $supplierId, 'product_id' => $product->id,
        'requester_id' => $requester->id, 'quantity' => 1, 'status' => 'rejected_follow_up',
        'reason' => 'Return rejected', 'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-02 10:00:00',
    ]);
    $handoverId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $owner->id,
        'status' => 'rejected_pending_handover', 'reason' => 'Inspection rejected return',
        'created_at' => '2026-10-03 10:00:00', 'updated_at' => '2026-10-03 10:00:00',
    ]);
    $approvedId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $owner->id,
        'status' => 'accepted', 'reason' => 'Accepted return',
        'created_at' => '2026-10-04 10:00:00', 'updated_at' => '2026-10-04 10:00:00',
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $approvedId, 'order_item_id' => $itemId,
        'quantity' => 1, 'condition' => 'opened', 'disposition' => 'write_off',
    ]);
    DB::table('customer_refunds')->insert([
        'customer_return_id' => $approvedId, 'approved_by' => $owner->id,
        'approved_amount' => '50.00', 'paid_amount' => '20.00',
        'created_at' => '2026-10-04 10:00:00', 'updated_at' => '2026-10-04 10:00:00',
    ]);

    $this->actingAs($owner)->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 4)
            ->where('workQueueCounts', ['customer_handover' => 1, 'damage' => 1, 'refund' => 1, 'supplier_return' => 1])
            ->where('entries.data.0.kind', 'damage')
            ->where('entries.data.1.kind', 'supplier_return')
            ->where('entries.data.2.kind', 'customer_handover')
            ->where('entries.data.2.href', route('administration.customer-returns.index', ['case' => $handoverId]))
            ->where('entries.data.3.kind', 'refund'));

    $this->get(route('administration.work-queue.index', ['kind' => 'damage']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.kind', 'damage')->where('filters.kind', 'damage'));
});

test('work queue links return reviews and refund reconciliation to the correct parent case', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['status' => 'completed']);
    $product = Product::factory()->available()->create();
    $orderItemId = DB::table('order_items')->insertGetId([
        'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 2, 'price_at_time' => '100.00',
    ]);
    $pendingReturnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $staff->id,
        'status' => 'pending', 'reason' => 'Customer reported a fault',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $pendingReturnId, 'order_item_id' => $orderItemId,
        'quantity' => 1, 'condition' => 'opened', 'disposition' => 'write_off',
    ]);
    $ownReturnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $owner->id,
        'status' => 'pending', 'reason' => 'Return awaiting independent review',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $ownReturnId, 'order_item_id' => $orderItemId,
        'quantity' => 1, 'condition' => 'opened', 'disposition' => 'write_off',
    ]);
    $acceptedReturnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $staff->id,
        'status' => 'accepted', 'reason' => 'Accepted return for refund',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $refundId = DB::table('customer_refunds')->insertGetId([
        'customer_return_id' => $acceptedReturnId, 'approved_by' => $owner->id,
        'approved_amount' => '100.00', 'paid_amount' => '30.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $paymentId = DB::table('customer_refund_payments')->insertGetId([
        'customer_refund_id' => $refundId, 'recorded_by' => $staff->id,
        'amount' => '30.00', 'payment_method' => 'cash', 'external_reference' => 'REFUND-REVIEW-1',
        'paid_at' => now(),
    ]);
    DB::table('customer_refund_payments')->insert([
        'customer_refund_id' => $refundId, 'recorded_by' => $owner->id,
        'amount' => '10.00', 'payment_method' => 'cash', 'external_reference' => 'REFUND-SELF-REVIEW-1',
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)->get(route('administration.work-queue.index'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 3)
            ->where('entries.data.0.kind', 'customer_return')
            ->where('entries.data.0.href', route('administration.customer-returns.index', ['case' => $pendingReturnId]))
            ->where('entries.data.1.kind', 'refund')
            ->where('entries.data.1.href', route('administration.customer-returns.index', ['case' => $acceptedReturnId]))
            ->where('entries.data.2.kind', 'refund_reconciliation')
            ->where('entries.data.2.href', route('administration.customer-returns.index', [
                'case' => $acceptedReturnId,
                'payment_id' => $paymentId,
            ]).'#refund-payment-'.$paymentId)
        );

    $this->actingAs($owner)->get(route('administration.customer-returns.index', ['payment_id' => $paymentId]))
        ->assertInertia(fn (Assert $page) => $page->where('selected_case', $acceptedReturnId)
            ->where('selected_payment', $paymentId));
});

test('refund reconciliation queue excludes verified and voided payments', function () {
    $owner = User::factory()->administrator()->create();
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['status' => 'completed']);
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $staff->id, 'status' => 'accepted', 'reason' => 'Approved return',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $refundId = DB::table('customer_refunds')->insertGetId([
        'customer_return_id' => $returnId, 'approved_by' => $owner->id,
        'approved_amount' => '100.00', 'paid_amount' => '60.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([null, 'verified', 'voided'] as $decision) {
        $paymentId = DB::table('customer_refund_payments')->insertGetId([
            'customer_refund_id' => $refundId, 'recorded_by' => $staff->id, 'amount' => '20.00',
            'payment_method' => 'cash', 'external_reference' => 'QUEUE-'.($decision ?? 'pending'), 'paid_at' => now(),
        ]);
        if ($decision !== null) {
            DB::table('customer_refund_payment_reviews')->insert([
                'customer_refund_payment_id' => $paymentId, 'reviewed_by' => $owner->id,
                'decision' => $decision, 'reason' => 'Reviewed', 'created_at' => now(),
            ]);
        }
    }

    $this->actingAs($owner)->get(route('administration.work-queue.index', ['kind' => 'refund_reconciliation']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)
            ->where('entries.data.0.kind', 'refund_reconciliation')
            ->where('entries.data.0.href', route('administration.customer-returns.index', [
                'case' => $returnId,
                'payment_id' => DB::table('customer_refund_payments')->where('external_reference', 'QUEUE-pending')->value('id'),
            ]).'#refund-payment-'.DB::table('customer_refund_payments')->where('external_reference', 'QUEUE-pending')->value('id')));
});
