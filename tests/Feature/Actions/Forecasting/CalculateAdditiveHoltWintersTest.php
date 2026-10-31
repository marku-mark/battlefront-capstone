<?php

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use App\Actions\Forecasting\EvaluateQuarterlyForecasts;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;
use Tests\MonthlyForecastFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

test('consumes prepared imported product history without another query or changing observations', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $service = app(ProductForecastPreparationService::class);
    $prepared = [];
    foreach ($products as $product) {
        $prepared[$product->id] = $service->prepareMonthly($product);
    }
    $original = $prepared;
    $this->travelTo(CarbonImmutable::parse('2030-01-01', 'UTC'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        foreach ($prepared as $history) {
            $result = app(CalculateAdditiveHoltWinters::class)->execute($history);
            expect($result['status'])->toBe('ok');
            expect($result['product_id'])->toBe($history['product_id']);
            expect($result['source_period'])->toBe($history['source_period']);
            expect($result['target_period'])->toBe($history['target_period']);
            expect(array_column($result['monthly_forecasts'], 'start'))->toBe(['2026-10-01', '2026-11-01', '2026-12-01']);
            expect($result['source_kind'])->toBe('synthetic_development');
            expect($result['sales_scope'])->toBe('development_fixture_transactions');
            expect($result['candidates_evaluated'])->toBe(900);
        }
        expect($prepared)->toBe($original);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

test('preserves coverage and sparse eligibility failures without fallback or queries', function () {
    $expected = ['SPARSE' => 'history_unsuitable', 'SHORT' => 'insufficient_history', 'UNKNOWN' => 'history_unavailable', 'STALE' => 'history_unavailable', 'GAPPED' => 'history_unavailable'];
    $prepared = [];
    foreach ($expected as $scenario => $status) {
        $product = HistoricalSalesCatalogFixtures::unreadyProduct($scenario);
        $prepared[$scenario] = app(ProductForecastPreparationService::class)->prepareMonthly($product);
    }
    $this->expectsDatabaseQueryCount(0);

    foreach ($expected as $scenario => $status) {
        $result = app(CalculateAdditiveHoltWinters::class)->execute($prepared[$scenario]);
        expect($result['status'])->toBe($status);
        expect($result['message'])->toBe($prepared[$scenario]['message']);
        expect($result['forecast_quantity'])->toBeNull();
        expect($result['parameters'])->toBeNull();
        expect($result['candidates_evaluated'])->toBe(0);
        expect($result['monthly_forecasts'])->toBe([]);
    }
});

test('evaluates four imported product preparation origins against known demonstration transactions', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $folds = [];
    foreach (['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01'] as $index => $origin) {
        $prepared = app(ProductForecastPreparationService::class)->prepareMonthly($products['10003'], CarbonImmutable::parse($origin, 'UTC'));
        $actuals = [[2, 1, 2], [2, 1, 2], [1, 2, 2], [2, 1, 2]][$index];
        $folds[] = ['preparation' => $prepared, 'actual_months' => MonthlyForecastFixtures::months($actuals, $origin)];
    }
    $this->expectsDatabaseQueryCount(0);

    $result = app(EvaluateQuarterlyForecasts::class)->execute($folds);

    expect($result['evaluated_count'])->toBe(4);
    expect($result['excluded_count'])->toBe(0);
    expect(array_column($result['origins'], 'actual_quantity'))->toBe(['5.000000000000', '5.000000000000', '5.000000000000', '5.000000000000']);
    expect(array_keys($result['mae_display']))->toBe(['additive_holt_winters', 'seasonal_naive', 'moving_average']);
    expect($result['source_kinds'])->toBe(['synthetic_development']);
});
