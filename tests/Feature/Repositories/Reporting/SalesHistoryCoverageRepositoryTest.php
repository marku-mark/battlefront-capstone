<?php

use App\Models\Product;
use App\Models\Sale;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function preparedSalesCoverage(array $overrides = []): array
{
    return array_replace([
        'granularity' => 'month',
        'start' => '2024-10-01', 'end_exclusive' => '2026-10-01',
        'unavailable_months' => [], 'timezone' => 'UTC',
        'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
    ], $overrides);
}

test('bulk coverage preserves validation exact codes conflicts and environment restrictions', function (string $environment) {
    $disk = Storage::fake('local');
    $operational = preparedSalesCoverage();
    $synthetic = preparedSalesCoverage(['source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions']);
    config(['forecasting.operational_coverage' => [
        '00123' => $operational, '123' => $operational,
        'INVALID' => preparedSalesCoverage(['timezone' => 'Asia/Manila']), 'CONFLICT' => $operational,
    ]]);
    $disk->put(config('forecasting.development_manifest'), json_encode([
        'version' => 2, 'products' => ['SYNTHETIC' => $synthetic, 'CONFLICT' => $synthetic],
    ], JSON_THROW_ON_ERROR));
    $this->app->instance('env', $environment);
    $this->expectsDatabaseQueryCount(0);

    $repository = app(SalesHistoryCoverageRepository::class);
    $results = $repository->forProductCodes(['00123', '123', 'UNKNOWN', 'INVALID', 'CONFLICT', 'SYNTHETIC', 'invalid-code']);

    expect($results)->toBe([
        '00123' => $operational, '123' => $operational, 'UNKNOWN' => null, 'INVALID' => null,
        'CONFLICT' => $environment === 'production' ? $operational : null,
        'SYNTHETIC' => $environment === 'production' ? null : $synthetic, 'invalid-code' => null,
    ]);
    config(['forecasting.operational_coverage' => []]);
    $disk->delete(config('forecasting.development_manifest'));
    expect($repository->forProductCodes(['00123', 'SYNTHETIC']))->toBe(['00123' => null, 'SYNTHETIC' => null]);
})->with(['testing', 'production']);

test('operational coverage stays unavailable despite existing records and generated zero buckets', function () {
    Storage::fake('local');
    $product = Product::factory()->create(['product_code' => '00123', 'created_at' => '2020-01-01']);
    Sale::factory()->create(['sale_date' => '2020-01-01']);
    $history = app(QuarterlySalesAggregationService::class)->completedProducts(4, [$product->id]);

    expect(config('forecasting.operational_coverage'))->toBe([]);
    expect($history['series'][0]['quarters'])->toHaveCount(4);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('00123'))->toBeNull();
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('development publication retains distinct numeric and leading zero product code identities', function () {
    $disk = Storage::fake('local');
    $entry = preparedSalesCoverage(['source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions']);
    $repository = app(SalesHistoryCoverageRepository::class);

    $repository->writeDevelopment(['00123' => $entry, '123' => $entry]);

    expect($repository->forProductCode('00123'))->toBe($entry);
    expect($repository->forProductCode('123'))->toBe($entry);
    $manifest = json_decode($disk->get(config('forecasting.development_manifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['products'])->toHaveCount(2);
    expect($manifest['products']['00123'])->toBe($entry);
    expect($manifest['products']['123'])->toBe($entry);
});

test('operational declarations preserve exact product codes and explicit sales scope without database queries', function (string $scope) {
    Storage::fake('local');
    $entry = preparedSalesCoverage(['sales_scope' => $scope]);
    config(['forecasting.operational_coverage' => ['00123' => $entry]]);
    $this->app->instance('env', 'production');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $repository = app(SalesHistoryCoverageRepository::class);
        expect($repository->forProductCode('00123'))->toBe($entry);
        expect($repository->forProductCode('123'))->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with(['all_sagay_sales', 'captured_system_transactions']);

test('malformed declarations return no trusted coverage', function (array $overrides) {
    Storage::fake('local');
    config(['forecasting.operational_coverage' => ['00123' => preparedSalesCoverage($overrides)]]);

    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('00123'))->toBeNull();
})->with([
    'missing start' => [['start' => null]],
    'missing end' => [['end_exclusive' => null]],
    'non date' => [['start' => 123]],
    'zero year' => [['start' => '0000-01-01']],
    'normalized invalid date' => [['start' => '2025-02-30']],
    'unaligned date' => [['start' => '2025-10-02']],
    'time included' => [['start' => '2025-10-01 00:00:00']],
    'reversed interval' => [['start' => '2027-01-01']],
    'timezone mismatch' => [['timezone' => 'Asia/Shanghai']],
    'unknown source' => [['source_kind' => 'unverified']],
    'missing source' => [['source_kind' => null]],
    'missing scope' => [['sales_scope' => null]],
    'synthetic operational declaration' => [['source_kind' => 'synthetic_development']],
    'unknown scope' => [['sales_scope' => 'unknown']],
    'null gaps' => [['unavailable_months' => null]],
    'non list gaps' => [['unavailable_months' => ['gap' => '2026-01-01']]],
    'unaligned gap' => [['unavailable_months' => ['2026-02-02']]],
    'missing granularity' => [['granularity' => null]],
    'quarter granularity' => [['granularity' => 'quarter']],
    'legacy quarter gaps' => [['unavailable_quarters' => []]],
    'gap at exclusive end' => [['unavailable_months' => ['2026-10-01']]],
    'gap before interval' => [['unavailable_months' => ['2024-07-01']]],
    'duplicate gaps' => [['unavailable_months' => ['2026-01-01', '2026-01-01']]],
]);

test('short complete intervals preserve zero to thirty five months for later insufficient history evaluation', function (string $start, int $completedMonths) {
    Storage::fake('local');
    $entry = preparedSalesCoverage(['start' => $start]);
    unset($entry['unavailable_months']);
    config(['forecasting.operational_coverage' => ['00123' => $entry]]);

    $coverage = app(SalesHistoryCoverageRepository::class)->forProductCode('00123');

    expect($coverage['start'])->toBe($start);
    expect($coverage['end_exclusive'])->toBe('2026-10-01');
    expect($coverage['unavailable_months'])->toBe([]);
    expect((int) CarbonImmutable::parse($start)->diffInMonths(CarbonImmutable::parse($coverage['end_exclusive'])))->toBe($completedMonths);
})->with(array_map(fn (int $months): array => [CarbonImmutable::parse('2026-10-01')->subMonths($months)->toDateString(), $months], range(0, 35)));

test('stale ends and declared gaps remain explicit without clock based repairs', function () {
    Storage::fake('local');
    $stale = preparedSalesCoverage(['end_exclusive' => '2026-07-01']);
    config(['forecasting.operational_coverage' => [
        'STALE' => $stale,
        'GAPPED' => preparedSalesCoverage(['unavailable_months' => ['2026-01-01', '2024-10-01']]),
    ]]);
    $repository = app(SalesHistoryCoverageRepository::class);

    expect($repository->forProductCode('STALE'))->toBe($stale);
    expect($repository->forProductCode('GAPPED')['unavailable_months'])->toBe(['2024-10-01', '2026-01-01']);
    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    expect($repository->forProductCode('STALE'))->toBe($stale);
});

test('seeded monthly coverage matches selected imported product quantities', function (string $code, int $quantity) {
    HistoricalSalesCatalogFixtures::createProducts();
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', $code)->sole();
    $repository = new SalesHistoryCoverageRepository;

    expect($repository->forProductCode($product->product_code))->toBe(preparedSalesCoverage([
        'start' => '2022-10-01',
        'source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions',
    ]));
    expect($product->orderItems()->sum('quantity'))->toBe($quantity);
    $manifest = json_decode(Storage::disk('local')->get(config('forecasting.development_manifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['version'])->toBe(2);
    expect($manifest['products'])->toHaveCount(15);
})->with([
    ['10003', 72], ['43720093', 78], ['10035', 96], ['6939084200089', 96],
    ['6940056180506', 186], ['87399741', 163], ['10196', 172], ['19986', 160],
    ['10468', 175], ['10121', 155], ['10986', 108], ['10214', 64], ['10350', 80], ['10463', 175], ['28909657', 164],
]);

test('only explicit reseeding advances the development coverage at month rollover', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $path = config('forecasting.development_manifest');
    $before = $disk->get($path);
    $repository = app(SalesHistoryCoverageRepository::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-15'));
    expect($repository->forProductCode('10003')['end_exclusive'])->toBe('2026-10-01');
    expect($disk->get($path))->toBe($before);
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $entry = $repository->forProductCode('10003');
    expect($entry['start'])->toBe('2022-11-01');
    expect($entry['end_exclusive'])->toBe('2026-11-01');
    expect(Sale::count())->toBeBetween(150, 300);
    expect(config('forecasting.operational_coverage'))->toBe([]);
});

test('failed fixture preparation invalidates old coverage and rolls back record changes', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    Product::where('product_code', '10003')->sole()->forceFill(['is_catalog_imported' => false])->save();
    $before = DB::table('products')->get()->toArray();

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'existing imported product');

    expect(DB::table('products')->get()->toArray())->toEqual($before);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('10003'))->toBeNull();
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));
});

test('malformed development manifests cannot establish coverage', function (string $contents) {
    $disk = Storage::fake('local');
    $disk->put(config('forecasting.development_manifest'), $contents);

    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('10003'))->toBeNull();
})->with(['{', '{"version":1,"products":{}}', '{"version":1,"products":null}', '{"version":2,"products":null}', '{"products":{}}']);

test('synthetic coverage cannot be read published or cleared in unsafe environments', function (string $environment) {
    HistoricalSalesCatalogFixtures::createProducts();
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $before = $disk->get(config('forecasting.development_manifest'));
    $this->app->instance('env', $environment);
    $repository = app(SalesHistoryCoverageRepository::class);

    expect($repository->forProductCode('10003'))->toBeNull();
    expect(fn () => $repository->writeDevelopment([]))->toThrow(RuntimeException::class);
    expect(fn () => $repository->clearDevelopment())->toThrow(RuntimeException::class);
    expect($disk->get(config('forecasting.development_manifest')))->toBe($before);
})->with(['production', 'staging']);

test('explicit Cloud demo mode stores synthetic coverage in the database', function () {
    Storage::fake('local');
    $this->app->instance('env', 'production');
    config(['forecasting.cloud_demo_history_enabled' => true]);
    $entry = preparedSalesCoverage([
        'start' => '2022-10-01',
        'source_kind' => 'synthetic_development',
        'sales_scope' => 'development_fixture_transactions',
    ]);
    $repository = app(SalesHistoryCoverageRepository::class);

    $repository->writeDevelopment(['00123' => $entry, 'AB12' => $entry, 'ab12' => $entry]);

    expect($repository->forProductCode('00123'))->toBe($entry);
    expect($repository->hasProductReference('00123'))->toBeTrue();
    expect($repository->forProductCodes(['AB12', 'ab12']))->toBe(['AB12' => $entry, 'ab12' => $entry]);
    $this->assertDatabaseHas('forecasting_history_coverages', ['product_code' => '00123']);
    Storage::disk('local')->assertMissing(config('forecasting.development_manifest'));

    config(['forecasting.cloud_demo_history_enabled' => false]);
    expect($repository->forProductCode('00123'))->toBeNull();
    expect($repository->hasProductReference('00123'))->toBeFalse();

    config(['forecasting.cloud_demo_history_enabled' => true]);

    $repository->clearDevelopment();

    expect($repository->forProductCode('00123'))->toBeNull();
});

test('conflicting source declarations cannot establish coverage or overwrite a manifest', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $repository = app(SalesHistoryCoverageRepository::class);
    $entry = $repository->forProductCode('10003');
    $before = $disk->get(config('forecasting.development_manifest'));
    config(['forecasting.operational_coverage' => ['10003' => preparedSalesCoverage()]]);

    expect($repository->forProductCode('10003'))->toBeNull();
    expect(fn () => $repository->writeDevelopment(['10003' => $entry]))->toThrow(InvalidArgumentException::class);
    expect($disk->get(config('forecasting.development_manifest')))->toBe($before);
});

test('invalid synthetic publication leaves the prior declaration intact', function () {
    $disk = Storage::fake('local');
    $repository = app(SalesHistoryCoverageRepository::class);
    $path = config('forecasting.development_manifest');
    $disk->put($path, 'prior declaration');

    expect(fn () => $repository->writeDevelopment(['INVALID-CODE' => preparedSalesCoverage()]))->toThrow(InvalidArgumentException::class);
    expect($disk->get($path))->toBe('prior declaration');
});

test('failed publication leaves prepared records without coverage and can be retried without duplicate transactions', function (bool $published) {
    HistoricalSalesCatalogFixtures::createProducts();
    $disk = Storage::fake('local');
    $this->partialMock(SalesHistoryCoverageRepository::class, function ($mock) use ($published) {
        $mock->shouldReceive('writeDevelopment')->once()->andReturnUsing(function (array $entries) use ($published): void {
            if ($published) {
                (new SalesHistoryCoverageRepository)->writeDevelopment($entries);
            }
            throw new RuntimeException('Publication failed.');
        });
    });

    expect(fn () => $this->seed(DevelopmentHistoricalSalesSeeder::class))->toThrow(RuntimeException::class, 'Publication failed');

    $this->assertDatabaseCount('sales', 259);
    $disk->assertMissing(config('forecasting.development_manifest'));
    $ordersBefore = DB::table('orders')->orderBy('id')->get()->all();
    $salesBefore = DB::table('sales')->orderBy('id')->get()->all();
    $this->app->instance(SalesHistoryCoverageRepository::class, new SalesHistoryCoverageRepository);

    $this->seed(DevelopmentHistoricalSalesSeeder::class);

    expect(DB::table('orders')->orderBy('id')->get()->all())->toEqual($ordersBefore);
    expect(DB::table('sales')->orderBy('id')->get()->all())->toEqual($salesBefore);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('10003')['source_kind'])->toBe('synthetic_development');
})->with(['write fails' => false, 'exception after publication' => true]);

test('explicit monthly declarations retain short absent stale and gapped coverage despite recorded sales', function () {
    Storage::fake('local');
    $repository = app(SalesHistoryCoverageRepository::class);
    $short = HistoricalSalesCatalogFixtures::unreadyProduct('SHORT');
    $unknown = HistoricalSalesCatalogFixtures::unreadyProduct('UNKNOWN');
    $stale = HistoricalSalesCatalogFixtures::unreadyProduct('STALE');
    $gapped = HistoricalSalesCatalogFixtures::unreadyProduct('GAPPED');

    expect($repository->forProductCode($short->product_code)['start'])->toBe('2023-11-01');
    expect($repository->forProductCode($unknown->product_code))->toBeNull();
    expect($repository->forProductCode($stale->product_code)['end_exclusive'])->toBe('2026-09-01');
    expect($repository->forProductCode($gapped->product_code)['unavailable_months'])->toBe(['2026-04-01']);
    foreach ([$unknown, $stale, $gapped] as $product) {
        expect($product->orderItems()->sum('quantity'))->toBe(1);
    }
});

test('version one cannot establish coverage even with otherwise valid monthly entries', function () {
    HistoricalSalesCatalogFixtures::createProducts();
    $disk = Storage::fake('local');
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $path = config('forecasting.development_manifest');
    $manifest = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['version'] = 1;
    $disk->put($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('10003'))->toBeNull();
});
