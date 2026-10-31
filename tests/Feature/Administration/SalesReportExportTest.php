<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<array{product: Product, quantity: int, price: string}>  $items
 */
function createSalesExportEntry(string $date, string $amount, array $items): Sale
{
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Verified,
        'total_amount' => $amount,
    ]);

    foreach ($items as $item) {
        OrderItem::factory()->for($order)->for($item['product'])->create([
            'quantity' => $item['quantity'],
            'price_at_time' => $item['price'],
        ]);
    }

    return Sale::factory()->for($order)->create(['sale_date' => $date, 'amount' => $amount]);
}

/**
 * @return array{components: Category, peripherals: Category, processor: Product, keyboard: Product}
 */
function createSalesExportFixture(): array
{
    $components = Category::factory()->create(['name' => 'Components']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    $processor = Product::factory()->for($components)->create(['name' => 'Ryzen 7', 'price' => '9000.00']);
    $keyboard = Product::factory()->for($peripherals)->create(['name' => 'Mechanical Keyboard', 'price' => '2000.00']);

    createSalesExportEntry('2026-09-01', '230.00', [
        ['product' => $processor, 'quantity' => 2, 'price' => '50.00'],
        ['product' => $keyboard, 'quantity' => 1, 'price' => '100.00'],
    ]);
    createSalesExportEntry('2026-09-30', '300.00', [
        ['product' => $processor, 'quantity' => 3, 'price' => '100.00'],
    ]);

    return compact('components', 'peripherals', 'processor', 'keyboard');
}

/**
 * @return list<list<string|null>>
 */
function salesExportCsvRows(string $content): array
{
    expect($content)->toStartWith("\xEF\xBB\xBF");
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

/**
 * Inspect actual PDF content streams, including the embedded Unicode text.
 */
function salesExportPdfStreams(string $content): string
{
    expect($content)->toStartWith('%PDF-')->toContain('%%EOF');
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $matches);

    return implode('', array_map(static fn (string $stream): string => @gzuncompress($stream) ?: $stream, $matches[1]));
}

test('sales document endpoints redirect guests and forbid customers', function (string $format) {
    $this->get(route('administration.reports.sales.'.$format))->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->customer()->create())
        ->get(route('administration.reports.sales.'.$format))->assertForbidden();
})->with(['print', 'pdf', 'csv']);

test('print reports contain authoritative KPIs and complete sale backed breakdowns without the application shell', function () {
    $this->travelTo('2026-10-12 09:30:00');
    $fixture = createSalesExportFixture();
    createSalesExportEntry('2026-08-31', '999.00', [
        ['product' => $fixture['keyboard'], 'quantity' => 9, 'price' => '111.00'],
    ]);
    createSalesExportEntry('2026-10-01', '999.00', [
        ['product' => $fixture['keyboard'], 'quantity' => 9, 'price' => '111.00'],
    ]);
    OrderItem::factory()->for($fixture['keyboard'])->create(['quantity' => 99, 'price_at_time' => '999.00']);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.print', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month',
        ]));

    $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('Battlefront Computer Trading')->assertSee('Oct 12, 2026, 5:30 PM Asia/Manila')
        ->assertSee('2026-09-01 to 2026-09-30')->assertSee('₱500.00')->assertSee('₱250.00')
        ->assertSee('Sales by reporting period')->assertSee('Category sales breakdown')->assertSee('Product sales breakdown')
        ->assertSee('Ryzen 7')->assertSee('Mechanical Keyboard')->assertSee('window.print()', false)
        ->assertDontSee('<nav', false)->assertDontSee('<button', false)->assertDontSee('<canvas', false)
        ->assertDontSee('Recommendation engagement')->assertDontSee('₱999.00')
        ->assertViewHas('kpis', [
            'total_sales' => 2, 'total_revenue' => '500.00', 'average_order_value' => '250.00', 'total_items_sold' => 6,
            'sales_returns' => '0.00', 'net_sales' => '500.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00',
            'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false,
        ])
        ->assertViewHas('timeline', ['labels' => ['Sep 2026'], 'sales' => [2], 'revenue' => [500.0], 'gross_profit_before_returns' => [null]])
        ->assertViewHas('categories', [
            ['id' => $fixture['components']->id, 'name' => 'Components', 'sales_count' => 2, 'quantity_sold' => 5, 'item_revenue' => '400.00', 'cost_of_goods_sold' => '0.00', 'gross_profit_before_returns' => null, 'gross_margin_before_returns' => null],
            ['id' => $fixture['peripherals']->id, 'name' => 'Peripherals', 'sales_count' => 1, 'quantity_sold' => 1, 'item_revenue' => '100.00', 'cost_of_goods_sold' => '0.00', 'gross_profit_before_returns' => null, 'gross_margin_before_returns' => null],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

test('PDF downloads contain a genuine PDF with the report title Unicode names and peso amounts', function () {
    $product = Product::factory()->create(['name' => 'Niño Élite Keyboard']);
    createSalesExportEntry('2026-09-12', '125.50', [['product' => $product, 'quantity' => 2, 'price' => '62.75']]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month']));

    $response->assertOk()->assertDownload('sales-report_2026-09-01_to_2026-09-30.pdf')
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $streams = salesExportPdfStreams($response->getContent());
    expect($streams)->toContain(mb_convert_encoding('Battlefront Computer Trading', 'UTF-16BE'))
        ->toContain(mb_convert_encoding('Niño Élite Keyboard', 'UTF-16BE'))
        ->toContain(mb_convert_encoding('₱125.50', 'UTF-16BE'))
        ->not->toContain('window.print');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

test('CSV downloads contain product totals purchase time revenue and applied filter metadata', function () {
    createSalesExportFixture();

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.csv', ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'week']));

    $response->assertOk()->assertStreamed()->assertDownload('sales-report_2026-09-01_to_2026-09-30.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
    $content = $response->streamedContent();
    expect($content)->toEndWith("\r\n");
    expect(salesExportCsvRows($content))->toBe([
        ['Product', 'Current category', 'Number of sales', 'Quantity sold', 'Item revenue (PHP, excluding third-party delivery charges)', 'Cost of goods sold (PHP)', 'Gross profit before returns (PHP)', 'Gross margin before returns (%)', 'Sales returns (PHP)', 'Net product sales (PHP)', 'Gross profit (PHP)', 'Gross margin (%)', 'Report from', 'Report to', 'Reporting period', 'Product filter', 'Category filter'],
        ['Ryzen 7', 'Components', '2', '5', '400.00', '0.00', '', '', '', '', '', '', '2026-09-01', '2026-09-30', 'week', 'All products', 'All categories'],
        ['Mechanical Keyboard', 'Peripherals', '1', '1', '100.00', '0.00', '', '', '', '', '', '', '2026-09-01', '2026-09-30', 'week', 'All products', 'All categories'],
    ]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

test('CSV omits profitability fields when the administrator lacks the profit permission', function () {
    $managerRoleId = DB::table('administration_roles')->where('code', 'manager')->value('id');
    $profitPermissionId = DB::table('administration_permissions')->where('code', 'reports.profit')->value('id');
    DB::table('administration_role_permissions')
        ->where('administration_role_id', $managerRoleId)
        ->where('administration_permission_id', $profitPermissionId)
        ->delete();
    DB::table('administration_role_permissions')->insertOrIgnore([
        'administration_role_id' => $managerRoleId,
        'administration_permission_id' => DB::table('administration_permissions')->where('code', 'reports.export')->value('id'),
    ]);
    $manager = User::factory()->administrator()->create(['administration_role_id' => $managerRoleId]);
    $fixture = createSalesExportFixture();

    $response = $this->actingAs($manager)->get(route('administration.reports.sales.csv', [
        'from' => '2026-09-01', 'to' => '2026-09-30',
    ]));
    $rows = salesExportCsvRows($response->streamedContent());

    expect($rows[0])->not->toContain('Cost of goods sold (PHP)')
        ->and($rows[1])->toHaveCount(10)
        ->and($rows[1][0])->toBe('Ryzen 7');
});

test('exports restore saved selections and exclude snapshotted LBC fees without changing remembered filters', function (string $format) {
    $this->travelTo('2026-09-17 10:00:00');
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '80.00']);
    $administrator = User::factory()->administrator()->create();
    $components = Category::factory()->create(['name' => 'Components']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    $processor = Product::factory()->for($components)->create(['name' => 'Processor', 'price' => '9000.00']);
    $keyboard = Product::factory()->for($peripherals)->create(['name' => 'Keyboard', 'price' => '2000.00']);
    $order = Order::factory()->withDeliverySnapshot(subtotal: '200.00')
        ->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    OrderItem::factory()->for($order)->for($processor)->create(['quantity' => 2, 'price_at_time' => '50.00']);
    OrderItem::factory()->for($order)->for($keyboard)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $sale = Sale::factory()->for($order)->create(['sale_date' => '2026-09-12', 'amount' => '280.00']);
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '150.00']);
    $filters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => null, 'category_id' => null];
    $key = 'administration.sales_report_filters.'.$administrator->id;
    $this->actingAs($administrator)->get(route('administration.reports.sales', $filters))
        ->assertSessionHas($key, $filters)
        ->assertInertia(fn (Assert $page) => $page->where('kpis.total_revenue', '200.00')->where('timeline.revenue', [200]));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('dashboard', fn (Assert $page) => $page->where('dashboard.kpis.recorded_revenue', '200.00')));

    $response = $this->get(route('administration.reports.sales.'.$format, ['page' => 2]))->assertSessionHas($key, $filters);

    if ($format === 'print') {
        $response->assertViewHas('filters', $filters)
            ->assertViewHas('kpis', ['total_sales' => 1, 'total_revenue' => '200.00', 'average_order_value' => '200.00', 'total_items_sold' => 3, 'sales_returns' => '0.00', 'net_sales' => '200.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00', 'gross_profit' => null, 'gross_margin' => null, 'cost_data_complete' => false])
            ->assertViewHas('timeline', ['labels' => ['Sep 2026'], 'sales' => [1], 'revenue' => [200.0], 'gross_profit_before_returns' => [null]])
            ->assertSee('exclude third-party delivery charges')->assertDontSee('₱280.00');
    } elseif ($format === 'pdf') {
        $response->assertDownload('sales-report_2026-09-01_to_2026-09-30.pdf');
        expect(salesExportPdfStreams($response->getContent()))
            ->toContain(mb_convert_encoding('₱200.00', 'UTF-16BE'))
            ->not->toContain(mb_convert_encoding('₱280.00', 'UTF-16BE'));
    } else {
        $response->assertDownload('sales-report_2026-09-01_to_2026-09-30.csv');
        $rows = salesExportCsvRows($response->streamedContent());
        expect($rows[0][4])->toBe('Item revenue (PHP, excluding third-party delivery charges)');
        expect(array_slice($rows, 1))->toBe([
            ['Processor', 'Components', '1', '2', '100.00', '0.00', '', '', '', '', '', '', '2026-09-01', '2026-09-30', 'month', 'All products', 'All categories'],
            ['Keyboard', 'Peripherals', '1', '1', '100.00', '0.00', '', '', '', '', '', '', '2026-09-01', '2026-09-30', 'month', 'All products', 'All categories'],
        ]);
    }

    $selection = array_replace($filters, ['product_id' => $processor->id]);
    $explicit = $this->get(route('administration.reports.sales.'.$format, $selection))->assertSessionHas($key, $filters);
    if ($format === 'print') {
        $explicit->assertViewHas('filters', $selection)->assertViewHas('kpis.total_revenue', '100.00');
    } elseif ($format === 'pdf') {
        expect(salesExportPdfStreams($explicit->getContent()))->toContain(mb_convert_encoding('₱100.00', 'UTF-16BE'));
    } else {
        $rows = salesExportCsvRows($explicit->streamedContent());
        expect($rows)->toHaveCount(2);
        expect($rows[1])->toBe(['Processor', 'Components', '1', '2', '100.00', '0.00', '', '', '', '', '', '', '2026-09-01', '2026-09-30', 'month', 'Processor', 'All categories']);
    }
    $this->get(route('administration.reports.sales'))->assertInertia(fn (Assert $page) => $page->where('filters', $filters)->where('kpis.total_revenue', '200.00'));
    expect($sale->refresh()->amount)->toBe('280.00');
    expect($order->refresh())->product_subtotal->toBe('200.00')->delivery_fee->toBe('80.00')->total_amount->toBe('280.00');
})->with(['print', 'pdf', 'csv']);

test('print PDF and CSV agree on product category and combined filters', function (array $selection, array $expectedKpis, array $expectedRows) {
    $fixture = createSalesExportFixture();
    $query = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month'];
    foreach ($selection as $field => $record) {
        $query[$field] = $fixture[$record]->id;
    }
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.reports.sales.print', $query))
        ->assertViewHas('kpis', fn (array $kpis): bool => array_intersect_key($kpis, $expectedKpis) === $expectedKpis)
        ->assertViewHas('timeline', fn (array $timeline): bool => $timeline['labels'] === ['Sep 2026']
            && $timeline['sales'] === [$expectedKpis['total_sales']]
            && $timeline['revenue'] === [(float) $expectedKpis['total_revenue']])
        ->assertViewHas('categories', fn (array $rows): bool => array_sum(array_column($rows, 'quantity_sold')) === $expectedKpis['total_items_sold']);
    $csv = $this->get(route('administration.reports.sales.csv', $query));
    $rows = array_slice(salesExportCsvRows($csv->streamedContent()), 1);
    expect(array_map(fn (array $row): array => array_slice($row, 0, 5), $rows))->toBe($expectedRows);
    $pdf = $this->get(route('administration.reports.sales.pdf', $query));
    expect(salesExportPdfStreams($pdf->getContent()))
        ->toContain(mb_convert_encoding('₱'.$expectedKpis['total_revenue'], 'UTF-16BE'));
})->with([
    'product' => [['product_id' => 'processor'], ['total_sales' => 2, 'total_revenue' => '400.00', 'average_order_value' => '200.00', 'total_items_sold' => 5], [['Ryzen 7', 'Components', '2', '5', '400.00']]],
    'category' => [['category_id' => 'peripherals'], ['total_sales' => 1, 'total_revenue' => '100.00', 'average_order_value' => '100.00', 'total_items_sold' => 1], [['Mechanical Keyboard', 'Peripherals', '1', '1', '100.00']]],
    'combined' => [['product_id' => 'processor', 'category_id' => 'components'], ['total_sales' => 2, 'total_revenue' => '400.00', 'average_order_value' => '200.00', 'total_items_sold' => 5], [['Ryzen 7', 'Components', '2', '5', '400.00']]],
    'incompatible' => [['product_id' => 'processor', 'category_id' => 'peripherals'], ['total_sales' => 0, 'total_revenue' => '0.00', 'average_order_value' => '0.00', 'total_items_sold' => 0], []],
]);

test('documents preserve daily weekly and monthly buckets at inclusive boundaries', function (string $period, array $labels, array $sales, array $revenue) {
    $fixture = createSalesExportFixture();
    createSalesExportEntry('2026-08-31', '990.00', [['product' => $fixture['processor'], 'quantity' => 1, 'price' => '990.00']]);
    createSalesExportEntry('2026-10-01', '990.00', [['product' => $fixture['processor'], 'quantity' => 1, 'price' => '990.00']]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.print', ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => $period]));

    $response->assertViewHas('kpis.total_revenue', '500.00')
        ->assertViewHas('timeline', fn (array $timeline): bool => $timeline['labels'] === $labels
            && $timeline['sales'] === $sales && $timeline['revenue'] === $revenue);
    $csv = $this->get(route('administration.reports.sales.csv', ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => $period]));
    expect(array_column(array_slice(salesExportCsvRows($csv->streamedContent()), 1), 4))->toBe(['400.00', '100.00']);
})->with([
    'day' => ['day', array_map(fn (int $day): string => 'Sep '.$day.', 2026', range(1, 30)), [1, ...array_fill(0, 28, 0), 1], [200.0, ...array_fill(0, 28, 0.0), 300.0]],
    'week' => ['week', ['Aug 31, 2026 – Sep 6, 2026', 'Sep 7, 2026 – Sep 13, 2026', 'Sep 14, 2026 – Sep 20, 2026', 'Sep 21, 2026 – Sep 27, 2026', 'Sep 28, 2026 – Oct 4, 2026'], [1, 0, 0, 0, 1], [200.0, 0.0, 0.0, 0.0, 300.0]],
    'month' => ['month', ['Sep 2026'], [2], [500.0]],
]);

test('empty exports use the shared default dates and retain headers zero KPIs and empty messages', function () {
    $this->travelTo('2026-09-17 10:00:00');
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('administration.reports.sales.print'))
        ->assertSee('No recorded sales match these filters.')->assertSee('No product sales to report.')
        ->assertSee('No category sales to report.')->assertViewHas('kpis', [
            'total_sales' => 0, 'total_revenue' => '0.00', 'average_order_value' => '0.00', 'total_items_sold' => 0,
            'sales_returns' => '0.00', 'net_sales' => '0.00', 'cost_of_goods_sold' => '0.00', 'cost_recovered' => '0.00',
            'gross_profit' => '0.00', 'gross_margin' => null, 'cost_data_complete' => true,
        ]);
    $csv = $this->get(route('administration.reports.sales.csv'))
        ->assertDownload('sales-report_2026-08-19_to_2026-09-17.csv');
    expect(salesExportCsvRows($csv->streamedContent()))->toHaveCount(1);
    $pdf = $this->get(route('administration.reports.sales.pdf'))
        ->assertDownload('sales-report_2026-08-19_to_2026-09-17.pdf');
    expect(salesExportPdfStreams($pdf->getContent()))->toContain(mb_convert_encoding('No product sales to report.', 'UTF-16BE'));
});

test('exports reject invalid filters before returning document or file content', function (string $format, array $query, string $field, string $message) {
    $administrator = User::factory()->administrator()->create();
    $filters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'product_id' => null, 'category_id' => null];
    $key = 'administration.sales_report_filters.'.$administrator->id;
    $this->actingAs($administrator)->withSession([$key => $filters])
        ->getJson(route('administration.reports.sales.'.$format, $query))
        ->assertUnprocessable()->assertInvalid([$field => $message])->assertSessionHas($key, $filters);
})->with(['print', 'pdf', 'csv'])->with([
    'invalid date' => [['from' => '2026-02-30', 'to' => '2026-09-30'], 'from', 'Enter the start date in YYYY-MM-DD format.'],
    'reversed range' => [['from' => '2026-09-30', 'to' => '2026-09-01'], 'to', 'The end date must be on or after the start date.'],
    'missing date' => [['from' => '2026-09-01'], 'to', 'Select both a start and end date.'],
    'invalid period' => [['period' => 'quarter'], 'period', 'Select a valid reporting period.'],
    'unknown product' => [['product_id' => 999999], 'product_id', 'The selected product is unavailable.'],
    'unknown category' => [['category_id' => 999999], 'category_id', 'The selected category is unavailable.'],
    'injected product' => [['product_id' => '1 OR 1=1'], 'product_id', 'Select a valid product filter.'],
]);

test('invalid browser export filters return to the reports page with validation feedback', function (string $format) {
    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.reports.sales'))
        ->get(route('administration.reports.sales.'.$format, ['period' => 'quarter']))
        ->assertRedirectToRoute('administration.reports.sales')
        ->assertSessionHasErrors(['period' => 'Select a valid reporting period.']);
})->with(['print', 'pdf', 'csv']);

test('CSV safely round trips Unicode quotes commas newlines and backslashes', function () {
    $category = Category::factory()->create(['name' => 'Peripherals, Élite']);
    $name = "Niño \"Pro\", wired\\wireless\nKeyboard";
    $product = Product::factory()->for($category)->create(['name' => $name]);
    createSalesExportEntry('2026-09-12', '125.50', [['product' => $product, 'quantity' => 2, 'price' => '62.75']]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.csv', ['from' => '2026-09-01', 'to' => '2026-09-30']));

    $row = salesExportCsvRows($response->streamedContent())[1];
    expect(array_slice($row, 0, 5))->toBe([$name, $category->name, '1', '2', '125.50']);
    expect(array_slice($row, 5, 7))->toBe(['0.00', '', '', '', '', '', '']);
    expect(array_slice($row, 12))->toBe(['2026-09-01', '2026-09-30', 'day', 'All products', 'All categories']);
});

test('CSV neutralizes spreadsheet formulas in product category and filter text', function (string $prefix) {
    $name = $prefix.'SUM(1,1)';
    $category = Category::factory()->create(['name' => $name]);
    $product = Product::factory()->for($category)->create(['name' => $name]);
    createSalesExportEntry('2026-09-12', '42.75', [['product' => $product, 'quantity' => 1, 'price' => '42.75']]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.csv', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => $product->id, 'category_id' => $category->id,
        ]));

    $row = salesExportCsvRows($response->streamedContent())[1];
    expect([$row[0], $row[1], $row[15], $row[16]])->toBe(array_fill(0, 4, "'".$name));
    expect(array_slice($row, 2, 3))->toBe(['1', '1', '42.75']);
})->with(['equals' => '=', 'plus' => '+', 'minus' => '-', 'at' => '@', 'tab' => "\t", 'CR' => "\r", 'LF' => "\n", 'padded' => '  =', 'control padded' => "\t =", 'unicode padded' => "\u{00a0}="]);

test('print documents escape product category and filter names as text', function () {
    $name = '<script>alert("sales")</script>';
    $category = Category::factory()->create(['name' => $name]);
    $product = Product::factory()->for($category)->create(['name' => $name]);
    createSalesExportEntry('2026-09-12', '10.00', [['product' => $product, 'quantity' => 1, 'price' => '10.00']]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.reports.sales.print', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'product_id' => $product->id, 'category_id' => $category->id,
        ]))
        ->assertSee($name)->assertDontSee($name, false);
});

test('all matching products export across CSV batches and dashboard pages with a multipage PDF', function () {
    Storage::fake('sales-report-preview');
    $category = Category::factory()->create(['name' => 'Components']);
    $products = Product::factory()->for($category)->count(201)
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Export product %03d', $sequence->index + 1)])
        ->create();
    createSalesExportEntry('2026-09-12', '2010.00', $products->map(fn (Product $product): array => [
        'product' => $product, 'quantity' => 1, 'price' => '10.00',
    ])->all());
    $this->actingAs(User::factory()->administrator()->create());
    $query = ['from' => '2026-09-01', 'to' => '2026-09-30', 'period' => 'month', 'page' => 2];

    $csv = $this->get(route('administration.reports.sales.csv', $query));
    $rows = array_slice(salesExportCsvRows($csv->streamedContent()), 1);
    expect($rows)->toHaveCount(201);
    expect(array_column($rows, 0))->toBe($products->pluck('name')->all());
    $print = $this->get(route('administration.reports.sales.print', $query));
    $document = new DOMDocument;
    @$document->loadHTML($print->getContent());
    expect((new DOMXPath($document))->query('//table[@aria-label="Product sales breakdown"]/tbody/tr')->length)->toBe(201);
    $pdf = $this->get(route('administration.reports.sales.pdf', $query));
    $streams = salesExportPdfStreams($pdf->getContent());
    expect($streams)->toContain(mb_convert_encoding($products->first()->name, 'UTF-16BE'))
        ->toContain(mb_convert_encoding($products->last()->name, 'UTF-16BE'));
    preg_match_all('~/Type\s*/Page\b~', $pdf->getContent(), $pages);
    expect(count($pages[0]))->toBeGreaterThan(1);
    expect(substr_count($streams, mb_convert_encoding('Current category', 'UTF-16BE')))->toBeGreaterThan(2);
    Storage::disk('sales-report-preview')->put('sales-report-preview.pdf', $pdf->getContent());
});

test('exports leave sales orders items inventory and forecasts unchanged', function () {
    createSalesExportFixture();
    $administrator = User::factory()->administrator()->create();
    $tables = ['sales', 'orders', 'order_items', 'inventories', 'forecasts'];
    $before = array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables);

    $this->actingAs($administrator);
    foreach (['print', 'pdf', 'csv'] as $format) {
        $response = $this->get(route('administration.reports.sales.'.$format, ['from' => '2026-09-01', 'to' => '2026-09-30']));
        if ($format === 'csv') {
            $response->streamedContent();
        }
    }

    expect(array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(), $tables))->toEqual($before);
});

test('CSV query counts grow by aggregate batches rather than individual products or sales', function () {
    $fixture = createSalesExportFixture();
    $this->actingAs(User::factory()->administrator()->create());
    $query = ['from' => '2026-09-01', 'to' => '2026-09-30'];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get(route('administration.reports.sales.csv', $query))->streamedContent();
    $initialQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $moreProducts = Product::factory()->for($fixture['components'])->count(25)->create();
    createSalesExportEntry('2026-09-15', '250.00', $moreProducts->map(fn (Product $product): array => [
        'product' => $product, 'quantity' => 1, 'price' => '10.00',
    ])->all());

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get(route('administration.reports.sales.csv', $query))->streamedContent();
    $expandedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expandedQueries)->toBe($initialQueries);
});
