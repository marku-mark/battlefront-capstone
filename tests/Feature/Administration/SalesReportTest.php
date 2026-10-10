<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecommendationInteraction;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<array{product: Product, quantity: int, price: string}>  $items
 */
function createSalesReportEntry(string $date, string $amount, array $items = []): Sale
{
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
        'total_amount' => $amount,
    ]);

    foreach ($items as $item) {
        OrderItem::factory()
            ->for($order)
            ->for($item['product'])
            ->create([
                'quantity' => $item['quantity'],
                'price_at_time' => $item['price'],
            ]);
    }

    return Sale::factory()->for($order)->create([
        'amount' => $amount,
        'sale_date' => $date,
    ]);
}

/**
 * @return array{components: Category, peripherals: Category, processor: Product, keyboard: Product}
 */
function createSalesCrossFilterFixture(): array
{
    $components = Category::factory()->create(['name' => 'Components']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    $processor = Product::factory()->for($components)->create(['name' => 'Ryzen 7']);
    $keyboard = Product::factory()->for($peripherals)->create(['name' => 'Mechanical Keyboard']);

    createSalesReportEntry('2026-09-01', '200.00', [
        ['product' => $processor, 'quantity' => 2, 'price' => '50.00'],
        ['product' => $keyboard, 'quantity' => 1, 'price' => '100.00'],
    ]);
    createSalesReportEntry('2026-09-30', '300.00', [
        ['product' => $processor, 'quantity' => 3, 'price' => '100.00'],
    ]);

    return compact('components', 'peripherals', 'processor', 'keyboard');
}

test('guests are redirected from sales reports and customers are forbidden', function () {
    $customer = User::factory()->customer()->create();

    $this->get(route('administration.reports.sales'))
        ->assertRedirectToRoute('login');
    $this->actingAs($customer)
        ->get(route('administration.reports.sales'))
        ->assertForbidden();
});

test('administrators receive zero values and empty reports when no sales exist', function () {
    $this->travelTo('2026-09-17 10:00:00');
    $administrator = User::factory()->administrator()->create();

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Reports/Sales')
        ->where('filters', [
            'from' => '2026-08-19',
            'to' => '2026-09-17',
            'period' => 'day',
            'product_id' => null,
            'category_id' => null,
        ])
        ->where('kpis', [
            'total_sales' => 0,
            'total_revenue' => '0.00',
            'average_order_value' => '0.00',
            'total_items_sold' => 0,
        ])
        ->where('top_products.labels', [])
        ->where('categories.rows', [])
        ->where('products.data', [])
        ->where('recommendation_engagement.summary', [
            'impressions' => 0,
            'clicks' => 0,
            'dismissals' => 0,
            'wrong_reports' => 0,
        ])
        ->where('recommendation_engagement.placements', [])
        ->where('recommendation_engagement.reasons', [])
        ->where('recommendation_engagement.top_clicked_products', []));
});

test('administrators see anonymous recommendation engagement by date placement reason and product', function () {
    $administrator = User::factory()->administrator()->create();
    $graphicsCard = Product::factory()->create(['name' => 'Atlas Graphics Card']);
    $keyboard = Product::factory()->create(['name' => 'Mechanical Keyboard']);

    RecommendationInteraction::factory()->for($graphicsCard)->create([
        'event_type' => 'impression',
        'placement' => 'home',
        'reason_code' => 'popular_with_customers',
        'created_at' => '2026-09-10 10:00:00',
    ]);
    RecommendationInteraction::factory()->for($graphicsCard)->create([
        'event_type' => 'click',
        'placement' => 'home',
        'reason_code' => 'popular_with_customers',
        'created_at' => '2026-09-10 10:01:00',
    ]);
    RecommendationInteraction::factory()->for($keyboard)->create([
        'event_type' => 'impression',
        'placement' => 'cart',
        'reason_code' => 'bought_with_cart_products',
        'created_at' => '2026-09-11 10:00:00',
    ]);
    RecommendationInteraction::factory()->for($keyboard)->create([
        'event_type' => 'click',
        'placement' => 'product',
        'reason_code' => 'matched_recent_searches',
        'created_at' => '2026-09-11 10:01:00',
    ]);
    RecommendationInteraction::factory()->for($graphicsCard)->create([
        'event_type' => 'dismiss',
        'placement' => 'home',
        'reason_code' => 'popular_with_customers',
        'created_at' => '2026-09-11 10:02:00',
    ]);
    RecommendationInteraction::factory()->for($keyboard)->create([
        'event_type' => 'report_wrong',
        'placement' => 'cart',
        'reason_code' => 'bought_with_cart_products',
        'created_at' => '2026-09-11 10:02:00',
    ]);
    RecommendationInteraction::factory()->for($keyboard)->create([
        'event_type' => 'click',
        'placement' => 'home',
        'created_at' => '2026-08-31 10:00:00',
    ]);
    RecommendationInteraction::factory()->for($keyboard)->create([
        'event_type' => 'click',
        'placement' => 'home',
        'created_at' => '2026-09-12 10:00:00',
        'expires_at' => now()->subSecond(),
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Reports/Sales')
        ->where('recommendation_engagement.summary', [
            'impressions' => 2,
            'clicks' => 2,
            'dismissals' => 1,
            'wrong_reports' => 1,
        ])
        ->has('recommendation_engagement.placements', 3)
        ->has('recommendation_engagement.reasons', 3)
        ->has('recommendation_engagement.top_clicked_products', 2)
        ->where('recommendation_engagement.top_clicked_products.0.product_name', 'Atlas Graphics Card'));

    $placements = collect($response->inertiaProps('recommendation_engagement.placements'))
        ->keyBy('placement');
    $reasons = collect($response->inertiaProps('recommendation_engagement.reasons'))
        ->keyBy('reason_code');

    expect($placements->get('home'))
        ->toMatchArray(['impressions' => 1, 'clicks' => 1, 'dismissals' => 1, 'wrong_reports' => 0])
        ->and($placements->get('cart'))
        ->toMatchArray(['impressions' => 1, 'clicks' => 0, 'dismissals' => 0, 'wrong_reports' => 1])
        ->and($placements->get('product'))
        ->toMatchArray(['impressions' => 0, 'clicks' => 1, 'dismissals' => 0, 'wrong_reports' => 0])
        ->and($reasons->get('matched_recent_searches'))
        ->toMatchArray(['impressions' => 0, 'clicks' => 1]);
});

test('recommendation reports label new shopping placements and retain historical events', function () {
    $administrator = User::factory()->administrator()->create();
    foreach (['dashboard', 'catalog', 'home', 'recommendations'] as $placement) {
        RecommendationInteraction::factory()->create([
            'placement' => $placement,
            'event_type' => 'click',
            'created_at' => now(),
        ]);
    }

    $response = $this->actingAs($administrator)->get(route('administration.reports.sales'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('recommendation_engagement.summary.clicks', 4)
        ->has('recommendation_engagement.placements', 4));
    $placements = collect($response->inertiaProps('recommendation_engagement.placements'))->keyBy('placement');
    expect($placements->get('dashboard'))->toMatchArray(['label' => 'Customer dashboard', 'clicks' => 1])
        ->and($placements->get('catalog'))->toMatchArray(['label' => 'Product catalog', 'clicks' => 1])
        ->and($placements->get('home'))->toMatchArray(['label' => 'Home page', 'clicks' => 1])
        ->and($placements->get('recommendations'))->toMatchArray(['label' => 'Recommendations page', 'clicks' => 1]);
});

test('recommendation engagement reporting never exposes raw customer browsing history', function () {
    CustomerSearch::factory()->create(['query' => 'private customer search phrase']);
    CustomerProductView::factory()->create(['dwell_seconds' => 1234]);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales'))->assertOk()
        ->assertDontSee('private customer search phrase')->assertDontSee('dwell_seconds')
        ->assertDontSee('guest_recommendation_profile_id');
});

test('sales KPIs and product and category reports use sale-backed order records', function () {
    $administrator = User::factory()->administrator()->create();
    $components = Category::factory()->create(['name' => 'Components']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    $processor = Product::factory()->for($components)->create(['name' => 'Ryzen 7']);
    $keyboard = Product::factory()->for($peripherals)->create(['name' => 'Mechanical Keyboard']);

    createSalesReportEntry('2026-09-01', '200.00', [
        ['product' => $processor, 'quantity' => 2, 'price' => '50.00'],
        ['product' => $keyboard, 'quantity' => 1, 'price' => '100.00'],
    ]);
    createSalesReportEntry('2026-09-30', '300.00', [
        ['product' => $processor, 'quantity' => 3, 'price' => '100.00'],
    ]);
    createSalesReportEntry('2026-08-31', '999.00', [
        ['product' => $keyboard, 'quantity' => 9, 'price' => '111.00'],
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'period' => 'month',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis', [
            'total_sales' => 2,
            'total_revenue' => '500.00',
            'average_order_value' => '250.00',
            'total_items_sold' => 6,
        ])
        ->where('timeline.labels', ['Sep 2026'])
        ->where('timeline.sales', [2])
        ->where('timeline.revenue', [500])
        ->where('top_products.labels', ['Ryzen 7', 'Mechanical Keyboard'])
        ->where('top_products.quantities', [5, 1])
        ->where('products.data.0', [
            'id' => $processor->id,
            'name' => 'Ryzen 7',
            'category' => 'Components',
            'sales_count' => 2,
            'quantity_sold' => 5,
            'item_revenue' => '400.00',
        ])
        ->where('products.data.1', [
            'id' => $keyboard->id,
            'name' => 'Mechanical Keyboard',
            'category' => 'Peripherals',
            'sales_count' => 1,
            'quantity_sold' => 1,
            'item_revenue' => '100.00',
        ])
        ->where('categories.rows.0', [
            'id' => $components->id,
            'name' => 'Components',
            'sales_count' => 2,
            'quantity_sold' => 5,
            'item_revenue' => '400.00',
        ])
        ->where('categories.rows.1', [
            'id' => $peripherals->id,
            'name' => 'Peripherals',
            'sales_count' => 1,
            'quantity_sold' => 1,
            'item_revenue' => '100.00',
        ]));
});

test('date boundaries are inclusive and adjacent dates are excluded', function () {
    $administrator = User::factory()->administrator()->create();
    createSalesReportEntry('2026-08-31', '40.00');
    createSalesReportEntry('2026-09-01', '100.00');
    createSalesReportEntry('2026-09-30', '200.00');
    createSalesReportEntry('2026-10-01', '80.00');

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis.total_sales', 2)
        ->where('kpis.total_revenue', '300.00')
        ->where('kpis.average_order_value', '150.00'));
});

test('category filters update dependent aggregates while retaining category chart context', function () {
    $administrator = User::factory()->administrator()->create();
    $fixture = createSalesCrossFilterFixture();

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'period' => 'month',
            'category_id' => $fixture['components']->id,
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('active_filters.product', null)
        ->where('active_filters.category', [
            'id' => $fixture['components']->id,
            'name' => 'Components',
        ])
        ->where('kpis', [
            'total_sales' => 2,
            'total_revenue' => '400.00',
            'average_order_value' => '200.00',
            'total_items_sold' => 5,
        ])
        ->where('timeline.sales', [2])
        ->where('timeline.revenue', [400])
        ->where('top_products.ids', [$fixture['processor']->id])
        ->where('top_products.quantities', [5])
        ->where('categories.ids', [
            $fixture['components']->id,
            $fixture['peripherals']->id,
        ])
        ->where('categories.revenue', [400, 100])
        ->where('categories.rows', [[
            'id' => $fixture['components']->id,
            'name' => 'Components',
            'sales_count' => 2,
            'quantity_sold' => 5,
            'item_revenue' => '400.00',
        ]])
        ->where('products.data.0.id', $fixture['processor']->id)
        ->where('products.total', 1));
});

test('product filters update dependent aggregates while retaining product chart context', function () {
    $administrator = User::factory()->administrator()->create();
    $fixture = createSalesCrossFilterFixture();

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'period' => 'month',
            'product_id' => $fixture['keyboard']->id,
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('active_filters.product', [
            'id' => $fixture['keyboard']->id,
            'name' => 'Mechanical Keyboard',
        ])
        ->where('active_filters.category', null)
        ->where('kpis', [
            'total_sales' => 1,
            'total_revenue' => '100.00',
            'average_order_value' => '100.00',
            'total_items_sold' => 1,
        ])
        ->where('timeline.sales', [1])
        ->where('timeline.revenue', [100])
        ->where('top_products.ids', [
            $fixture['processor']->id,
            $fixture['keyboard']->id,
        ])
        ->where('top_products.quantities', [5, 1])
        ->where('categories.ids', [$fixture['peripherals']->id])
        ->where('categories.revenue', [100])
        ->where('categories.rows.0.id', $fixture['peripherals']->id)
        ->where('products.data.0.id', $fixture['keyboard']->id)
        ->where('products.total', 1));
});

test('clearing cross filters restores the complete date-range report', function () {
    $administrator = User::factory()->administrator()->create();
    $fixture = createSalesCrossFilterFixture();

    $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'category_id' => $fixture['peripherals']->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.total_revenue', '100.00'));

    $response = $this->get(route('administration.reports.sales', [
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('active_filters', [
            'product' => null,
            'category' => null,
        ])
        ->where('kpis', [
            'total_sales' => 2,
            'total_revenue' => '500.00',
            'average_order_value' => '250.00',
            'total_items_sold' => 6,
        ])
        ->where('products.total', 2)
        ->where('categories.rows', fn ($rows): bool => $rows->count() === 2));
});

test('incompatible product and category filters return defined empty aggregates', function () {
    $administrator = User::factory()->administrator()->create();
    $fixture = createSalesCrossFilterFixture();

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'product_id' => $fixture['keyboard']->id,
            'category_id' => $fixture['components']->id,
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('kpis', [
            'total_sales' => 0,
            'total_revenue' => '0.00',
            'average_order_value' => '0.00',
            'total_items_sold' => 0,
        ])
        ->where('timeline.sales', array_fill(0, 30, 0))
        ->where('timeline.revenue', array_fill(0, 30, 0))
        ->where('top_products.ids', [$fixture['processor']->id])
        ->where('categories.ids', [$fixture['peripherals']->id])
        ->where('categories.rows', [])
        ->where('products.data', []));
});

test('daily reporting fills missing dates with zero aggregates', function () {
    $administrator = User::factory()->administrator()->create();
    createSalesReportEntry('2026-09-01', '100.00');
    createSalesReportEntry('2026-09-03', '200.00');

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-03',
            'period' => 'day',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('timeline.labels', ['Sep 1', 'Sep 2', 'Sep 3'])
        ->where('timeline.sales', [1, 0, 1])
        ->where('timeline.revenue', [100, 0, 200]));
});

test('weekly reporting groups sales into Monday-based periods', function () {
    $administrator = User::factory()->administrator()->create();
    createSalesReportEntry('2026-09-01', '100.00');
    createSalesReportEntry('2026-09-08', '200.00');
    createSalesReportEntry('2026-09-14', '300.00');

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-14',
            'period' => 'week',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('timeline.labels', [
            'Aug 31 – Sep 6',
            'Sep 7 – Sep 13',
            'Sep 14 – Sep 20',
        ])
        ->where('timeline.sales', [1, 1, 1])
        ->where('timeline.revenue', [100, 200, 300]));
});

test('invalid date and period filters are rejected with clear messages', function (array $query, string $field, string $message) {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->from(route('administration.reports.sales'))
        ->get(route('administration.reports.sales', $query))
        ->assertRedirect(route('administration.reports.sales'))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'reversed range' => [
        ['from' => '2026-09-30', 'to' => '2026-09-01'],
        'to',
        'The end date must be on or after the start date.',
    ],
    'missing end date' => [
        ['from' => '2026-09-01'],
        'to',
        'Select both a start and end date.',
    ],
    'unknown period' => [
        ['period' => 'quarter'],
        'period',
        'Select a valid reporting period.',
    ],
    'unknown product' => [
        ['product_id' => 999999],
        'product_id',
        'The selected product is unavailable.',
    ],
    'unknown category' => [
        ['category_id' => 999999],
        'category_id',
        'The selected category is unavailable.',
    ],
]);

test('product reports are paginated and preserve active filters', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
        'total_amount' => '210.00',
    ]);
    $products = Product::factory()->for($category)->count(21)->create();

    foreach ($products as $product) {
        OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => 1,
            'price_at_time' => '10.00',
        ]);
    }
    Sale::factory()->for($order)->create([
        'amount' => '210.00',
        'sale_date' => '2026-09-15',
    ]);

    $response = $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'period' => 'month',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 21)
        ->where('products.per_page', 20)
        ->where('products.last_page', 2)
        ->where('products.next_page_url', fn (string $url): bool => str_contains($url, 'from=2026-09-01')
            && str_contains($url, 'to=2026-09-30')
            && str_contains($url, 'period=month')));
});

test('report query count does not grow with the number of sales rows', function () {
    $administrator = User::factory()->administrator()->create();
    createSalesReportEntry('2026-09-10', '100.00');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($administrator)->get(route('administration.reports.sales', [
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]));
    $singleSaleQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    createSalesReportEntry('2026-09-11', '110.00');
    createSalesReportEntry('2026-09-12', '120.00');
    createSalesReportEntry('2026-09-13', '130.00');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($administrator)->get(route('administration.reports.sales', [
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ]));
    $multipleSalesQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($multipleSalesQueryCount)->toBe($singleSaleQueryCount);
});
