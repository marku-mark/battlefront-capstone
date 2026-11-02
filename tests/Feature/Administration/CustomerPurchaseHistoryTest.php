<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('purchases and order details retain recorded names prices and quantities after catalog archival', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['name' => 'Current catalog name', 'price' => '999.00']);
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified,
        'product_subtotal' => '250.00', 'total_amount' => '250.00',
    ]);
    OrderItem::factory()->for($order)->for($product)->create([
        'product_name_at_time' => 'Name at purchase', 'price_at_time' => '125.00', 'quantity' => 2,
    ]);

    $this->actingAs($owner)->delete(route('administration.products.destroy', $product))->assertSessionHasNoErrors();
    $this->get(route('administration.customers.show', $customer))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('orders.data.0.reference', $order->reference)
        ->where('orders.data.0.status', 'completed')->where('orders.data.0.payment_status', 'verified')
        ->where('orders.data.0.product_subtotal', '250.00')->where('orders.data.0.total_amount', '250.00')
        ->where('orders.data.0.items.0.name', 'Name at purchase')
        ->where('orders.data.0.items.0.quantity', 2)->where('orders.data.0.items.0.price_at_time', '125.00'));
    $this->get(route('administration.orders.show', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.product.name', 'Name at purchase'));
    expect($product->refresh()->trashed())->toBeTrue();
});

test('customer purchases paginate completed orders and preserve the all orders filter', function () {
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->count(11)->create(['status' => OrderStatus::Completed]);
    Order::factory()->for($customer)->create(['status' => OrderStatus::Pending]);
    Order::factory()->for($customer)->create(['status' => OrderStatus::Cancelled]);
    Order::factory()->create(['status' => OrderStatus::Completed]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.customers.show', $customer))->assertInertia(fn (Assert $page) => $page
        ->where('order_status_filter', 'completed')->where('orders.total', 11)->has('orders.data', 10));
    $this->get(route('administration.customers.show', [$customer, 'page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('orders.total', 11)->has('orders.data', 1));
    $this->get(route('administration.customers.show', [$customer, 'status' => 'all']))->assertInertia(fn (Assert $page) => $page
        ->where('order_status_filter', 'all')->where('orders.total', 13)
        ->where('orders.data.0.status', 'cancelled')->where('orders.data.1.status', 'pending')
        ->where('orders.next_page_url', fn ($url): bool => str_contains($url, 'status=all')));
});

test('legacy purchase product subtotals prefer recorded items before the saved payable total', function (bool $hasItems) {
    $order = Order::factory()->delivery()->paidWithGCash()->create([
        'product_subtotal' => null, 'total_amount' => '310.00', 'status' => OrderStatus::Completed,
    ]);
    if ($hasItems) {
        OrderItem::factory()->for($order)->create(['quantity' => 2, 'price_at_time' => '125.00']);
    }

    $this->actingAs(User::factory()->administrator()->create())->get(route('administration.customers.show', $order->user_id))
        ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.product_subtotal', $hasItems ? '250.00' : '310.00')
            ->where('orders.data.0.delivery_fee', '0.00')->where('orders.data.0.total_amount', '310.00'));
})->with([true, false]);

test('customer history remains readable without permission to open order or return destinations', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    DB::table('administration_role_permissions')->where('administration_role_id', $manager->administration_role_id)
        ->whereIn('administration_permission_id', DB::table('administration_permissions')->whereIn('code', ['orders.view', 'returns.request'])->pluck('id'))->delete();
    $order = Order::factory()->create(['status' => OrderStatus::Completed]);

    $this->actingAs($manager)->get(route('administration.customers.show', $order->user_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('orders.total', 1)
            ->where('auth.can.viewOrders', false)->where('auth.can.requestReturns', false));
    $this->get(route('administration.orders.show', $order))->assertForbidden();
    $this->get(route('administration.customer-returns.index'))->assertForbidden();
});

test('staff and IT cannot read customer purchase history by default', function (string $role) {
    $actor = User::factory()->administrator()->create([
        'administration_role_id' => ($role === 'it' ? legacyItRoleId() : DB::table('administration_roles')->where('code', $role)->value('id')),
    ]);

    $this->actingAs($actor)->get(route('administration.customers.show', User::factory()->customer()->create()))->assertForbidden();
})->with(['staff', 'it']);
