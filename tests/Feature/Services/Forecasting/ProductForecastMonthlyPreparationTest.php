<?php

use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Reporting\MonthlySalesRepository;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function monthlyForecastCoverage(array $overrides = []): array
{
    return array_replace([
        'granularity' => 'month',
        'start' => '2023-10-01', 'end_exclusive' => '2026-10-01',
        'unavailable_months' => [], 'timezone' => 'UTC',
        'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
    ], $overrides);
}

function monthlyForecastSale(Product $product, string $date, int $quantity): Sale
{
    $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => '1.00']);
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => $quantity, 'price_at_time' => '0.10']);

    return $sale;
}

test('prepares the latest thirty six imported product months with exact quantities and eligibility', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = $products['10003'];
    $quantities = [1, 1, 2, 1, 1, 2, 1, 1, 2, 2, 1, 1, 2, 2, 3, 1, 1, 1, 2, 1, 1, 2, 2, 1, 2, 1, 2, 2, 1, 2, 1, 2, 2, 2, 1, 2];
    $this->expectsDatabaseQueryCount(1);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe('ready');
    expect($result['covered_months'])->toBe(36);
    expect($result['positive_sales_months'])->toBe([12, 12, 12]);
    expect($result['source_period'])->toBe(['start' => '2023-10-01', 'end_exclusive' => '2026-10-01']);
    expect($result['target_period'])->toBe(['start' => '2026-10-01', 'end_exclusive' => '2027-01-01']);
    expect($result['coverage'])->toBe(monthlyForecastCoverage([
        'start' => '2022-10-01', 'source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions',
    ]));
    expect($product->is_active)->toBeTrue();
    expect($result['history']['months'])->toHaveCount(36);
    expect(array_column($result['history']['months'], 'quantity_sold'))->toBe($quantities);
    expect($result['history']['months'][0])->toBe(['year' => 2023, 'month' => 10, 'start' => '2023-10-01', 'end_exclusive' => '2023-11-01', 'quantity_sold' => 1]);
    expect($result['history']['months'][35])->toBe(['year' => 2026, 'month' => 9, 'start' => '2026-09-01', 'end_exclusive' => '2026-10-01', 'quantity_sold' => 2]);
});

test('selected real products become ready while unselected products keep their coverage states', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $unknown = HistoricalSalesCatalogFixtures::unreadyProduct('UNKNOWN');
    $short = HistoricalSalesCatalogFixtures::unreadyProduct('SHORT');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $service = app(ProductForecastPreparationService::class);

    expect($products)->toHaveCount(15);
    foreach ($products as $product) {
        $result = $service->prepareMonthly($product);
        expect($result['status'])->toBe('ready');
        expect($result['coverage']['source_kind'])->toBe('synthetic_development');
        expect($result['positive_sales_months'])->toBe([12, 12, 12]);
    }
    expect($service->prepareMonthly($unknown)['status'])->toBe('history_unavailable');
    expect($service->prepareMonthly($short)['status'])->toBe('insufficient_history');
});

test('returns fixture coverage failures before querying sales', function (string $code, string $status, ?int $covered) {
    $product = HistoricalSalesCatalogFixtures::unreadyProduct($code);
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe($status);
    expect($result['covered_months'])->toBe($covered);
    expect($result['history'])->toBeNull();
    expect($result['positive_sales_months'])->toBeNull();
})->with([
    'short' => ['SHORT', 'insufficient_history', 35],
    'absent' => ['UNKNOWN', 'history_unavailable', null],
    'stale' => ['STALE', 'history_unavailable', null],
    'gap' => ['GAPPED', 'history_unavailable', null],
]);

test('counts each short trusted window without queries or observations', function (int $count) {
    $product = Product::factory()->create(['product_code' => '00123', 'created_at' => '2000-01-01']);
    monthlyForecastSale($product, '2023-10-01', 8);
    $start = CarbonImmutable::parse('2026-10-01', 'UTC')->subMonths($count)->toDateString();
    $coverage = monthlyForecastCoverage(['start' => $start]);
    config(['forecasting.operational_coverage' => ['00123' => $coverage]]);
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result)->toBe([
        'status' => 'insufficient_history',
        'message' => "{$count} completed months covered; 36 are required.",
        'product_id' => $product->id, 'product_code' => '00123', 'timezone' => 'UTC',
        'source_period' => ['start' => '2023-10-01', 'end_exclusive' => '2026-10-01'],
        'target_period' => ['start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
        'covered_months' => $count, 'coverage' => $coverage, 'history' => null, 'positive_sales_months' => null,
    ]);
})->with(range(0, 35));

test('does not construct history for missing or invalid coverage', function (?array $overrides) {
    $product = Product::factory()->create();
    monthlyForecastSale($product, '2023-10-01', 10);
    config(['forecasting.operational_coverage' => $overrides === null ? [] : [$product->product_code => monthlyForecastCoverage($overrides)]]);
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe('history_unavailable');
    expect($result['covered_months'])->toBeNull();
    expect($result['history'])->toBeNull();
    expect($result['positive_sales_months'])->toBeNull();
})->with([
    'missing metadata' => [null],
    'missing date' => [['start' => null]],
    'unaligned date' => [['start' => '2023-10-02']],
    'invalid calendar date' => [['start' => '2023-02-30']],
    'wrong timezone' => [['timezone' => 'Asia/Shanghai']],
    'old quarter granularity' => [['granularity' => 'quarter']],
    'old quarter gaps' => [['unavailable_quarters' => []]],
    'stale' => [['end_exclusive' => '2026-09-01']],
    'empty stale coverage' => [['start' => '2026-09-01', 'end_exclusive' => '2026-09-01']],
    'first source gap' => [['unavailable_months' => ['2023-10-01']]],
    'last source gap' => [['unavailable_months' => ['2026-09-01']]],
    'gap precedes shortness' => [['start' => '2026-09-01', 'unavailable_months' => ['2026-09-01']]],
]);

test('ignores gaps outside the source and never counts future months as history', function (array $overrides, string $status, int $covered) {
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage($overrides)]]);
    $this->expectsDatabaseQueryCount($status === 'ready' ? 1 : 0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe($status);
    expect($result['covered_months'])->toBe($covered);
})->with([
    'old gap' => [['start' => '2022-10-01', 'unavailable_months' => ['2023-09-01']], 'ready', 36],
    'target gap' => [['end_exclusive' => '2027-01-01', 'unavailable_months' => ['2026-10-01']], 'ready', 36],
    'future end' => [['start' => '2026-09-01', 'end_exclusive' => '2027-01-01'], 'insufficient_history', 1],
    'future only' => [['start' => '2027-01-01', 'end_exclusive' => '2027-04-01'], 'insufficient_history', 0],
]);

test('requires six positive observations independently in each chronological annual block', function (array $positiveCounts, string $status) {
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage()]]);
    foreach ($positiveCounts as $block => $count) {
        for ($index = 0; $index < $count; $index++) {
            monthlyForecastSale($product, CarbonImmutable::parse('2023-10-01', 'UTC')->addMonths($block * 12 + $index)->toDateString(), 1);
        }
    }

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe($status);
    expect($result['positive_sales_months'])->toBe($positiveCounts);
    expect($result['history']['months'])->toHaveCount(36);
})->with([
    'threshold' => [[6, 6, 6], 'ready'],
    'first block fails' => [[5, 12, 12], 'history_unsuitable'],
    'middle block fails' => [[12, 5, 12], 'history_unsuitable'],
    'last block fails' => [[12, 12, 5], 'history_unsuitable'],
    'zero block is not all zero history' => [[0, 12, 12], 'history_unsuitable'],
]);

test('uses calendar months across leap years and timezone quarter rollover', function (string $reference, string $timezone, string $start, string $end, string $targetEnd) {
    config(['app.timezone' => $timezone]);
    $instant = CarbonImmutable::parse($reference, 'UTC');
    $this->travelTo($instant);
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage([
        'start' => $start, 'end_exclusive' => $end, 'timezone' => $timezone,
    ])]]);
    $service = app(ProductForecastPreparationService::class);

    $result = $service->prepareMonthly($product);

    expect($result)->toBe($service->prepareMonthly($product, $instant));
    expect($result)->toBe($service->prepareMonthlyBatch([$product], $instant)[$product->id]);
    expect($result['status'])->toBe('ready');
    expect($result['source_period'])->toBe(['start' => $start, 'end_exclusive' => $end]);
    expect($result['target_period']['end_exclusive'])->toBe($targetEnd);
    expect($result['history']['timezone'])->toBe($timezone);
    $months = $result['history']['months'];
    expect($months)->toHaveCount(36);
    $starts = array_column($months, 'start');
    $ordered = $starts;
    sort($ordered, SORT_STRING);
    expect($starts)->toBe($ordered);
    expect(array_slice(array_column($months, 'end_exclusive'), 0, 35))->toBe(array_slice($starts, 1));
})->with([
    'UTC new year' => ['2027-01-01 00:00:00', 'UTC', '2024-01-01', '2027-01-01', '2027-04-01'],
    'Shanghai before local new year' => ['2026-12-31 15:59:59', 'Asia/Shanghai', '2023-12-01', '2026-12-01', '2027-03-01'],
    'Shanghai at local new year' => ['2026-12-31 16:00:00', 'Asia/Shanghai', '2024-01-01', '2027-01-01', '2027-04-01'],
    'December rolls through February' => ['2026-12-15 12:00:00', 'UTC', '2023-12-01', '2026-12-01', '2027-03-01'],
    'April rollover' => ['2026-04-01 00:00:00', 'UTC', '2023-04-01', '2026-04-01', '2026-07-01'],
    'before local November' => ['2026-10-31 15:59:59', 'Asia/Manila', '2023-10-01', '2026-10-01', '2027-01-01'],
    'at local November' => ['2026-10-31 16:00:00', 'Asia/Manila', '2023-11-01', '2026-11-01', '2027-02-01'],
    'leap February' => ['2028-02-29 12:00:00', 'UTC', '2025-02-01', '2028-02-01', '2028-05-01'],
]);

test('uses sale dates including leap day and excludes every target quarter observation', function () {
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage()]]);
    foreach (['2026-12-31' => 900, '2023-09-30' => 800, '2026-09-30' => 3, '2024-02-29' => 5, '2023-10-31' => 2, '2023-10-01' => 4, '2026-10-01' => 700] as $date => $quantity) {
        $sale = monthlyForecastSale($product, $date, $quantity);
        $sale->order->forceFill(['created_at' => '2026-11-15 12:00:00'])->save();
    }

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    $months = $result['history']['months'];
    expect($months[0]['quantity_sold'])->toBe(6);
    expect($months[4])->toBe(['year' => 2024, 'month' => 2, 'start' => '2024-02-01', 'end_exclusive' => '2024-03-01', 'quantity_sold' => 5]);
    expect($months[35]['quantity_sold'])->toBe(3);
    expect(array_sum(array_column($months, 'quantity_sold')))->toBe(14);
    expect($months[1]['quantity_sold'])->toBe(0);
});

test('does not change observations with customer identity price stock or catalog eligibility', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = $products['10003'];
    $service = app(ProductForecastPreparationService::class);
    $before = $service->prepareMonthly($product);
    User::where('email', 'like', 'demo.customer%@example.test')->get()->each(fn (User $customer) => $customer->update(['name' => 'Changed customer identity']));
    $product->orderItems()->with('order')->each(function (OrderItem $item) {
        $item->order->forceFill(['created_at' => '2026-12-01'])->save();
        $item->update(['price_at_time' => '9999.99']);
    });
    $product->update(['price' => '0.01', 'discount_price' => null]);
    Inventory::where('product_id', $product->id)->update(['quantity' => 0, 'last_updated' => '2026-10-15']);

    expect($service->prepareMonthly($product->refresh()))->toBe($before);
});

test('synthetic monthly history cannot establish readiness outside development', function (string $environment) {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = $products['10003'];
    $this->app->instance('env', $environment);
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe('history_unavailable');
    expect($result['coverage'])->toBeNull();
    expect($result['history'])->toBeNull();
})->with(['production', 'staging']);

test('the clock does not extend trusted coverage at the next quarter', function () {
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage()]]);
    $this->travelTo(CarbonImmutable::parse('2027-01-01', 'UTC'));
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthly($product);

    expect($result['status'])->toBe('history_unavailable');
    expect($result['source_period'])->toBe(['start' => '2024-01-01', 'end_exclusive' => '2027-01-01']);
    expect($result['history'])->toBeNull();
});

test('rejects malformed recorded groups instead of normalizing them into history', function (array $rows) {
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => monthlyForecastCoverage()]]);
    $this->mock(MonthlySalesRepository::class, function ($mock) use ($product, $rows) {
        $mock->shouldReceive('aggregateProduct')->once()->with($product->id, Mockery::on(fn (array $months): bool => count($months) === 36))->andReturn($rows);
    });

    expect(fn () => app(ProductForecastPreparationService::class)->prepareMonthly($product))->toThrow(InvalidArgumentException::class, 'Recorded monthly history');
})->with([
    'duplicate month' => [[['start' => '2023-10-01', 'quantity_sold' => 1], ['start' => '2023-10-01', 'quantity_sold' => 2]]],
    'unaligned month' => [[['start' => '2023-10-02', 'quantity_sold' => 1]]],
    'outside source' => [[['start' => '2026-10-01', 'quantity_sold' => 1]]],
    'negative' => [[['start' => '2023-10-01', 'quantity_sold' => -1]]],
    'fractional' => [[['start' => '2023-10-01', 'quantity_sold' => 1.5]]],
    'numeric string is not a normalized integer' => [[['start' => '2023-10-01', 'quantity_sold' => '1']]],
]);

test('rejects a product which has not been persisted', function () {
    $product = Product::factory()->make();
    $this->expectsDatabaseQueryCount(0);

    expect(fn () => app(ProductForecastPreparationService::class)->prepareMonthly($product))->toThrow(InvalidArgumentException::class, 'persisted product');
});

test('batch preparation matches every single product fixture using one sales query', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $products = Product::whereIn('product_code', DevelopmentHistoricalSalesSeeder::PRODUCT_CODES)->orderBy('id')->get();
    $preparation = app(ProductForecastPreparationService::class);
    $expected = [];
    foreach ($products as $product) {
        $expected[$product->id] = $preparation->prepareMonthly($product);
    }
    $this->expectsDatabaseQueryCount(1);

    $result = $preparation->prepareMonthlyBatch($products->all());

    expect($result)->toBe($expected);
});

test('batch preparation does not query sales when every declaration fails coverage', function () {
    $products = Product::factory()->count(3)->create();
    config(['forecasting.operational_coverage' => [
        $products[1]->product_code => monthlyForecastCoverage(['start' => '2023-11-01']),
        $products[2]->product_code => monthlyForecastCoverage(['unavailable_months' => ['2026-04-01']]),
    ]]);
    $this->expectsDatabaseQueryCount(0);

    $result = app(ProductForecastPreparationService::class)->prepareMonthlyBatch($products->all());

    expect(array_column($result, 'status'))->toBe(['history_unavailable', 'insufficient_history', 'history_unavailable']);
    expect(array_column($result, 'history'))->toBe([null, null, null]);
});

test('batch preparation accepts empty input and rejects unpersisted products before querying', function () {
    $preparation = app(ProductForecastPreparationService::class);
    $this->expectsDatabaseQueryCount(0);

    expect($preparation->prepareMonthlyBatch([]))->toBe([]);
    expect(fn () => $preparation->prepareMonthlyBatch([new Product]))->toThrow(InvalidArgumentException::class, 'persisted product');
});
