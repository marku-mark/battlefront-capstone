<?php

use App\Models\Forecast;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\HistoricalSalesCatalogFixtures;

beforeEach(function () {
    Storage::fake('local');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

test('monthly generation preserves legacy forecasts without changing their saved values', function () {
    $products = HistoricalSalesCatalogFixtures::createProducts();
    $this->seed(DevelopmentHistoricalSalesSeeder::class);
    $product = $products['10003'];
    $legacy = Forecast::factory()->for($product)->create(['method' => 'moving_average', 'forecast_quarter' => '2026-Q4', 'predicted_demand' => '12.00']);
    $snapshot = $legacy->fresh()->toArray();
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.status', 'ready')
            ->where('readiness.covered_months', 36));
    $this->post(route('administration.forecasting.store'), ['product_id' => $product->id])
        ->assertInertiaFlash('forecast_result.status', 'ready')
        ->assertInertiaFlash('forecast_result.method', 'additive_holt_winters')
        ->assertInertiaFlash('forecast_result.forecast_quantity', '7.10');
    $this->assertDatabaseHas('forecasts', [
        'product_id' => $product->id, 'method' => 'additive_holt_winters',
        'forecast_quarter' => '2026-10/2026-12', 'predicted_demand' => '7.10',
    ]);
    expect($legacy->fresh()->toArray())->toBe($snapshot);
    $this->assertDatabaseCount('forecasts', 2);
});
