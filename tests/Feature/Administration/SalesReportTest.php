<?php

use App\Actions\Order\RecordCompletedOrderSale;
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
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

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

test('sales report separates sale dates from refund payment dates and calculates balance at cutoff', function () {
    $owner = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $sale = createSalesReportEntry('2026-09-05', '100.00', [
        ['product' => $product, 'quantity' => 1, 'price' => '100.00'],
    ]);
    $itemId = DB::table('order_items')->where('order_id', $sale->order_id)->value('id');
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $sale->order_id, 'requester_id' => $owner->id,
        'status' => 'accepted', 'reason' => 'Verified return',
        'created_at' => '2026-09-06 10:00:00', 'updated_at' => '2026-09-06 10:00:00',
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId, 'order_item_id' => $itemId,
        'quantity' => 1, 'condition' => 'opened', 'disposition' => 'write_off',
    ]);
    $refundId = DB::table('customer_refunds')->insertGetId([
        'customer_return_id' => $returnId, 'approved_by' => $owner->id,
        'approved_amount' => '50.00', 'paid_amount' => '20.00',
        'created_at' => '2026-09-06 10:00:00', 'updated_at' => '2026-10-02 10:00:00',
    ]);
    foreach ([
        ['20.00', '2026-09-20 10:00:00', 'voided'],
        ['10.00', '2026-09-28 10:00:00', 'verified'],
        ['10.00', '2026-10-02 10:00:00', null],
    ] as [$amount, $date, $decision]) {
        $paymentId = DB::table('customer_refund_payments')->insertGetId([
            'customer_refund_id' => $refundId, 'recorded_by' => $owner->id,
            'amount' => $amount, 'payment_method' => 'cash', 'external_reference' => $date,
            'paid_at' => $date,
        ]);
        if ($decision !== null) {
            DB::table('customer_refund_payment_reviews')->insert([
                'customer_refund_payment_id' => $paymentId, 'reviewed_by' => $owner->id,
                'decision' => $decision, 'reason' => $decision === 'voided' ? 'Disputed duplicate record' : 'Checked against cash book',
                'created_at' => $decision === 'voided' ? '2026-09-25 10:00:00' : '2026-09-29 10:00:00',
            ]);
        }
    }

    $this->actingAs($owner)->get(route('administration.reports.sales', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('refund_measures.gross_item_sales', '100.00')
            ->where('refund_measures.refund_payments', '10.00')
            ->where('refund_measures.gross_less_refunds', '90.00')
            ->where('refund_measures.outstanding_at_cutoff', '40.00')
            ->where('refund_measures.payments.total', 1)
            ->where('refund_measures.reversals.total', 1)
            ->where('refund_measures.reversals.data.0.amount', 20)
            ->where('refund_measures.outstanding_refunds.total', 1)
            ->where('sale_transactions.total', 1));

    $unrelated = Product::factory()->create();
    $this->actingAs($owner)->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => $unrelated->id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('refund_measures.gross_item_sales', '0.00')
        ->where('refund_measures.refund_payments', '0.00')
        ->where('refund_measures.outstanding_at_cutoff', '0.00'));
});

test('sales reports retain historical product revenue after product deletion', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Retired Graphics Card']);
    createSalesReportEntry('2026-09-05', '2500.00', [
        ['product' => $product, 'quantity' => 2, 'price' => '1250.00'],
    ]);
    $product->delete();

    $this->actingAs($administrator)
        ->get(route('administration.reports.sales', [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'period' => 'month',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.total_sales', 1)
            ->where('kpis.total_revenue', '2500.00')
            ->where('kpis.total_items_sold', 2)
            ->where('top_products.labels', ['Retired Graphics Card'])
            ->where('products.data.0.name', 'Retired Graphics Card')
            ->where('products.data.0.item_revenue', '2500.00'));
});

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
            'sales_returns' => '0.00',
            'net_sales' => '0.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => '0.00',
            'gross_margin' => null,
            'cost_data_complete' => true,
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
            'sales_returns' => '0.00',
            'net_sales' => '500.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => null,
            'gross_margin' => null,
            'cost_data_complete' => false,
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
            'cost_of_goods_sold' => '0.00',
            'gross_profit_before_returns' => null,
            'gross_margin_before_returns' => null,
        ])
        ->where('products.data.1', [
            'id' => $keyboard->id,
            'name' => 'Mechanical Keyboard',
            'category' => 'Peripherals',
            'sales_count' => 1,
            'quantity_sold' => 1,
            'item_revenue' => '100.00',
            'cost_of_goods_sold' => '0.00',
            'gross_profit_before_returns' => null,
            'gross_margin_before_returns' => null,
        ])
        ->where('categories.rows.0', [
            'id' => $components->id,
            'name' => 'Components',
            'sales_count' => 2,
            'quantity_sold' => 5,
            'item_revenue' => '400.00',
            'cost_of_goods_sold' => '0.00',
            'gross_profit_before_returns' => null,
            'gross_margin_before_returns' => null,
        ])
        ->where('categories.rows.1', [
            'id' => $peripherals->id,
            'name' => 'Peripherals',
            'sales_count' => 1,
            'quantity_sold' => 1,
            'item_revenue' => '100.00',
            'cost_of_goods_sold' => '0.00',
            'gross_profit_before_returns' => null,
            'gross_margin_before_returns' => null,
        ]));
});

test('sales report calculates gross profit from the acquisition cost saved with each sale item', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['acquisition_cost' => '30.00']);
    $sale = createSalesReportEntry('2026-09-05', '100.00', [
        ['product' => $product, 'quantity' => 2, 'price' => '50.00'],
    ]);
    DB::table('order_items')->where('order_id', $sale->order_id)->update(['acquisition_cost_at_time' => '30.00']);

    $this->actingAs($administrator)
        ->get(route('administration.reports.sales', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.cost_of_goods_sold', '60.00')
            ->where('kpis.net_sales', '100.00')
            ->where('kpis.gross_profit', '40.00')
            ->where('kpis.gross_margin', '40.00')
            ->where('kpis.cost_data_complete', true)
            ->where('timeline.gross_profit_before_returns', [0, 0, 0, 0, 40, ...array_fill(0, 25, 0)])
            ->where('products.data.0.gross_profit_before_returns', '40.00'));
});

test('sales report omits profitability data when an administrator lacks the profit permission', function () {
    $managerRoleId = DB::table('administration_roles')->where('code', 'manager')->value('id');
    $profitPermissionId = DB::table('administration_permissions')->where('code', 'reports.profit')->value('id');
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $managerRoleId)
        ->where('administration_permission_id', $profitPermissionId)
        ->delete();
    $manager = User::factory()->administrator()->create(['administration_role_id' => $managerRoleId]);

    createSalesReportEntry('2026-09-05', '100.00', [
        ['product' => Product::factory()->create(['acquisition_cost' => '30.00']), 'quantity' => 2, 'price' => '50.00'],
    ]);

    $this->actingAs($manager)
        ->get(route('administration.reports.sales', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('kpis.gross_profit')
            ->missing('products.data.0.cost_of_goods_sold'));
});

test('new sale dates follow the configured Philippine business calendar', function () {
    $this->travelTo('2026-10-19 17:30:00');
    $order = Order::factory()->withDeliverySnapshot(subtotal: '100.00')->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
    ]);

    app(RecordCompletedOrderSale::class)->execute($order);

    expect($order->sale()->firstOrFail()->sale_date->toDateString())->toBe('2026-10-20');
});

test('historical and newly recorded sales use product snapshots across reporting periods', function (string $period, array $revenue, array $sales) {
    $this->travelTo('2026-09-03 10:00:00');
    $administrator = User::factory()->administrator()->create();
    $processor = Product::factory()->create(['price' => '9000.00']);
    $keyboard = Product::factory()->create(['price' => '2000.00']);
    $legacySale = createSalesReportEntry('2026-09-01', '230.00', [
        ['product' => $processor, 'quantity' => 2, 'price' => '50.00'],
        ['product' => $keyboard, 'quantity' => 1, 'price' => '100.00'],
    ]);
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '80.00']);
    $delivery = Order::factory()->withDeliverySnapshot(subtotal: '200.00')
        ->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    OrderItem::factory()->for($delivery)->for($processor)->create(['quantity' => 2, 'price_at_time' => '50.00']);
    OrderItem::factory()->for($delivery)->for($keyboard)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $historicalSale = Sale::factory()->for($delivery)->create(['sale_date' => '2026-09-02', 'amount' => '280.00']);
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '150.00']);
    $newOrder = Order::factory()->withDeliverySnapshot(subtotal: '100.00')
        ->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    OrderItem::factory()->for($newOrder)->for($processor)->create(['quantity' => 2, 'price_at_time' => '50.00']);
    app(RecordCompletedOrderSale::class)->execute($newOrder);
    $before = DB::table('sales')->orderBy('id')->get()->all();

    $this->actingAs($administrator)->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-03', 'period' => $period,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('kpis', ['total_sales' => 3, 'total_revenue' => '500.00', 'average_order_value' => '166.67', 'total_items_sold' => 8, 'sales_returns' => '0.00', 'net_sales' => '500.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00', 'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false])
        ->where('timeline.revenue', $revenue)->where('timeline.sales', $sales)
        ->where('products.data.0.item_revenue', '300.00')->where('products.data.1.item_revenue', '200.00'));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->missing('dashboard')
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page
            ->where('dashboard.kpis.recorded_revenue', '500.00')
            ->where('dashboard.kpis.recorded_sales', 3)
            ->where('dashboard.recent_orders', fn ($orders): bool => collect($orders)->firstWhere('id', $delivery->id)['total'] === '280.00')));
    expect($newOrder->refresh()->sale->amount)->toBe('100.00');
    expect($newOrder->total_amount)->toBe('250.00');
    expect($legacySale->refresh()->amount)->toBe('230.00');
    expect($historicalSale->refresh()->amount)->toBe('280.00');
    expect(DB::table('sales')->orderBy('id')->get()->all())->toEqual($before);

    $this->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-03', 'period' => $period,
        'product_id' => $processor->id, 'category_id' => $processor->category_id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('kpis', ['total_sales' => 3, 'total_revenue' => '300.00', 'average_order_value' => '100.00', 'total_items_sold' => 6, 'sales_returns' => '0.00', 'net_sales' => '300.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00', 'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false]));
    $this->get(route('administration.reports.sales', [
        'from' => '2026-09-01', 'to' => '2026-09-03', 'period' => $period, 'category_id' => $keyboard->category_id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('kpis', ['total_sales' => 2, 'total_revenue' => '200.00', 'average_order_value' => '100.00', 'total_items_sold' => 2, 'sales_returns' => '0.00', 'net_sales' => '200.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00', 'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false]));
})->with(['daily' => ['day', [200, 200, 100], [1, 1, 1]], 'weekly' => ['week', [500], [3]], 'monthly' => ['month', [500], [3]]]);

test('zero product subtotals and itemless legacy sales preserve sales counts without recognizing delivery revenue', function () {
    $administrator = User::factory()->administrator()->create();
    $zeroOrder = Order::factory()->withDeliverySnapshot(subtotal: '0.00')
        ->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    Sale::factory()->for($zeroOrder)->create(['sale_date' => '2026-09-01', 'amount' => '80.00']);
    $legacyOrder = Order::factory()->delivery()->create([
        'status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified, 'total_amount' => '100.50',
    ]);
    Sale::factory()->for($legacyOrder)->create(['sale_date' => '2026-09-02', 'amount' => '100.50']);

    $this->actingAs($administrator)->get(route('administration.reports.sales', ['from' => '2026-09-01', 'to' => '2026-09-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis', ['total_sales' => 2, 'total_revenue' => '100.50', 'average_order_value' => '50.25', 'total_items_sold' => 0, 'sales_returns' => '0.00', 'net_sales' => '100.50', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00', 'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false])
            ->where('timeline.revenue', [0, 100.5])->where('timeline.sales', [1, 1]));
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
            'sales_returns' => '0.00',
            'net_sales' => '400.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => null,
            'gross_margin' => null,
            'cost_data_complete' => false,
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
            'cost_of_goods_sold' => '0.00',
            'gross_profit_before_returns' => null,
            'gross_margin_before_returns' => null,
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
            'sales_returns' => '0.00',
            'net_sales' => '100.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => null,
            'gross_margin' => null,
            'cost_data_complete' => false,
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
            'sales_returns' => '0.00',
            'net_sales' => '500.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => null,
            'gross_margin' => null,
            'cost_data_complete' => false,
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
            'sales_returns' => '0.00',
            'net_sales' => '0.00',
            'cost_of_goods_sold' => '0.00',
            'cost_recovered' => '0.00',
            'gross_profit' => '0.00',
            'gross_margin' => null,
            'cost_data_complete' => true,
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

test('demonstration historical transactions contribute exact sales and item revenue to existing reports', function (string $scope, int $transactions, string $revenue, int $quantity) {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo('2026-10-15 12:00:00');
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $filters = ['from' => '2022-10-01', 'to' => '2026-09-30', 'period' => 'month'];
    if ($scope === 'product') {
        $filters['product_id'] = $products['10003']->id;
    } elseif ($scope === 'category') {
        $filters['category_id'] = $products['10003']->category_id;
    }

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales', $filters))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.total_sales', $transactions)
            ->where('kpis.total_revenue', $revenue)
            ->where('kpis.total_items_sold', $quantity)
            ->has('timeline.labels', 48)
            ->where('products.data.0.name', $products['10003']->name));
})->with([
    'all transactions' => ['all', 259, '829300.00', 1944],
    'product snapshots' => ['product', 72, '201600.00', 72],
    'actual catalog category' => ['category', 130, '373200.00', 150],
]);

test('demonstration monthly report counts and revenue reflect varied persisted transactions', function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo('2026-10-15 12:00:00');
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales', ['from' => '2022-10-01', 'to' => '2022-11-30', 'period' => 'month']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('timeline.labels', ['Oct 2022', 'Nov 2022'])
            ->where('timeline.sales', [4, 5])
            ->where('timeline.revenue', [17560, 19070])
            ->where('kpis.total_sales', 9)
            ->where('kpis.total_revenue', '36630.00'));
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
