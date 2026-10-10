<?php

use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('web catalog records customer searches by default and only on the first page', function () {
    $this->travelTo('2026-10-08 12:00:00');

    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('products.index', ['q' => '  ryzen  ']))->assertOk();
    $this->actingAs($customer)->get(route('products.index', ['q' => 'ryzen', 'page' => 2]))->assertOk();

    $this->assertDatabaseCount('customer_searches', 1);
    $search = CustomerSearch::query()->sole();
    expect($search->query)->toBe('ryzen')
        ->and($search->expires_at->toDateTimeString())
        ->toBe(now()->addDays(90)->toDateTimeString());
});

test('normalized repeated catalog searches do not inflate retained search history', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);

    $this->actingAs($customer)->get(route('products.index', ['q' => 'Graphics-card']))->assertOk();
    $this->actingAs($customer)->get(route('products.index', ['q' => ' graphics card ']))->assertOk();

    $this->assertDatabaseCount('customer_searches', 1);
    expect(CustomerSearch::query()->sole()->query)->toBe('graphics card');
});

test('incremental query typing updates one recent search instead of storing every partial query', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);

    $this->actingAs($customer)->get(route('products.index', ['q' => 'graphics ca']))->assertOk();
    $this->actingAs($customer)->get(route('products.index', ['q' => 'graphics car']))->assertOk();
    $this->actingAs($customer)->get(route('products.index', ['q' => 'graphics card']))->assertOk();

    $this->assertDatabaseCount('customer_searches', 1);
    expect(CustomerSearch::query()->sole()->query)->toBe('graphics card');
});

test('guest searches are retained temporarily while opted-out customers and administrators are not tracked', function () {
    $customer = User::factory()->customer()->create(['search_recommendations_enabled' => false]);
    $administrator = User::factory()->administrator()->create([
        'search_recommendations_enabled' => true,
    ]);

    $this->get(route('products.index', ['q' => 'keyboard']))->assertOk();
    $this->actingAs($customer)->get(route('products.index', ['q' => 'mouse']))->assertOk();
    $this->actingAs($administrator)->get(route('products.index', ['q' => 'monitor']))->assertOk();

    $this->assertDatabaseCount('customer_searches', 1);
    $this->assertDatabaseHas('customer_searches', [
        'user_id' => null,
        'guest_recommendation_profile_id' => GuestRecommendationProfile::query()->sole()->id,
        'query' => 'keyboard',
    ]);
});

test('mobile catalog records an opted-in customer search from the bearer identity', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);
    $token = $customer->createToken('test-device')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.products.index', ['q' => 'graphics card']))->assertOk();

    $this->assertDatabaseHas('customer_searches', [
        'user_id' => $customer->id,
        'query' => 'graphics card',
    ]);
});

test('profile settings let customers control search-based recommendation consent', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('searchRecommendationsEnabled', true)
            ->where('productViewRecommendationsEnabled', true));

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'search_recommendations_enabled' => '1',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->search_recommendations_enabled)->toBeTrue();

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'search_recommendations_enabled' => '0',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->search_recommendations_enabled)->toBeFalse();
});

test('withdrawing search recommendation consent pauses tracking and retains unexpired searches', function () {
    $customer = User::factory()->customer()->create([
        'search_recommendations_enabled' => true,
    ]);
    CustomerSearch::factory()->count(2)->for($customer)->create();

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'search_recommendations_enabled' => '0',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->search_recommendations_enabled)->toBeFalse();
    $this->assertDatabaseCount('customer_searches', 2);
});

test('the scheduled cleanup command removes expired searches and retains current ones', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $expired = CustomerSearch::factory()->create(['expires_at' => now()->subSecond()]);
    $current = CustomerSearch::factory()->create(['expires_at' => now()->addSecond()]);
    $expiredProductView = CustomerProductView::factory()->create(['expires_at' => now()->subSecond()]);
    $currentProductView = CustomerProductView::factory()->create(['expires_at' => now()->addSecond()]);

    $this->artisan('app:prune-expired-customer-searches')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($current);
    $this->assertModelMissing($expiredProductView);
    $this->assertModelExists($currentProductView);
});

test('global personalization pause stops both signals while retaining existing activity', function () {
    $customer = User::factory()->customer()->create(['personalized_recommendations_enabled' => false]);
    $search = CustomerSearch::factory()->for($customer)->create();
    $view = CustomerProductView::factory()->for($customer)->create();
    $this->actingAs($customer)->get(route('products.index', ['q' => 'new private query']))->assertOk();
    $this->get(route('products.show', Product::factory()->available()->create()))->assertOk();
    $this->assertDatabaseCount('customer_searches', 1);
    $this->assertDatabaseCount('customer_product_views', 1);
    $this->assertModelExists($search);
    $this->assertModelExists($view);
});
