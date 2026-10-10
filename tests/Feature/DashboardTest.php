<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('customers receive only their authoritative shopping and order summary', function () {
    $this->travelTo('2026-09-20 12:00:00');
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->create(['quantity' => 2]);
    CartItem::factory()->for($cart)->create(['quantity' => 3]);
    CartItem::factory()
        ->for(Cart::factory()->for($otherCustomer))
        ->create(['quantity' => 9]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'created_at' => now()->subDays(2),
    ]);
    $latestOrder = Order::factory()->for($customer)->delivery()->create([
        'fulfillment_method' => FulfillmentMethod::Delivery->value,
        'status' => OrderStatus::Processing->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '2450.75',
        'created_at' => now()->subHour(),
    ]);
    OrderItem::factory()
        ->count(2)
        ->for($latestOrder)
        ->sequence(['quantity' => 2], ['quantity' => 3])
        ->create();
    Order::factory()->for($otherCustomer)->create([
        'created_at' => now(),
    ]);

    $response = $this
        ->actingAs($customer)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->where('auth.can.accessAdministration', false)
        ->where('dashboard.summary.cart_items', 2)
        ->where('dashboard.summary.cart_units', 5)
        ->where('dashboard.summary.active_orders', 1)
        ->where('dashboard.summary.total_orders', 2)
        ->where('dashboard.latest_order.reference', $latestOrder->reference)
        ->where('dashboard.latest_order.status.value', OrderStatus::Processing->value)
        ->where('dashboard.latest_order.status.label', 'Processing')
        ->where('dashboard.latest_order.item_count', 2)
        ->where('dashboard.latest_order.total_quantity', 5)
        ->where('dashboard.latest_order.total', '2450.75')
        ->missing('dashboard.kpis')
        ->missing('dashboard.needs_attention')
        ->missing('dashboard.recent_orders'));
});

test('administrators receive authoritative operational dashboard data', function () {
    $this->travelTo('2026-09-20 12:00:00');
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $pendingOrder = Order::factory()->for($customer)->paidWithGCash()->create([
        'status' => OrderStatus::Pending->value,
        'payment_status' => PaymentStatus::Pending->value,
        'total_amount' => '1250.00',
        'created_at' => now()->subHour(),
    ]);
    OrderItem::factory()->count(2)->for($pendingOrder)->create(['quantity' => 2]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Processing->value,
        'payment_status' => PaymentStatus::Verified->value,
        'created_at' => now()->subHours(2),
    ]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Cancelled->value,
        'payment_status' => PaymentStatus::Pending->value,
        'created_at' => now()->subHours(3),
    ]);
    $firstCompletedOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '1500.25',
        'created_at' => now()->subDays(2),
    ]);
    $secondCompletedOrder = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed->value,
        'payment_status' => PaymentStatus::Verified->value,
        'total_amount' => '2000.25',
        'created_at' => now()->subDay(),
    ]);
    Sale::factory()->for($firstCompletedOrder)->create([
        'amount' => '1500.25',
        'sale_date' => now()->subDays(2)->toDateString(),
    ]);
    Sale::factory()->for($secondCompletedOrder)->create([
        'amount' => '2000.25',
        'sale_date' => now()->subDay()->toDateString(),
    ]);
    $lowStockProduct = Product::factory()->create([
        'name' => 'Low Stock Keyboard',
        'brand' => 'Battlefront Test',
    ]);
    Inventory::factory()->for($lowStockProduct)->create([
        'quantity' => 5,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for(Product::factory())->create([
        'quantity' => 10,
        'reorder_level' => 5,
    ]);
    $outOfStockProduct = Product::factory()->create(['name' => 'Out of Stock Mouse']);
    Inventory::factory()->for($outOfStockProduct)->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->where('auth.can.accessAdministration', true)
        ->where('dashboard.kpis.recorded_revenue', '3500.50')
        ->where('dashboard.kpis.recorded_sales', 2)
        ->where('dashboard.kpis.pending_orders', 1)
        ->where('dashboard.kpis.processing_orders', 1)
        ->where('dashboard.kpis.low_stock_products', 1)
        ->where('dashboard.kpis.out_of_stock_products', 1)
        ->where('dashboard.needs_attention.pending_payment_reviews', 1)
        ->has('dashboard.needs_attention.payment_orders', 1)
        ->where('dashboard.needs_attention.payment_orders.0.reference', $pendingOrder->reference)
        ->where('dashboard.needs_attention.payment_orders.0.total', '1250.00')
        ->has('dashboard.needs_attention.low_stock_products', 1)
        ->where('dashboard.needs_attention.low_stock_products.0.id', $lowStockProduct->id)
        ->where('dashboard.needs_attention.low_stock_products.0.quantity', 5)
        ->has('dashboard.needs_attention.out_of_stock_products', 1)
        ->where('dashboard.needs_attention.out_of_stock_products.0.id', $outOfStockProduct->id)
        ->where('dashboard.needs_attention.out_of_stock_products.0.quantity', 0)
        ->has('dashboard.recent_orders', 5)
        ->where('dashboard.recent_orders.0.reference', $pendingOrder->reference)
        ->where('dashboard.recent_orders.0.item_count', 2)
        ->where('dashboard.recent_orders.0.total_quantity', 4)
        ->missing('dashboard.summary')
        ->missing('dashboard.latest_order'));
});

test('customers receive stable empty dashboard data', function () {
    $customer = User::factory()->customer()->create();

    $response = $this
        ->actingAs($customer)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->where('dashboard.summary', [
            'cart_items' => 0,
            'cart_units' => 0,
            'active_orders' => 0,
            'total_orders' => 0,
        ])
        ->where('dashboard.latest_order', null));
});

test('administrators receive stable empty dashboard data', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->where('dashboard.kpis', [
            'recorded_revenue' => '0.00',
            'recorded_sales' => 0,
            'pending_orders' => 0,
            'processing_orders' => 0,
            'low_stock_products' => 0,
            'out_of_stock_products' => 0,
        ])
        ->where('dashboard.needs_attention.pending_payment_reviews', 0)
        ->has('dashboard.needs_attention.payment_orders', 0)
        ->has('dashboard.needs_attention.low_stock_products', 0)
        ->has('dashboard.needs_attention.out_of_stock_products', 0)
        ->has('dashboard.recent_orders', 0));
});

test('dashboard stock warnings ignore inactive records while the inventory ledger retains them', function (int $quantity, string $status) {
    $administrator = User::factory()->administrator()->create();
    $active = Product::factory()->create();
    Inventory::factory()->for($active)->create(['quantity' => $quantity, 'reorder_level' => 5]);
    foreach ([
        Product::factory()->inactive()->create(),
        Product::factory()->for(Category::factory()->inactive())->create(),
        Product::factory()->inactive()->for(Category::factory()->inactive())->create(),
    ] as $inactive) {
        Inventory::factory()->for($inactive)->create(['quantity' => $quantity, 'reorder_level' => 5]);
    }

    $this->actingAs($administrator)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where("dashboard.kpis.{$status}_products", 1)
        ->has("dashboard.needs_attention.{$status}_products", 1)
        ->where("dashboard.needs_attention.{$status}_products.0.id", $active->id));
    $this->get(route('administration.inventory.index', ['stock' => $status]))->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 4));
})->with(['inclusive low stock' => [5, 'low_stock'], 'out of stock' => [0, 'out_of_stock']]);

test('historical forecasting fixtures do not generate operational dashboard restock warnings', function () {
    Storage::fake('local');
    $this->travelTo('2026-10-15 12:00:00');
    $administrator = User::factory()->administrator()->create();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $fixtureStock = Inventory::query()->orderBy('product_id')->get()->toArray();

    $this->actingAs($administrator)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.kpis.low_stock_products', 0)
        ->where('dashboard.kpis.out_of_stock_products', 0)
        ->has('dashboard.needs_attention.low_stock_products', 0)
        ->has('dashboard.needs_attention.out_of_stock_products', 0));
    $this->get(route('administration.inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 13)
        ->where('low_stock_count', 5)
        ->where('out_of_stock_count', 1));
    expect(Inventory::query()->orderBy('product_id')->get()->toArray())->toBe($fixtureStock);
});
