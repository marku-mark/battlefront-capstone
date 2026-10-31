<?php

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Forecast;
use App\Models\Product;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;
use Tests\MonthlyForecastFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function persistHoltWintersResult(Product $product, int $quantity = 0, string $start = '2023-10-01'): array
{
    $prepared = MonthlyForecastFixtures::preparation(array_fill(0, 36, $quantity), $start);
    $prepared['product_id'] = $prepared['history']['product_id'] = $product->id;
    $prepared['product_code'] = $product->product_code;

    return app(CalculateAdditiveHoltWinters::class)->execute($prepared);
}

test('persists finalized Holt Winters output against its product and quarter', function () {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product, 10);
    $original = $result;

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->product_id)->toBe($product->id);
    expect($forecast->method)->toBe('additive_holt_winters');
    expect($forecast->predicted_demand)->toBe('30.00');
    expect($forecast->forecast_quarter)->toBe('2026-10/2026-12');
    expect($forecast->generated_at->toDateTimeString())->toBe('2027-01-15 12:00:00');
    expect($forecast->product->is($product))->toBeTrue();
    expect($product->forecasts()->sole()->is($forecast))->toBeTrue();
    expect(array_keys($forecast->getAttributes()))->toEqualCanonicalizing(['id', 'product_id', 'method', 'predicted_demand', 'forecast_quarter', 'generated_at']);
    expect($result)->toBe($original);
    $this->assertDatabaseCount('forecasts', 1);
});

test('rerunning replaces demand and generation time without changing the row identity', function () {
    $product = Product::factory()->create();
    $action = app(PersistForecast::class);
    $original = $action->execute(persistHoltWintersResult($product, 4));
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));

    $replacement = $action->execute(persistHoltWintersResult($product, 8));

    expect($replacement->id)->toBe($original->id);
    expect($replacement->predicted_demand)->toBe('24.00');
    expect($replacement->generated_at->toDateTimeString())->toBe('2027-01-16 12:00:00');
    $this->assertDatabaseCount('forecasts', 1);
});

test('preserves both legacy methods during inserts reruns and rejected legacy writes', function () {
    $product = Product::factory()->create();
    $legacy = Forecast::factory()->for($product)->count(2)->sequence(
        ['method' => 'moving_average', 'predicted_demand' => '12.50'],
        ['method' => 'linear_trend', 'predicted_demand' => '25.00'],
    )->create(['forecast_quarter' => '2026-Q4', 'generated_at' => '2026-10-15 12:00:00']);
    $original = $legacy->map(fn (Forecast $forecast): array => $forecast->refresh()->getAttributes())->all();
    $result = persistHoltWintersResult($product, 10);
    $action = app(PersistForecast::class);

    $forecast = $action->execute($result);
    $action->execute(persistHoltWintersResult($product, 8));
    foreach (['moving_average', 'linear_trend'] as $method) {
        expect(fn () => $action->execute(array_replace($result, ['method' => $method])))->toThrow(InvalidArgumentException::class, 'Only additive_holt_winters');
    }

    expect($forecast->refresh()->predicted_demand)->toBe('24.00');
    expect($legacy->map(fn (Forecast $forecast): array => $forecast->refresh()->getAttributes())->all())->toBe($original);
    expect($product->forecasts()->count())->toBe(3);
    $this->assertDatabaseCount('forecasts', 3);
});

test('reruns preserve other product and quarter results', function () {
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $action = app(PersistForecast::class);
    $first = $action->execute(persistHoltWintersResult($product, 4));
    $otherQuarter = $action->execute(persistHoltWintersResult($product, 8, '2024-01-01'));
    $other = $action->execute(persistHoltWintersResult($otherProduct, 10));
    $snapshots = [$otherQuarter->getAttributes(), $other->getAttributes()];

    $replacement = $action->execute(persistHoltWintersResult($product, 12));

    expect($replacement->id)->toBe($first->id);
    expect($replacement->predicted_demand)->toBe('36.00');
    expect($replacement->forecast_quarter)->toBe('2026-10/2026-12');
    expect([$otherQuarter->refresh()->getAttributes(), $other->refresh()->getAttributes()])->toBe($snapshots);
    $this->assertDatabaseCount('forecasts', 3);
});

test('persists covered zero demand from monthly preparation and calculation', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    config(['forecasting.operational_coverage' => [$product->product_code => [
        'granularity' => 'month', 'start' => '2024-01-01', 'end_exclusive' => '2027-01-01',
        'unavailable_months' => [], 'timezone' => 'UTC',
        'source_kind' => 'operational_prepared', 'sales_scope' => 'captured_system_transactions',
    ]]]);
    $prepared = app(ProductForecastPreparationService::class)->prepareMonthly($product);
    expect($prepared['status'])->toBe('ready');
    $result = app(CalculateAdditiveHoltWinters::class)->execute($prepared);

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('0.00');
    expect($forecast->forecast_quarter)->toBe('2027-01/2027-03');
    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'method' => 'additive_holt_winters', 'predicted_demand' => '0.00']);
});

test('rejects actual unavailable insufficient and unsuitable calculation results without writes', function (string $code, string $status) {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $product = HistoricalSalesCatalogFixtures::unreadyProduct($code);
    $result = app(CalculateAdditiveHoltWinters::class)->execute(app(ProductForecastPreparationService::class)->prepareMonthly($product));
    expect($result['status'])->toBe($status);

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Only completed');

    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'unavailable' => ['UNKNOWN', 'history_unavailable'],
    'insufficient' => ['SHORT', 'insufficient_history'],
    'unsuitable' => ['SPARSE', 'history_unsuitable'],
]);

test('rejects legacy unsupported and malformed methods for new writes', function (mixed $method) {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    $result['method'] = $method;

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Only additive_holt_winters');
    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'moving average' => ['moving_average'], 'linear trend' => ['linear_trend'],
    'unknown' => ['seasonal'], 'missing' => [null], 'malformed' => [[]],
    'uppercase' => ['ADDITIVE_HOLT_WINTERS'], 'trailing space' => ['additive_holt_winters '],
]);

test('rejects category results explicitly at the product persistence boundary', function () {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    $result['dimension'] = 'category';

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class, 'Category forecast results cannot be persisted');
    $this->assertDatabaseCount('forecasts', 0);
});

test('rejects malformed output without changing a saved forecast', function (string $field, mixed $value) {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    $action = app(PersistForecast::class);
    $forecast = $action->execute($result);
    $original = $forecast->getAttributes();
    $this->travelTo(CarbonImmutable::parse('2027-01-16 12:00:00', 'UTC'));
    Arr::set($result, $field, $value);

    expect(fn () => $action->execute($result))->toThrow(InvalidArgumentException::class);

    expect($forecast->refresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'invalid product' => ['product_id', 0], 'string product' => ['product_id', '1'],
    'missing product' => ['product_id', null], 'nonexistent product' => ['product_id', 999999],
    'unknown dimension' => ['dimension', 'branch'], 'null dimension' => ['dimension', null],
    'missing status' => ['status', null], 'missing history' => ['status', 'missing_history'],
    'unknown status' => ['status', 'ready'], 'unavailable' => ['status', 'history_unavailable'],
    'insufficient' => ['status', 'insufficient_history'], 'unsuitable' => ['status', 'history_unsuitable'],
    'missing source' => ['source_period', null], 'missing target' => ['target_period', null],
    'invalid timezone' => ['timezone', 'Invalid/Zone'], 'empty timezone' => ['timezone', ''],
    'missing timezone' => ['timezone', null],
    '35 source months' => ['source_period.start', '2023-11-01'],
    '37 source months' => ['source_period.start', '2023-09-01'],
    'reversed source' => ['source_period.start', '2027-01-01'],
    'source unaligned' => ['source_period.start', '2023-10-02'],
    'missing source start' => ['source_period.start', null],
    'invalid source date' => ['source_period.start', '2023-02-30'],
    'source year zero' => ['source_period.start', '0000-10-01'],
    'source gap before target' => ['source_period.end_exclusive', '2026-09-01'],
    'source overlaps target' => ['source_period.end_exclusive', '2026-11-01'],
    'missing source end' => ['source_period.end_exclusive', null],
    'target not quarter aligned' => ['target_period.start', '2026-11-01'],
    'target date unaligned' => ['target_period.start', '2026-10-02'],
    'target end year mismatch' => ['target_period.end_exclusive', '2028-01-01'],
    'target end reversed' => ['target_period.end_exclusive', '2026-07-01'],
    'target end wrong type' => ['target_period.end_exclusive', 2027],
    'missing target end' => ['target_period.end_exclusive', null],
    'two month horizon' => ['target_period.end_exclusive', '2026-12-01'],
    'four month horizon' => ['target_period.end_exclusive', '2027-02-01'],
    'partial final month' => ['target_period.end_exclusive', '2026-12-31'],
    'missing months' => ['monthly_forecasts', null], 'empty months' => ['monthly_forecasts', []],
    'malformed month' => ['monthly_forecasts.0', null],
    'wrong month year' => ['monthly_forecasts.0.year', 2027],
    'wrong month index' => ['monthly_forecasts.0.month', 11],
    'string month index' => ['monthly_forecasts.0.month', '10'],
    'missing month start' => ['monthly_forecasts.0.start', null],
    'month start gap' => ['monthly_forecasts.1.start', '2026-12-01'],
    'duplicate month' => ['monthly_forecasts.1.start', '2026-10-01'],
    'incorrect month end' => ['monthly_forecasts.0.end_exclusive', '2026-12-01'],
    'missing raw' => ['monthly_forecasts.0.raw_quantity', null],
    'raw float' => ['monthly_forecasts.0.raw_quantity', 0.0],
    'raw wrong precision' => ['monthly_forecasts.0.raw_quantity', '0.00'],
    'missing usable' => ['monthly_forecasts.0.usable_quantity', null],
    'usable negative' => ['monthly_forecasts.0.usable_quantity', '-1.000000000000'],
    'usable float' => ['monthly_forecasts.0.usable_quantity', 0.0],
    'usable wrong precision' => ['monthly_forecasts.0.usable_quantity', '0.00'],
    'usable excessive precision' => ['monthly_forecasts.0.usable_quantity', '0.0000000000001'],
    'usable scientific notation' => ['monthly_forecasts.0.usable_quantity', '0e0'],
    'usable leading zero' => ['monthly_forecasts.0.usable_quantity', '00.000000000000'],
    'usable trailing newline' => ['monthly_forecasts.0.usable_quantity', "0.000000000000\n"],
    'missing clamp' => ['monthly_forecasts.0.was_clamped', null],
    'nonboolean clamp' => ['monthly_forecasts.0.was_clamped', 0],
    'inconsistent clamp' => ['monthly_forecasts.0.was_clamped', true],
    'negative raw without clamp' => ['monthly_forecasts.0.raw_quantity', '-1.000000000000'],
    'mismatched usable' => ['monthly_forecasts.0.usable_quantity', '1.000000000000'],
    'missing total' => ['forecast_quantity', null], 'float total' => ['forecast_quantity', 0.0],
    'negative total' => ['forecast_quantity', '-1.00'], 'noncanonical total' => ['forecast_quantity', '00.00'],
    'scientific total' => ['forecast_quantity', '0e0'], 'trailing newline total' => ['forecast_quantity', "0.00\n"],
    'excessive total precision' => ['forecast_quantity', '0.000'],
    'insufficient total precision' => ['forecast_quantity', '0.0'],
    'total mismatch' => ['forecast_quantity', '0.01'],
    'total overflow' => ['forecast_quantity', '10000000000.00'],
]);

test('rejects missing extra unordered and associative target months', function (string $case) {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    $result['monthly_forecasts'] = match ($case) {
        'missing' => array_slice($result['monthly_forecasts'], 0, 2),
        'extra' => [...$result['monthly_forecasts'], $result['monthly_forecasts'][2]],
        'unordered' => array_reverse($result['monthly_forecasts']),
        'associative' => array_combine(['oct', 'nov', 'dec'], $result['monthly_forecasts']),
    };

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with(['missing', 'extra', 'unordered', 'associative']);

test('rejects source history containing an incomplete current quarter', function () {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product, start: '2024-04-01');

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('sums internal precision quantities and rounds the quarter once half up', function (array $quantities, string $expected) {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    foreach ($quantities as $index => $quantity) {
        $result['monthly_forecasts'][$index]['raw_quantity'] = $quantity;
        $result['monthly_forecasts'][$index]['usable_quantity'] = $quantity;
    }
    $result['forecast_quantity'] = $expected;
    $scale = bcscale(0);

    try {
        $forecast = app(PersistForecast::class)->execute($result);

        expect($forecast->predicted_demand)->toBe($expected);
        expect(bcscale())->toBe(0);
        $this->assertDatabaseHas('forecasts', ['id' => $forecast->id, 'predicted_demand' => $expected]);
    } finally {
        bcscale($scale);
    }
})->with([
    'below half cent' => [['0.004999999999', '0.000000000000', '0.000000000000'], '0.00'],
    'exact half cent' => [['0.005000000000', '0.000000000000', '0.000000000000'], '0.01'],
    'display rounding differs' => [['0.004000000000', '0.004000000000', '0.004000000000'], '0.01'],
    'maximum stored demand' => [['9999999999.990000000000', '0.000000000000', '0.000000000000'], '9999999999.99'],
    'maximum rounds down' => [['9999999999.994999999999', '0.000000000000', '0.000000000000'], '9999999999.99'],
]);

test('rejects summing monthly display rounding and overflow rounding', function (array $quantities, string $total) {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    foreach ($quantities as $index => $quantity) {
        $result['monthly_forecasts'][$index]['raw_quantity'] = $quantity;
        $result['monthly_forecasts'][$index]['usable_quantity'] = $quantity;
    }
    $result['forecast_quantity'] = $total;

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
})->with([
    'sum rounded monthly values' => [['0.004000000000', '0.004000000000', '0.004000000000'], '0.00'],
    'raw sum rounds beyond capacity' => [['9999999999.995000000000', '0.000000000000', '0.000000000000'], '9999999999.99'],
    'monthly overflow' => [['10000000000.000000000000', '0.000000000000', '0.000000000000'], '9999999999.99'],
]);

test('persists a total using clamped months without allowing negative cancellation', function () {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product);
    $result['monthly_forecasts'][0]['raw_quantity'] = '-1.000000000000';
    $result['monthly_forecasts'][0]['was_clamped'] = true;
    $result['monthly_forecasts'][1]['raw_quantity'] = $result['monthly_forecasts'][1]['usable_quantity'] = '2.000000000000';
    $result['forecast_quantity'] = '2.00';

    $forecast = app(PersistForecast::class)->execute($result);

    expect($forecast->predicted_demand)->toBe('2.00');
    $this->assertDatabaseCount('forecasts', 1);
});

test('rejects actual oversized model output without saving it', function () {
    $product = Product::factory()->create();
    $result = persistHoltWintersResult($product, 4000000000);
    expect($result['forecast_quantity'])->toBe('12000000000.00');

    expect(fn () => app(PersistForecast::class)->execute($result))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('rejects an empty forecast payload without saving it', function () {
    expect(fn () => app(PersistForecast::class)->execute([]))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('forecasts', 0);
});

test('database rejects duplicate keys, missing products, invalid methods, negative demand, and malformed quarters', function (array $attributes) {
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create();

    expect(fn () => DB::table('forecasts')->insert(array_replace([
        'product_id' => $product->id,
        'method' => 'additive_holt_winters',
        'predicted_demand' => '1.00',
        'forecast_quarter' => '2026-Q4',
        'generated_at' => now(),
    ], $attributes)))->toThrow(QueryException::class);

    $this->assertModelExists($forecast);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'unique key' => [['forecast_quarter' => '2026-Q4']],
    'foreign key' => [['product_id' => 999999]],
    'unsupported method' => [['method' => 'unsupported']],
    'uppercase method' => [['method' => 'LINEAR_TREND']],
    'method trailing space' => [['method' => 'linear_trend ']],
    'negative demand' => [['predicted_demand' => '-1.00']],
    'quarter format' => [['forecast_quarter' => '2026-Q9']],
    'lowercase quarter' => [['forecast_quarter' => '2026-q4']],
    'year zero' => [['forecast_quarter' => '0000-Q1']],
    'required demand' => [['predicted_demand' => null]],
    'required generation time' => [['generated_at' => null]],
    'required product' => [['product_id' => null]],
]);

test('product deletion preserves existing forecasts', function () {
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create();

    expect(fn () => DB::table('forecasts')->where('id', $forecast->id)->update(['predicted_demand' => '-1.00']))->toThrow(QueryException::class);
    $product->delete();

    $this->assertModelExists($forecast);
    expect($product->fresh()->trashed())->toBeTrue();
});

test('database rejects noncanonical values when updating an existing forecast', function (array $attributes) {
    $forecast = Forecast::factory()->create();
    $original = $forecast->getAttributes();

    expect(fn () => DB::table('forecasts')->where('id', $forecast->id)->update($attributes))->toThrow(QueryException::class);

    expect($forecast->refresh()->getAttributes())->toEqual($original);
})->with([
    'uppercase method' => [['method' => 'LINEAR_TREND']],
    'trailing space' => [['method' => 'linear_trend ']],
    'lowercase quarter' => [['forecast_quarter' => '2026-q4']],
    'year zero' => [['forecast_quarter' => '0000-Q1']],
]);
