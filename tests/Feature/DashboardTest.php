<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentCustomerSeeder;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

beforeEach(fn () => $this->withoutVite());

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('customers are welcomed once and welcomed back after repeat visits and signing in again', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
    $this->assertDatabaseHas('users', ['id' => $customer->id, 'has_visited_dashboard' => false]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->where('is_first_visit', true)
        ->missing('auth.user.has_visited_dashboard'));

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'has_visited_dashboard' => true]);
    $this->assertDatabaseHas('users', ['id' => $otherCustomer->id, 'has_visited_dashboard' => false]);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', false));

    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->post(route('login.store'), [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', false));
    $this->actingAs($otherCustomer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', true));
});

test('seeded demo customers are welcomed once and reseeding preserves their visit', function (string $seeder, string $email) {
    $this->seed($seeder);
    $customer = User::query()->where('email', $email)->firstOrFail();

    $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', true));
    $this->seed($seeder);

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'has_visited_dashboard' => true]);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', false));
})->with([
    'default demo account' => [DatabaseSeeder::class, 'test@example.com'],
    'development demo roster' => [DevelopmentCustomerSeeder::class, 'demo.customer01@example.test'],
]);

test('dashboard prefetches and head requests do not consume the first visit', function (string $method, array $headers) {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->call($method, route('dashboard'), server: $headers)->assertOk();

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'has_visited_dashboard' => false]);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', true));
})->with([
    'prefetch' => ['GET', ['HTTP_PURPOSE' => 'prefetch']],
    'head' => ['HEAD', []],
]);

test('dashboard partial requests without the greeting do not consume the first visit', function () {
    $customer = User::factory()->customer()->create();
    $initial = $this->actingAs($customer)->get(route('products.index'));

    $this->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Customer',
        'X-Inertia-Partial-Data' => 'dashboard',
    ])->assertOk()->assertJsonMissingPath('props.is_first_visit');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'has_visited_dashboard' => false]);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('is_first_visit', true));
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
        ->where('is_first_visit', true)
        ->where('auth.can.accessAdministration', false)
        ->missing('dashboard')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
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
            ->missing('dashboard.recent_orders')));
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
        ->missing('is_first_visit')
        ->missing('dashboard')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
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
            ->missing('dashboard.latest_order')
            ->missing('is_first_visit')));

    $this->assertDatabaseHas('users', ['id' => $administrator->id, 'has_visited_dashboard' => false]);
});

test('administrator dashboards load delivered COD orders with their collection labels', function (?string $collectedAt, string $label) {
    $administrator = User::factory()->administrator()->create();
    $orders = Order::factory()->cashOnDelivery()->count(2)->create([
        'status' => OrderStatus::Processing,
    ]);
    foreach ($orders as $order) {
        Shipment::factory()->for($order)->create([
            'carrier' => 'lbc',
            'status' => ShipmentStatus::Delivered,
            'carrier_cod_collected_at' => $collectedAt,
        ]);
    }

    $response = $this->actingAs($administrator)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis.processing_orders', 2)
            ->where('dashboard.needs_attention.pending_payment_reviews', 2)
            ->has('dashboard.needs_attention.payment_orders', 2)
            ->where('dashboard.needs_attention.payment_orders.0.payment_method', $label)
            ->has('dashboard.recent_orders', 2)
            ->where('dashboard.recent_orders.0.payment_status.label', $label)));
})->with([
    'awaiting collection' => [null, 'COD — Awaiting Collection'],
    'awaiting remittance' => ['2026-09-20 12:00:00', 'COD — Awaiting LBC Remittance'],
]);

test('administrator dashboards load pending COD orders without shipments', function () {
    $administrator = User::factory()->administrator()->create();
    Order::factory()->cashOnDelivery()->count(2)->create();

    $response = $this->actingAs($administrator)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis.pending_orders', 2)
            ->where('dashboard.needs_attention.pending_payment_reviews', 0)
            ->has('dashboard.needs_attention.payment_orders', 0)
            ->has('dashboard.recent_orders', 2)
            ->where('dashboard.recent_orders.0.payment_status.label', 'COD — Awaiting Collection')));
});

test('customer dashboards show collected COD payments awaiting remittance', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->cashOnDelivery()->create([
        'status' => OrderStatus::Processing,
    ]);
    Shipment::factory()->for($order)->create([
        'carrier' => 'lbc',
        'status' => ShipmentStatus::Delivered,
        'carrier_cod_collected_at' => '2026-09-20 12:00:00',
    ]);

    $response = $this->actingAs($customer)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.latest_order.status.label', 'Delivered — awaiting LBC remittance')
            ->where('dashboard.latest_order.payment.status.label', 'COD — Awaiting LBC Remittance')));
});

test('customers receive stable empty dashboard data', function () {
    $customer = User::factory()->customer()->create();

    $response = $this
        ->actingAs($customer)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Customer')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.summary', [
                'cart_items' => 0,
                'cart_units' => 0,
                'active_orders' => 0,
                'total_orders' => 0,
            ])
            ->where('dashboard.latest_order', null)));
});

test('administrators receive stable empty dashboard data', function () {
    $administrator = User::factory()->administrator()->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis', [
                'recorded_revenue' => '0.00',
                'recorded_sales' => 0,
                'recorded_gross_profit' => '0.00',
                'profit_data_complete' => true,
                'pending_orders' => 0,
                'processing_orders' => 0,
                'low_stock_products' => 0,
                'out_of_stock_products' => 0,
            ])
            ->where('dashboard.needs_attention.pending_payment_reviews', 0)
            ->has('dashboard.needs_attention.payment_orders', 0)
            ->has('dashboard.needs_attention.low_stock_products', 0)
            ->has('dashboard.needs_attention.out_of_stock_products', 0)
            ->has('dashboard.recent_orders', 0)));
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
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where("dashboard.kpis.{$status}_products", 1)
            ->has("dashboard.needs_attention.{$status}_products", 1)
            ->where("dashboard.needs_attention.{$status}_products.0.id", $active->id)));
    $this->get(route('administration.inventory.index', ['stock' => $status]))->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 4));
})->with(['inclusive low stock' => [5, 'low_stock'], 'out of stock' => [0, 'out_of_stock']]);

test('historical seeding preserves operational dashboard stock warnings', function () {
    Storage::fake('local');
    $this->travelTo('2026-10-15 12:00:00');
    $administrator = User::factory()->administrator()->create();
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $products['10003']->inventory->update(['quantity' => 1]);
    $products['10035']->inventory->update(['quantity' => 0]);
    $stockBefore = Inventory::query()->orderBy('product_id')->get()->toArray();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $this->actingAs($administrator)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis.low_stock_products', 1)
            ->where('dashboard.kpis.out_of_stock_products', 1)
            ->has('dashboard.needs_attention.low_stock_products', 1)
            ->has('dashboard.needs_attention.out_of_stock_products', 1)));
    $this->get(route('administration.inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 15)
        ->where('low_stock_count', 1)
        ->where('out_of_stock_count', 1));
    expect(Inventory::query()->orderBy('product_id')->get()->toArray())->toBe($stockBefore);
});

test('administrator dashboard hides metrics and data from ungranted business areas', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $manager->administration_role_id)
        ->whereIn('administration_permission_id', DB::table('administration_permissions')
            ->whereIn('code', ['orders.view', 'inventory.view'])->select('id'))
        ->delete();

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewOrders', false)
            ->where('auth.can.viewInventory', false)
            ->loadDeferredProps('dashboard', fn (Assert $page) => $page
                ->where('dashboard.kpis.pending_orders', null)
                ->where('dashboard.kpis.low_stock_products', null)
                ->has('dashboard.recent_orders', 0)
                ->has('dashboard.needs_attention.payment_orders', 0)
                ->has('dashboard.needs_attention.low_stock_products', 0)));
});

test('active administration roles receive their own dashboard without redirects', function (string $role, string $component) {
    $user = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->missing('dashboard')
        ->missing('recommendations')
        ->missing('is_first_visit'));
    $this->assertDatabaseHas('users', ['id' => $user->id, 'has_visited_dashboard' => false]);
})->with([
    'Staff' => ['staff', 'Dashboard/Staff'],
    'IT' => ['it', 'Dashboard/It'],
    'Manager' => ['manager', 'Dashboard/Administration'],
    'Owner' => ['owner', 'Dashboard/Administration'],
]);

test('staff receive only operational counts even when report permissions are granted', function () {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    foreach (['reports.view', 'reports.profit'] as $permission) {
        DB::table('administration_role_permissions')->insert([
            'administration_role_id' => $staff->administration_role_id,
            'administration_permission_id' => DB::table('administration_permissions')->where('code', $permission)->value('id'),
        ]);
    }
    Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Pending,
        'payment_status' => PaymentStatus::Pending,
    ]);
    Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Cancelled,
        'payment_status' => PaymentStatus::Pending,
    ]);
    Order::factory()->paidWithGCash()->create([
        'status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Verified,
    ]);
    Inventory::factory()->create(['quantity' => 5, 'reorder_level' => 5]);
    Inventory::factory()->create(['quantity' => 0, 'reorder_level' => 5]);
    Inventory::factory()->create(['quantity' => 10, 'reorder_level' => 5]);
    Inventory::factory()->for(Product::factory()->inactive())->create(['quantity' => 0]);
    Inventory::factory()->for(Product::factory()->for(Category::factory()->inactive()))
        ->create(['quantity' => 1, 'reorder_level' => 5]);

    $this->actingAs($staff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Staff')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard', [
                'pending_orders' => 1,
                'pending_payment_reviews' => 1,
                'low_stock_products' => 1,
                'out_of_stock_products' => 1,
            ])));
});

test('staff dashboard omits operational counts when their permissions are removed', function (string $permission) {
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $staff->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', $permission)->value('id'))
        ->delete();
    Order::factory()->paidWithGCash()->create(['status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Pending]);
    Inventory::factory()->create(['quantity' => 1, 'reorder_level' => 5]);

    $this->actingAs($staff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Staff')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard', [
                'pending_orders' => $permission === 'orders.view' ? null : 1,
                'pending_payment_reviews' => $permission === 'orders.view' ? null : 1,
                'low_stock_products' => $permission === 'inventory.view' ? null : 1,
                'out_of_stock_products' => $permission === 'inventory.view' ? null : 0,
            ])));
})->with(['orders.view', 'inventory.view']);

test('managers without report access keep their dashboard and authorized operational data', function () {
    $manager = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id'),
    ]);
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $manager->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'reports.view')->value('id'))
        ->delete();
    Order::factory()->paidWithGCash()->create(['status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Pending]);
    Inventory::factory()->create(['quantity' => 1, 'reorder_level' => 5]);

    $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Administration')
        ->where('auth.can.viewReports', false)
        ->where('auth.can.viewProfitReports', true)
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis.recorded_revenue', null)
            ->where('dashboard.kpis.recorded_sales', null)
            ->where('dashboard.kpis.recorded_gross_profit', null)
            ->where('dashboard.kpis.profit_data_complete', null)
            ->where('dashboard.kpis.pending_orders', 1)
            ->where('dashboard.kpis.low_stock_products', 1)
            ->has('dashboard.recent_orders', 1)));
});

test('administrators with no module permissions can still open their assigned dashboard', function (string $role, string $component) {
    $user = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id'),
    ]);
    DB::table('administration_role_permissions')->where('administration_role_id', $user->administration_role_id)->delete();

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard', fn ($data): bool => collect($data)->flatten()->every(fn ($value): bool => $value === null))));
})->with([
    'Staff' => ['staff', 'Dashboard/Staff'],
    'Manager' => ['manager', 'Dashboard/Administration'],
]);

test('IT dashboard never supplies business data even when explicitly requested', function () {
    $it = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'it')->value('id'),
    ]);
    Order::factory()->create();
    Inventory::factory()->create();
    Sale::factory()->create();

    $initial = $this->actingAs($it)->get(route('dashboard'));
    $initial->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/It')
        ->where('auth.can.manageUsers', true)
        ->where('auth.can.viewAudit', true)
        ->where('auth.can.viewReports', false)
        ->where('auth.can.viewOrders', false)
        ->where('auth.can.viewInventory', false)
        ->missing('dashboard')
        ->missing('recommendations')
        ->missing('cartQuantity'));
    expect($initial->viewData('page'))->not->toHaveKey('deferredProps');

    $this->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Administration',
        'X-Inertia-Partial-Data' => 'dashboard,recommendations',
    ])->assertJsonPath('component', 'Dashboard/It')
        ->assertJsonMissingPath('props.dashboard')
        ->assertJsonMissingPath('props.recommendations');
});

test('inactive and unassigned administrators cannot load dashboard data', function (array $attributes) {
    $user = User::factory()->administrator()->create($attributes);

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
})->with([
    'inactive' => [['is_active' => false]],
    'unassigned' => [['administration_role_id' => null]],
]);
