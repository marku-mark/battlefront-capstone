<?php

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\MonthlyForecastFixtures;

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

function forecastingSale(Product $product, string $date, int $quantity): void
{
    $sale = Sale::factory()->create(['sale_date' => $date, 'amount' => '1.00']);
    OrderItem::factory()->for($sale->order)->for($product)->create([
        'quantity' => $quantity, 'price_at_time' => '0.00',
    ]);
}

/** @return array{product_id: int} */
function forecastingInput(Product $product): array
{
    return ['product_id' => $product->id];
}

/** @param array<string, mixed> $overrides */
function forecastingCoverage(Product $product, array $overrides = []): void
{
    config(['forecasting.operational_coverage' => [
        ...config('forecasting.operational_coverage', []),
        $product->product_code => [
            'granularity' => 'month',
            'start' => '2023-10-01',
            'end_exclusive' => '2026-10-01',
            'unavailable_months' => [],
            'timezone' => config('app.timezone'),
            'source_kind' => 'operational_prepared',
            'sales_scope' => 'captured_system_transactions',
            ...$overrides,
        ],
    ]]);
}

/** @param list<int> $quantities */
function forecastingMonthlySales(Product $product, array $quantities, string $start = '2023-10-01'): void
{
    foreach (MonthlyForecastFixtures::months($quantities, $start) as $month) {
        if ($month['quantity_sold'] > 0) {
            forecastingSale($product, $month['start'], $month['quantity_sold']);
        }
    }
}

/** @return array<string, mixed> */
function forecastingZeroResult(Product $product): array
{
    $preparation = MonthlyForecastFixtures::preparation(array_fill(0, 36, 0));
    $preparation['product_id'] = $product->id;
    $preparation['product_code'] = $product->product_code;
    $preparation['history']['product_id'] = $product->id;

    return (new CalculateAdditiveHoltWinters)->execute($preparation);
}

test('guests must log in and customers cannot read or generate forecasts', function (string $verb) {
    $this->$verb(route('administration.forecasting.'.($verb === 'get' ? 'index' : 'store')))
        ->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->customer()->create())
        ->$verb(route('administration.forecasting.'.($verb === 'get' ? 'index' : 'store')))
        ->assertForbidden();

    $this->assertDatabaseEmpty('forecasts');
})->with(['get', 'post']);

test('administrators see the dedicated forecasting page and empty states', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Administration/Forecasting')
            ->has('products.data', 0)
            ->has('forecasts.data', 0)
            ->where('selected_product', null)
            ->where('readiness', null)
            ->where('current_inventory', null)
            ->where('timezone', 'UTC')
            ->missing('methods')
            ->missing('history_end')
            ->missing('filters.method'));
});

test('the selector includes only currently forecast ready products', function (?array $coverage, array $quantities, bool $active, string $status) {
    $product = Product::factory()->create(['is_active' => $active]);
    if ($coverage !== null) {
        forecastingCoverage($product, $coverage);
    }
    forecastingMonthlySales($product, $quantities);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', $status)
            ->where('products.total', $status === 'ready' ? 1 : 0)
            ->where('products.data', $status === 'ready' ? [[
                'id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code,
                'category' => $product->category->name, 'is_active' => $active, 'is_synthetic' => false,
            ]] : []));
})->with([
    'positive history' => [[], array_fill(0, 36, 10), true, 'ready'],
    'mixed zeros at threshold' => [[], array_merge(...array_fill(0, 3, [1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0])), true, 'ready'],
    'covered all zero' => [[], [], true, 'ready'],
    'inactive ready' => [[], [], false, 'ready'],
    'short' => [['start' => '2023-11-01'], [], true, 'insufficient_history'],
    'absent coverage despite recorded sales' => [null, array_fill(0, 36, 10), true, 'history_unavailable'],
    'stale coverage' => [['end_exclusive' => '2026-09-01'], [], true, 'history_unavailable'],
    'coverage gap' => [['unavailable_months' => ['2026-04-01']], [], true, 'history_unavailable'],
    'sparse' => [[], [1], true, 'history_unsuitable'],
    'inactive unsuitable' => [[], [1], false, 'history_unsuitable'],
]);

test('generates three monthly estimates from exactly thirty six completed months through EXT45', function () {
    $product = Product::factory()->for(Category::factory()->state(['is_active' => false]))
        ->create(['is_active' => false]);
    forecastingCoverage($product, ['start' => '2022-10-01']);
    forecastingMonthlySales($product, array_fill(0, 36, 10));
    forecastingSale($product, '2023-09-30', 900);
    forecastingSale($product, '2026-10-01', 9999);
    forecastingSale($product, '2026-11-01', 9999);
    forecastingSale($product, '2026-12-31', 9999);
    $this->mock(PersistForecast::class)->shouldReceive('execute')->once()
        ->andReturnUsing(fn (array $result): Forecast => (new PersistForecast)->execute($result));

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product));

    $response->assertRedirectToRoute('administration.forecasting.index', ['product_id' => $product->id])
        ->assertInertiaFlash('forecast_result.status', 'ready')
        ->assertInertiaFlash('forecast_result.method', 'additive_holt_winters')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '30.00')
        ->assertInertiaFlash('forecast_result.product.id', $product->id)
        ->assertInertiaFlash('forecast_result.product.is_active', false)
        ->assertInertiaFlash('forecast_result.source_period.start', '2023-10-01')
        ->assertInertiaFlash('forecast_result.source_period.end_exclusive', '2026-10-01')
        ->assertInertiaFlash('forecast_result.source_label', 'Oct 2023 – Sep 2026')
        ->assertInertiaFlash('forecast_result.target_label', 'Q4 2026')
        ->assertInertiaFlash('forecast_result.target_quarter.end_exclusive', '2027-01-01')
        ->assertInertiaFlash('forecast_result.generated_at', '2026-10-15T12:00:00+00:00')
        ->assertInertiaFlash('forecast_result.observations.0.label', 'Oct 2023')
        ->assertInertiaFlash('forecast_result.observations.35.label', 'Sep 2026')
        ->assertInertiaFlash('forecast_result.observations.35.quantity_sold', 10)
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.label', 'Oct 2026')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.1.label', 'Nov 2026')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.label', 'Dec 2026')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.forecast_quantity', '10.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.forecast_quantity', '10.00')
        ->assertInertiaFlash('forecast_result.sales_scope', 'captured_system_transactions')
        ->assertInertiaFlash('forecast_result.sales_scope_label', 'Captured system transactions')
        ->assertInertiaFlash('forecast_result.source_kind', 'operational_prepared')
        ->assertInertiaFlash('forecast_result.is_synthetic', false)
        ->assertInertiaFlash('forecast_result.current_inventory', null)
        ->assertInertiaFlash('forecast_result.guidance', 'Use this estimate alongside business judgment when planning stock. Actual demand may differ.');
    $this->assertDatabaseHas('forecasts', [
        'product_id' => $product->id, 'method' => 'additive_holt_winters',
        'forecast_quarter' => '2026-Q4', 'predicted_demand' => '30.00',
    ]);
    $this->get($response->headers->get('Location'))
        ->assertInertia(function (Assert $page) {
            $result = $page->toArray()['flash']['forecast_result'];
            expect($result['observations'])->toHaveCount(36);
            expect(array_column($result['observations'], 'quantity_sold'))->toBe(array_fill(0, 36, 10));
            expect($result['monthly_forecasts'])->toHaveCount(3);
            $page->hasFlash('forecast_result.observations')
                ->hasFlash('forecast_result.monthly_forecasts')
                ->missingFlash('forecast_result.parameters')
                ->where('readiness.status', 'ready')
                ->where('readiness.covered_months', 36)
                ->where('selected_product.is_active', false)
                ->where('forecasts.data.0.forecast_quantity', '30.00');
        });
    $this->assertDatabaseCount('forecasts', 1);
});

test('reruns replace only the Holt Winters product quarter and exclude all target sales', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    forecastingMonthlySales($product, array_fill(0, 36, 10));
    $legacyAverage = Forecast::factory()->for($product)->create(['method' => 'moving_average', 'predicted_demand' => '12.00']);
    $legacyTrend = Forecast::factory()->for($product)->create(['method' => 'linear_trend']);
    $otherQuarter = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'forecast_quarter' => '2026-Q3']);
    $otherProduct = Forecast::factory()->create(['method' => 'additive_holt_winters']);
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product));
    $original = Forecast::where('product_id', $product->id)->where('method', 'additive_holt_winters')->where('forecast_quarter', '2026-Q4')->sole();
    $others = Forecast::where('id', '!=', $original->id)->orderBy('id')->get()->toArray();
    forecastingSale($product, '2026-10-01', 900);
    forecastingSale($product, '2026-11-15', 900);
    forecastingSale($product, '2026-12-31', 900);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));

    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.id', $original->id)
        ->assertInertiaFlash('forecast_result.forecast_quantity', '30.00')
        ->assertInertiaFlash('forecast_result.source_period.end_exclusive', '2026-10-01')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.start', '2026-10-01')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.end_exclusive', '2027-01-01')
        ->assertInertiaFlash('forecast_result.generated_at', '2026-11-16T12:00:00+00:00');

    $this->assertDatabaseCount('forecasts', 5);
    expect(Forecast::where('id', '!=', $original->id)->orderBy('id')->get()->toArray())->toBe($others);
    forecastingSale($product, '2026-09-30', 120);
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.id', $original->id);
    expect($original->fresh()->predicted_demand)->not->toBe('30.00');
    $this->assertDatabaseCount('forecasts', 5);
});

test('unavailable monthly coverage saves nothing and preserves saved results', function (?array $coverage) {
    $product = Product::factory()->create();
    if ($coverage !== null) {
        forecastingCoverage($product, $coverage);
    }
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '14.00']);
    $snapshot = $saved->fresh()->toArray();
    forecastingSale($product, '2026-07-01', 12);
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->where('readiness.status', 'history_unavailable'));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'history_unavailable')
        ->assertInertiaFlash('forecast_result.observations', [])
        ->assertInertiaFlash('forecast_result.monthly_forecasts', [])
        ->assertInertiaFlash('forecast_result.forecast_quantity', null)
        ->assertInertiaFlash('forecast_result.generated_at', null);

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    'absent' => [null],
    'malformed' => [['start' => 'invalid']],
    'stale' => [['end_exclusive' => '2026-09-01']],
    'gap' => [['unavailable_months' => ['2026-04-01']]],
    'gap takes precedence over short' => [['start' => '2026-01-01', 'unavailable_months' => ['2026-04-01']]],
]);

test('insufficient monthly history does not calculate or overwrite an existing forecast', function (string $start, int $count) {
    $product = Product::factory()->create();
    forecastingCoverage($product, ['start' => $start]);
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '14.00']);
    $snapshot = $saved->fresh()->toArray();
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'insufficient_history')
            ->where('readiness.covered_months', $count));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'insufficient_history')
        ->assertInertiaFlash('forecast_result.covered_months', $count)
        ->assertInertiaFlash('forecast_result.message', $count.' completed months covered; 36 are required. No forecast was saved.')
        ->assertInertiaFlash('forecast_result.forecast_quantity', null)
        ->assertInertiaFlash('forecast_result.monthly_forecasts', []);

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
})->with([
    ['2026-10-01', 0], ['2026-09-01', 1], ['2025-10-01', 12], ['2023-11-01', 35],
]);

test('covered all zero history persists three zero monthly estimates and a zero quarterly total', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'ready')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.1.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.observations.0.quantity_sold', 0)
        ->assertInertiaFlash('forecast_result.observations.35.quantity_sold', 0);

    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'predicted_demand' => '0.00', 'method' => 'additive_holt_winters']);
});

test('fully covered sparse history is unsuitable and preserves saved demand', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    forecastingSale($product, '2023-10-01', 3);
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '14.00']);
    $snapshot = $saved->fresh()->toArray();
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'history_unsuitable')
            ->where('readiness.covered_months', 36));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', 'history_unsuitable')
        ->assertInertiaFlash('forecast_result.message', 'This product’s monthly sales history is too sparse for this forecasting model. No forecast was saved.')
        ->assertInertiaFlash('forecast_result.monthly_forecasts', [])
        ->assertInertiaFlash('forecast_result.forecast_quantity', null);

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
});

test('readiness is rechecked during generation without overwriting saved demand', function (array $coverage, array $quantities, string $status) {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '14.00']);
    $snapshot = $saved->fresh()->toArray();
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('products.data.0.id', $product->id));

    forecastingCoverage($product, $coverage);
    forecastingMonthlySales($product, $quantities);
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.status', $status);

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
    $this->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 0)
            ->where('selected_product.id', $product->id)
            ->where('readiness.status', $status)
            ->where('forecasts.data.0.forecast_quantity', '14.00'));
})->with([
    'coverage becomes stale' => [['end_exclusive' => '2026-09-01'], [], 'history_unavailable'],
    'coverage becomes short' => [['start' => '2023-11-01'], [], 'insufficient_history'],
    'history becomes unsuitable' => [[], [1], 'history_unsuitable'],
]);

test('monthly development fixtures show synthetic scope and seasonal target estimates', function () {
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = Product::where('product_code', 'DEVHIST40REPEATING')->sole();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('readiness.is_synthetic', true)
            ->where('readiness.sales_scope', 'development_fixture_transactions')
            ->where('readiness.sales_scope_label', 'Synthetic development transactions'));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.is_synthetic', true)
        ->assertInertiaFlash('forecast_result.sales_scope', 'development_fixture_transactions')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '44.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.forecast_quantity', '18.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.1.forecast_quantity', '14.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.forecast_quantity', '12.00');

    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'method' => 'additive_holt_winters', 'predicted_demand' => '44.00']);
});

test('monthly display rounding never replaces the finalized quarterly total', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $result = forecastingZeroResult($product);
    foreach ($result['monthly_forecasts'] as &$month) {
        $month['raw_quantity'] = '10.004000000000';
        $month['usable_quantity'] = '10.004000000000';
    }
    unset($month);
    $result['forecast_quantity'] = '30.01';
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldReceive('execute')->once()->andReturn($result);

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.forecast_quantity', '10.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.1.forecast_quantity', '10.00')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.forecast_quantity', '10.00')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '30.01')
        ->assertInertiaFlash('forecast_result.rounding_note', 'Monthly estimates are rounded; the quarterly total is calculated before rounding.');

    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'predicted_demand' => '30.01']);
});

test('current inventory is separate planning context and does not change forecasts', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 0, 'last_updated' => '2026-10-14 10:00:00']);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('current_inventory.quantity', 0)
            ->where('current_inventory.last_updated', '2026-10-14T10:00:00+00:00'));
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.current_inventory.quantity', 0);

    $inventory->update(['quantity' => 500]);
    $this->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.forecast_quantity', '0.00')
        ->assertInertiaFlash('forecast_result.current_inventory.quantity', 500);

    $this->assertDatabaseCount('forecasts', 1);
});

test('invalid generation inputs report field errors without persistence', function (string $field, mixed $value, string $message) {
    $product = Product::factory()->create();
    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index'))
        ->post(route('administration.forecasting.store'), [...forecastingInput($product), $field => $value])
        ->assertSessionHasErrors([$field => $message])
        ->assertInertiaFlashMissing('forecast_result');
    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['product_id', null, 'Select a product.'],
    ['product_id', 'wrong', 'Select a valid product.'],
    ['product_id', 999999, 'The selected product no longer exists.'],
]);

test('generation rejects obsolete or extra configuration controls', function (string $field, mixed $value) {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), [...forecastingInput($product), $field => $value])
        ->assertSessionHasErrors([$field => 'Select a product only. Forecast method and dates are set automatically.'])
        ->assertInertiaFlashMissing('forecast_result');
    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['method', 'linear_trend'], ['method', 'moving_average'], ['method', 'additive_holt_winters'], ['method', null],
    ['alpha', 0.1], ['beta', 0.1], ['gamma', 0.1], ['alpha', null],
    ['seasonal_period', 12], ['horizon', 3], ['target', '2027-01-01'], ['window', 36],
    ['coverage_confirmed', true], ['coverage_confirmation', true],
    ['history_start', '2025-10-01'], ['history_confirmed', true],
    ['history_confirmed', false], ['window_size', 8], ['quarter_count', 8],
    ['target_quarter', '2027-Q1'], ['year', 2025], ['quarter', 1],
]);

test('application timezone controls monthly history and year rollover', function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'));
    $product = Product::factory()->create();
    forecastingCoverage($product, ['start' => '2024-01-01', 'end_exclusive' => '2027-01-01']);
    forecastingMonthlySales($product, array_fill(0, 36, 10), '2024-01-01');
    forecastingSale($product, '2027-01-01', 900);

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertInertiaFlash('forecast_result.timezone', 'Asia/Manila')
        ->assertInertiaFlash('forecast_result.target_label', 'Q1 2027')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '30.00')
        ->assertInertiaFlash('forecast_result.source_label', 'Jan 2024 – Dec 2026')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.0.label', 'Jan 2027')
        ->assertInertiaFlash('forecast_result.monthly_forecasts.2.label', 'Mar 2027');

    $this->assertDatabaseHas('forecasts', ['product_id' => $product->id, 'forecast_quarter' => '2027-Q1', 'predicted_demand' => '30.00']);
});

test('saved review reads all methods without recalculation or reconstructed monthly context', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldNotReceive('execute');
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');
    Forecast::factory()->for($product)->create(['method' => 'moving_average', 'predicted_demand' => '37.25']);
    Forecast::factory()->for($product)->create(['method' => 'linear_trend', 'predicted_demand' => '52.75']);
    Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '63.25']);
    forecastingMonthlySales($product, array_fill(0, 36, 999));
    $saved = Forecast::orderBy('id')->get()->toArray();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forecasts.data', 3)
            ->where('readiness.status', 'ready')
            ->where('forecasts.data.0.forecast_quantity', '63.25')
            ->where('forecasts.data.0.method_label', 'Additive Holt–Winters')
            ->where('forecasts.data.0.is_legacy', false)
            ->where('forecasts.data.0.source_label', 'Oct 2023 – Sep 2026 (inferred from 36-month method)')
            ->where('forecasts.data.1.forecast_quantity', '52.75')
            ->where('forecasts.data.1.method_label', 'Linear trend — legacy')
            ->where('forecasts.data.1.is_legacy', true)
            ->where('forecasts.data.1.source_label', 'Not retained with this saved forecast')
            ->where('forecasts.data.2.forecast_quantity', '37.25')
            ->where('forecasts.data.2.method_label', 'Moving average — legacy')
            ->where('forecasts.data.2.is_legacy', true)
            ->where('forecasts.data.2.source_label', 'Q4 2025 – Q3 2026 (inferred from four-quarter method)')
            ->missing('forecasts.data.0.observations')
            ->missing('forecasts.data.0.monthly_forecasts')
            ->missing('forecasts.data.0.parameters')
            ->missing('forecasts.data.1.observations')
            ->missing('forecasts.data.2.observations')
            ->missingFlash('forecast_result'));

    expect(Forecast::orderBy('id')->get()->toArray())->toBe($saved);
});

test('refreshing saved review does not recover generation time monthly context', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product));
    $this->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('forecast_result.monthly_forecasts'));
    $this->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->missingFlash('forecast_result')
            ->where('forecasts.data.0.forecast_quantity', '0.00')
            ->missing('forecasts.data.0.monthly_forecasts'));
});

test('product search and saved review retain inactive selections across pages', function () {
    $product = Product::factory()->for(Category::factory()->state(['is_active' => false]))
        ->create(['name' => 'ZZ Selected', 'product_code' => 'DEVHIST40STABLE', 'is_active' => false]);
    forecastingCoverage($product);
    $searchable = Product::factory()->count(16)->sequence(
        fn ($sequence): array => ['name' => sprintf('Searchable %02d', $sequence->index + 1)],
    )->create();
    foreach ($searchable as $ready) {
        forecastingCoverage($ready);
    }
    $short = Product::factory()->create(['name' => 'Searchable short history']);
    forecastingCoverage($short, ['start' => '2023-11-01']);
    Product::factory()->create(['name' => 'Searchable unavailable history']);
    $sparse = Product::factory()->create(['name' => 'Searchable sparse history']);
    forecastingCoverage($sparse);
    forecastingSale($sparse, '2023-10-01', 1);
    Forecast::factory()->for($product)->create(['method' => 'linear_trend']);
    Forecast::factory()->for($product)->create(['method' => 'moving_average']);
    Forecast::factory()->create(['method' => 'linear_trend']);
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->get(route('administration.forecasting.index', [
        'q' => 'Searchable', 'page' => 2, 'product_id' => $product->id,
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 1)
        ->where('products.total', 16)
        ->where('products.last_page', 2)
        ->where('products.data.0.id', $searchable->last()->id)
        ->where('selected_product.id', $product->id)
        ->where('selected_product.is_active', false)
        ->where('selected_product.is_synthetic', true)
        ->has('forecasts.data', 2)
        ->where('forecasts.data.0.method', 'moving_average')
        ->where('forecasts.data.1.method', 'linear_trend')
        ->missing('filters.method'));

    $this->get(route('administration.forecasting.index', ['q' => 'DEVHIST40STABLE']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $product->id));
    $this->get(route('administration.forecasting.index', ['q' => "' OR 1=1 --"]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    $this->get(route('administration.forecasting.index', ['q' => $short->product_code]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('products.total', 0));
});

test('eligible pagination crosses batches with bounded sales queries and deterministic ordering', function () {
    $products = Product::factory()->count(201)->for(Category::factory())
        ->sequence(fn ($sequence) => ['name' => sprintf('Ready %03d', $sequence->index)])
        ->create();
    foreach ($products as $product) {
        forecastingCoverage($product);
    }
    $excluded = Product::factory()->count(16)->sequence(
        fn ($sequence): array => ['name' => sprintf('Ready 000 unavailable %02d', $sequence->index + 1)],
    )->create();
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $response = $this->get(route('administration.forecasting.index', ['q' => 'Ready', 'page' => 14]));
        $salesQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'SUM(order_items.quantity)'));
        expect($salesQueries)->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
    }

    $response->assertInertia(fn (Assert $page) => $page
        ->where('products.total', 201)
        ->where('products.current_page', 14)
        ->where('products.last_page', 14)
        ->has('products.data', 6)
        ->where('products.data.0.id', $products[195]->id)
        ->where('products.data.5.id', $products[200]->id)
        ->where('filters.q', 'Ready'));
    expect(array_intersect(array_column($response->inertiaProps('products.data'), 'id'), $excluded->modelKeys()))->toBe([]);
    $this->get(route('administration.forecasting.index', ['q' => 'Ready', 'page' => 15]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('products.total', 201));
});

test('invalid review filters report validation errors', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index'))
        ->get(route('administration.forecasting.index', [$field => $value]))
        ->assertSessionHasErrors($field);

    $this->assertDatabaseEmpty('forecasts');
})->with([
    ['product_id', 'wrong'], ['product_id', 999999],
    ['method', 'linear_trend'], ['method', 'moving_average'],
]);

test('saved forecasts have deterministic independent pagination', function () {
    $product = Product::factory()->create();
    foreach (range(2000, 2015) as $year) {
        Forecast::factory()->for($product)->create(['forecast_quarter' => $year.'-Q1']);
    }
    $first = Forecast::oldest('id')->first();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['forecast_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forecasts.data', 1)
            ->where('forecasts.data.0.id', $first->id)
            ->where('forecasts.current_page', 2));
});

test('calculation failures preserve saved demand and report a safe error', function (string $failure) {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '12.00']);
    $snapshot = $saved->fresh()->toArray();
    $exception = $failure === 'arithmetic' ? new ArithmeticError('Internal arithmetic details') : new InvalidArgumentException('Internal input details');
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldReceive('execute')->once()->andThrow($exception);
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->from(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors(['forecast' => 'The forecast could not be calculated from the prepared monthly history. No forecast was saved.'])
        ->assertInertiaFlashMissing('forecast_result');

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
})->with(['arithmetic', 'input']);

test('unsuccessful calculations never reach persistence', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $result = forecastingZeroResult($product);
    $result['status'] = 'history_unavailable';
    $result['forecast_quantity'] = null;
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldReceive('execute')->once()->andReturn($result);
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors('forecast')
        ->assertInertiaFlashMissing('forecast_result');

    $this->assertDatabaseEmpty('forecasts');
});

test('persistence validation failures preserve saved demand', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '12.00']);
    $snapshot = $saved->fresh()->toArray();
    $result = forecastingZeroResult($product);
    $result['forecast_quantity'] = '0.01';
    $this->mock(CalculateAdditiveHoltWinters::class)->shouldReceive('execute')->once()->andReturn($result);

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved. Please refresh and try again.'])
        ->assertInertiaFlashMissing('forecast_result');

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
});

test('database failure after persistence rolls back demand and generation time', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    $saved = Forecast::factory()->for($product)->create([
        'method' => 'additive_holt_winters', 'predicted_demand' => '12.00', 'generated_at' => '2026-10-14 12:00:00',
    ]);
    $snapshot = $saved->fresh()->toArray();
    $this->mock(PersistForecast::class)->shouldReceive('execute')->once()->andReturnUsing(function (array $result) {
        (new PersistForecast)->execute($result);
        throw new QueryException('sqlite', 'select * from forecasts', [], new RuntimeException('Internal database failure'));
    });

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors(['forecast' => 'This forecast could not be saved. Please refresh and try again.'])
        ->assertInertiaFlashMissing('forecast_result');

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
});

test('oversized quarterly forecasts return a useful error without overwriting saved demand', function () {
    $product = Product::factory()->create();
    forecastingCoverage($product);
    forecastingMonthlySales($product, array_fill(0, 36, 4000000000));
    $saved = Forecast::factory()->for($product)->create(['method' => 'additive_holt_winters', 'predicted_demand' => '12.00']);
    $snapshot = $saved->fresh()->toArray();
    $this->mock(PersistForecast::class)->shouldNotReceive('execute');

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.forecasting.store'), forecastingInput($product))
        ->assertSessionHasErrors(['forecast' => 'This forecast exceeds the supported quantity and could not be saved.'])
        ->assertInertiaFlashMissing('forecast_result');

    expect($saved->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 1);
});
