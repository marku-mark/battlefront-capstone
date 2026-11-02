<?php

use App\Actions\Order\AssignOrderHandler;
use App\Actions\Order\RecordCompletedOrderSale;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\OrderProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('only completion records a sale for the final assigned handler and subsequent reassignment is rejected', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $staffRoleId = DB::table('administration_roles')->where('code', 'staff')->value('id');
    $first = User::factory()->administrator()->create(['administration_role_id' => $staffRoleId]);
    $final = User::factory()->administrator()->create(['administration_role_id' => $staffRoleId]);
    $order = Order::factory()->paidWithGCash()->create([
        'handler_required' => true, 'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified, 'total_amount' => '100.00',
    ]);
    app(AssignOrderHandler::class)->execute($order, $manager, $first->id);
    app(AssignOrderHandler::class)->execute($order, $manager, $final->id);
    $this->assertDatabaseCount('sales', 0);

    app(OrderProcessingService::class)->updateStatus($order, OrderStatus::Completed, actor: $final, productsHandedOver: true);
    $this->actingAs($manager)->patch(route('administration.orders.handler.update', $order), ['handler_id' => $first->id])->assertSessionHasErrors('handler');
    expect($order->sale()->sole()->handler_id)->toBe($final->id);
    expect(fn () => app(OrderProcessingService::class)->updateStatus($order->fresh(), OrderStatus::Completed))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('sales', 1);
    $this->actingAs($final)->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('staff.0.orders_count', 1)->where('transactions.total', 1)->where('transactions.data.0.order_id', $order->id));
});

test('assigned pending and cancelled orders do not count and historical sales stay unattributed', function () {
    $manager = User::factory()->administrator()->create();
    foreach ([OrderStatus::Pending, OrderStatus::Cancelled] as $status) {
        Order::factory()->create(['status' => $status, 'assigned_handler_id' => $manager->id]);
    }
    $legacy = Order::factory()->create(['status' => OrderStatus::Completed]);
    $legacy->sale()->create(['amount' => '100.00', 'sale_date' => today(config('battlefront.timezone')), 'handler_id' => null]);

    $this->actingAs($manager)->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('unattributed_count', 1)->where('staff.0.orders_count', 0)->where('transactions', null));
    expect($legacy->sale->handler_id)->toBeNull();
});

test('a staff member claims an order and the completed sale keeps that handler', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['handler_required' => true]);

    $this->actingAs($staff)->post(route('administration.orders.claim', $order))->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($staff->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order.handler.assigned', 'subject_id' => $order->id]);

    $order->update(['status' => OrderStatus::Completed]);
    app(RecordCompletedOrderSale::class)->execute($order->fresh());
    expect($order->sale->fresh()->handler_id)->toBe($staff->id);
});

test('opening an unassigned order is read only until staff explicitly claims it', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->create(['handler_required' => true]);

    $this->actingAs($staff)->get(route('administration.orders.show', $order))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('order.assigned_handler_id', null)
        ->where('order.assigned_handler_name', null));

    expect($order->fresh()->assigned_handler_id)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'order.handler.assigned', 'subject_id' => $order->id]);
    $this->post(route('administration.orders.claim', $order))->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($staff->id);
});

test('staff with order view but no processing permission can inspect without claiming', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $staff->administration_role_id)
        ->whereIn('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.process')->select('id'))
        ->delete();
    $order = Order::factory()->create(['handler_required' => true]);

    $this->actingAs($staff)->get(route('administration.orders.show', $order))->assertOk();
    $this->post(route('administration.orders.claim', $order))->assertForbidden();
    expect($order->fresh()->assigned_handler_id)->toBeNull();
});

test('manager sees team sales while staff sees only their own performance', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $order = Order::factory()->create(['total_amount' => '240.00', 'delivery_fee' => '0.00']);
    $order->sale()->create(['amount' => '240.00', 'sale_date' => today(config('battlefront.timezone')), 'handler_id' => $staff->id]);
    $product = Product::factory()->create();
    $orderItemId = DB::table('order_items')->insertGetId([
        'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price_at_time' => '240.00',
    ]);
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id, 'requester_id' => $staff->id, 'status' => 'accepted', 'reason' => 'Accepted return',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId, 'order_item_id' => $orderItemId, 'quantity' => 1, 'condition' => 'opened',
    ]);
    $refundId = DB::table('customer_refunds')->insertGetId([
        'customer_return_id' => $returnId, 'approved_by' => $manager->id, 'approved_amount' => '80.00', 'paid_amount' => '80.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['30.00', 'verified'], ['20.00', 'disputed'], ['30.00', 'voided']] as [$amount, $decision]) {
        $paymentId = DB::table('customer_refund_payments')->insertGetId([
            'customer_refund_id' => $refundId, 'recorded_by' => $staff->id, 'amount' => $amount,
            'payment_method' => 'cash', 'external_reference' => 'REPORT-'.$decision, 'paid_at' => now(),
        ]);
        DB::table('customer_refund_payment_reviews')->insert([
            'customer_refund_payment_id' => $paymentId, 'reviewed_by' => $manager->id,
            'decision' => $decision, 'reason' => 'Review result', 'created_at' => now(),
        ]);
    }

    $this->actingAs($manager)->get(route('administration.reports.staff'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/StaffPerformance')
        ->where('can_view_all', true)->where('selected_handler_id', null)->where('transactions', null)
        ->where('staff.0.id', $staff->id)->where('staff.0.orders_count', 1)->where('staff.0.gross_sales', '240')
        ->where('staff.0.refunds_paid', '30.00')->where('staff.0.net_sales', '210.00'));
    $this->actingAs($staff)->get(route('administration.reports.staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Reports/StaffPerformance')
            ->where('can_view_all', false)->has('staff', 1)->where('staff.0.id', $staff->id));
    $this->actingAs($staff)->get(route('administration.reports.audit'))->assertForbidden();
    $this->actingAs($manager)->get(route('administration.reports.audit'))->assertOk();
    $this->actingAs($manager)->get(route('administration.reports.audit.export'))->assertForbidden();
});

test('my sales lists only the signed in handler across all dates and excludes delivery fees', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $other = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $ownOrder = Order::factory()->withDeliverySnapshot(subtotal: '200.00')->create();
    $ownOrder->sale()->create(['amount' => '260.00', 'sale_date' => '2024-01-05', 'handler_id' => $staff->id]);
    $otherOrder = Order::factory()->create(['total_amount' => '90.00', 'delivery_fee' => '0.00']);
    $otherOrder->sale()->create(['amount' => '90.00', 'sale_date' => today(config('battlefront.timezone')), 'handler_id' => $other->id]);
    $legacyOrder = Order::factory()->create(['total_amount' => '50.00', 'delivery_fee' => '0.00']);
    $legacyOrder->sale()->create(['amount' => '50.00', 'sale_date' => today(config('battlefront.timezone')), 'handler_id' => null]);

    $this->actingAs($staff)->get(route('administration.reports.staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', null)
            ->where('transactions.total', 1)
            ->where('transactions.data.0.order_id', $ownOrder->id)
            ->where('transactions.data.0.gross_sales', '200'));
    $this->actingAs($staff)->get(route('administration.reports.staff', ['handler_id' => $other->id]))
        ->assertForbidden();
});

test('manager can inspect a selected handler and sale records paginate with date filters', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    foreach (range(1, 21) as $day) {
        $order = Order::factory()->create(['total_amount' => '100.00', 'delivery_fee' => '0.00']);
        $order->sale()->create(['amount' => '100.00', 'sale_date' => "2025-01-$day", 'handler_id' => $staff->id]);
    }

    $this->actingAs($manager)->get(route('administration.reports.staff', ['handler_id' => $staff->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected_handler_id', $staff->id)
            ->where('transactions.total', 21)->has('transactions.data', 20));
    $this->get(route('administration.reports.staff', ['handler_id' => $staff->id, 'sale_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('selected_handler_id', $staff->id)
            ->where('transactions.current_page', 2)->where('transactions.total', 21)->has('transactions.data', 1));
    $this->actingAs($manager)->get(route('administration.reports.staff', [
        'handler_id' => $staff->id, 'from' => '2025-01-10', 'to' => '2025-01-10',
    ]))->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)
        ->where('staff.0.orders_count', 1));
});

test('handler report defaults to a team summary of eligible business roles', function (string $role) {
    $this->travelTo('2026-10-22 12:00:00');
    $handlers = [];
    foreach (['staff', 'manager', 'owner'] as $handlerRole) {
        $handlers[$handlerRole] = User::factory()->administrator()->create([
            'administration_role_id' => DB::table('administration_roles')->where('code', $handlerRole)->value('id'),
        ]);
    }
    User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    User::factory()->administrator()->create(['is_active' => false]);
    User::factory()->administrator()->create(['administration_role_id' => null]);
    User::factory()->customer()->create();
    $handlerIds = collect($handlers)->pluck('id')->sort()->values()->all();

    $this->actingAs($handlers[$role])->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('can_view_all', true)
        ->where('auth.can.viewStaffReports', true)
        ->where('filters', ['from' => '2026-10-01', 'to' => '2026-10-22'])
        ->where('selected_handler_id', null)->where('selected_handler_name', null)->where('transactions', null)
        ->where('staff', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === $handlerIds));
})->with(['manager', 'owner']);

test('handler report excludes current accounts without order processing permission or sales history', function (string $role) {
    $viewer = User::factory()->administrator()->create();
    $roleId = ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id'));
    $handler = User::factory()->administrator()->create(['administration_role_id' => $roleId]);
    DB::table('administration_role_permissions')->where('administration_role_id', $roleId)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.process')->value('id'))
        ->delete();

    $this->actingAs($viewer)->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('staff', fn ($rows): bool => ! collect($rows)->pluck('id')->contains($handler->id)));
    $this->get(route('administration.reports.staff', ['handler_id' => $handler->id]))->assertNotFound();
})->with(['staff', 'manager']);

test('handler report respects explicitly unbounded dates in the team summary', function () {
    $viewer = User::factory()->administrator()->create();
    $order = Order::factory()->withDeliverySnapshot(subtotal: '200.00')->create();
    $order->sale()->create(['amount' => '260.00', 'sale_date' => '2024-01-05', 'handler_id' => $viewer->id]);

    $this->actingAs($viewer)->get(route('administration.reports.staff', ['from' => '', 'to' => '']))
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['from' => null, 'to' => null])
            ->where('transactions', null)->where('staff.0.id', $viewer->id)->where('staff.0.gross_sales', '200'));
});

test('handler report preserves genuine historical attribution after access changes', function (string $change) {
    $viewer = User::factory()->administrator()->create();
    $handler = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $order = Order::factory()->withDeliverySnapshot(subtotal: '200.00')->create();
    $sale = $order->sale()->create(['amount' => '260.00', 'sale_date' => '2025-01-05', 'handler_id' => $handler->id]);
    $originalSale = $sale->fresh()->getRawOriginal();
    if ($change === 'revoked processing permission') {
        DB::table('administration_role_permissions')->where('administration_role_id', $handler->administration_role_id)
            ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.process')->value('id'))
            ->delete();
    } else {
        $handler->update(match ($change) {
            'legacy IT assignment' => ['administration_role_id' => legacyItRoleId()],
            'customer role' => ['role' => 'customer', 'administration_role_id' => null],
            'unassigned role' => ['administration_role_id' => null],
            'inactive account' => ['is_active' => false],
        });
    }
    $filters = ['from' => '2025-01-01', 'to' => '2025-01-31'];

    $this->actingAs($viewer)->get(route('administration.reports.staff', $filters))->assertInertia(fn (Assert $page) => $page
        ->where('staff.0.id', $handler->id)->where('staff.0.orders_count', 1)->where('staff.0.gross_sales', '200')
        ->where('transactions', null));
    $this->get(route('administration.reports.staff', [...$filters, 'handler_id' => $handler->id]))->assertInertia(fn (Assert $page) => $page
        ->where('selected_handler_name', $handler->name)->where('transactions.total', 1)
        ->where('transactions.data.0.order_id', $order->id)->where('transactions.data.0.gross_sales', '200'));
    expect($sale->fresh()->getRawOriginal())->toBe($originalSale);
})->with(['revoked processing permission', 'legacy IT assignment', 'customer role', 'unassigned role', 'inactive account']);

test('handler report keeps staff on My sales even with a customized team reporting grant', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $other = User::factory()->administrator()->create();
    DB::table('administration_role_permissions')->insert([
        'administration_role_id' => $staff->administration_role_id,
        'administration_permission_id' => DB::table('administration_permissions')->where('code', 'staff_reports.view')->value('id'),
    ]);

    $this->actingAs($staff)->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('can_view_all', false)->where('auth.can.viewStaffReports', false)
        ->where('selected_handler_id', $staff->id)->has('staff', 1)->where('staff.0.id', $staff->id));
    $this->get(route('administration.reports.staff', ['handler_id' => $other->id]))->assertForbidden();
});

test('handler report requires team permission to select other handlers', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $other = User::factory()->administrator()->create();
    DB::table('administration_role_permissions')->where('administration_role_id', $manager->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'staff_reports.view')->value('id'))
        ->delete();

    $this->actingAs($manager)->get(route('administration.reports.staff'))->assertInertia(fn (Assert $page) => $page
        ->where('can_view_all', false)->where('selected_handler_id', $manager->id)->has('staff', 1));
    $this->get(route('administration.reports.staff', ['handler_id' => $other->id]))->assertForbidden();
});

test('handler report excludes legacy IT assignments from sales reporting', function () {
    $viewer = User::factory()->administrator()->create();
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);

    $this->actingAs($viewer)->get(route('administration.reports.staff', ['handler_id' => $it->id]))->assertNotFound();
    $this->actingAs($it)->get(route('administration.reports.staff'))->assertForbidden();
    DB::table('administration_role_permissions')->where('administration_role_id', $viewer->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'orders.view')->value('id'))
        ->delete();
    $this->actingAs($viewer)->get(route('administration.reports.staff'))->assertForbidden();
});

test('order handlers need order permission but do not need two factor enrollment', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    $it = User::factory()->administrator()->create([
        'administration_role_id' => legacyItRoleId(),
    ]);
    $order = Order::factory()->create(['handler_required' => true]);

    expect($staff->two_factor_confirmed_at)->toBeNull();
    $this->actingAs($manager)->get(route('administration.orders.show', $order))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('handler_options', fn ($options): bool => collect($options)->pluck('id')->contains($staff->id)
            && ! collect($options)->pluck('id')->contains($it->id)));
    expect($order->fresh()->assigned_handler_id)->toBeNull();
    $this->actingAs($manager)->patch(route('administration.orders.handler.update', $order), [
        'handler_id' => $it->id,
    ])->assertSessionHasErrors('handler');

    $this->actingAs($manager)->patch(route('administration.orders.handler.update', $order), [
        'handler_id' => $staff->id,
    ])->assertSessionHasNoErrors();
    expect($order->fresh()->assigned_handler_id)->toBe($staff->id);
});
