<?php

use App\Models\CustomerProductView;
use App\Models\GuestRecommendationProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('web product views are retained by default and duplicate refreshes are suppressed for 30 minutes', function () {
    $this->travelTo('2026-10-08 12:00:00');

    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();
    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();

    $this->assertDatabaseCount('customer_product_views', 1);
    $view = CustomerProductView::query()->sole();
    expect($view->product_id)->toBe($product->id)
        ->and($view->expires_at->toDateTimeString())
        ->toBe(now()->addDays(90)->toDateTimeString());
});

test('web prefetch is not a view but consuming cached product details records one owned view', function (string $header) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->withHeader($header, 'prefetch')->get(route('products.show', $product))->assertOk();
    $this->assertDatabaseCount('customer_product_views', 0);
    $this->flushHeaders();
    $this->postJson(route('products.view.store', $product))->assertNoContent();
    $this->postJson(route('products.view.store', $product))->assertNoContent();
    $this->assertDatabaseCount('customer_product_views', 1);
    $this->assertDatabaseHas('customer_product_views', ['user_id' => $customer->id, 'product_id' => $product->id]);
})->with(['Purpose', 'Sec-Purpose', 'X-Moz']);

test('mobile prefetch never records views and deliberate detail requests do', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->withToken($customer->createToken('Phone')->plainTextToken)
        ->withHeader('Purpose', 'prefetch')->getJson(route('api.v1.products.show', $product))->assertOk();
    $this->assertDatabaseCount('customer_product_views', 0);
    $this->flushHeaders();
    $this->withToken($customer->createToken('Phone')->plainTextToken)->getJson(route('api.v1.products.show', $product))->assertOk();
    $this->assertDatabaseCount('customer_product_views', 1);
});

test('guest prefetch and cached consumption retain only the current browser profile activity', function () {
    $product = Product::factory()->available()->create();
    $response = $this->withHeader('Purpose', 'prefetch')->get(route('products.show', $product))->assertOk();
    $this->assertDatabaseCount('customer_product_views', 0);
    $cookie = $response->getCookie('battlefront_recommendation_profile', decrypt: false);
    $this->flushHeaders();
    $this->withCredentials()->withUnencryptedCookie('battlefront_recommendation_profile', $cookie->getValue())
        ->postJson(route('products.view.store', $product))->assertNoContent();
    $this->assertDatabaseHas('customer_product_views', ['user_id' => null, 'product_id' => $product->id]);
});

test('view recording rejects administrators and unavailable catalog identities', function () {
    $product = Product::factory()->available()->create();
    $this->actingAs(User::factory()->administrator()->create())->postJson(route('products.view.store', $product))->assertForbidden();
    $this->actingAs(User::factory()->customer()->create())->postJson(route('products.view.store', 999999))->assertNotFound();
    $product->update(['is_active' => false]);
    $this->postJson(route('products.view.store', $product))->assertNotFound();
    $this->assertDatabaseCount('customer_product_views', 0);
});

test('mobile product views use the authenticated bearer identity', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $product = Product::factory()->available()->create();
    $token = $customer->createToken('test-device')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.products.show', $product))->assertOk();

    $this->assertDatabaseHas('customer_product_views', [
        'user_id' => $customer->id,
        'product_id' => $product->id,
    ]);
});

test('guest product views are retained temporarily while opted-out customers and administrators are not tracked', function () {
    $customer = User::factory()->customer()->create(['product_view_recommendations_enabled' => false]);
    $administrator = User::factory()->administrator()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    $product = Product::factory()->available()->create();

    $this->get(route('products.show', $product))->assertOk();
    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();
    $this->actingAs($administrator)->get(route('products.show', $product))->assertOk();

    $this->assertDatabaseCount('customer_product_views', 1);
    $this->assertDatabaseHas('customer_product_views', [
        'user_id' => null,
        'guest_recommendation_profile_id' => GuestRecommendationProfile::query()->sole()->id,
        'product_id' => $product->id,
    ]);
});

test('profile settings expose an independent product view recommendation consent', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('productViewRecommendationsEnabled', true));

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'product_view_recommendations_enabled' => '1',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->product_view_recommendations_enabled)->toBeTrue();
});

test('withdrawing product view consent pauses tracking and retains unexpired views', function () {
    $customer = User::factory()->customer()->create([
        'product_view_recommendations_enabled' => true,
    ]);
    CustomerProductView::factory()->count(2)->for($customer)->create();

    $this->actingAs($customer)->patch(route('profile.update'), [
        'name' => $customer->name,
        'email' => $customer->email,
        'product_view_recommendations_enabled' => '0',
    ])->assertSessionHasNoErrors();

    expect($customer->refresh()->product_view_recommendations_enabled)->toBeFalse();
    $this->assertDatabaseCount('customer_product_views', 2);
});

test('the retention command removes expired product views and keeps unexpired ones', function () {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $expired = CustomerProductView::factory()->create(['expires_at' => now()->subSecond()]);
    $current = CustomerProductView::factory()->create(['expires_at' => now()->addSecond()]);

    $this->artisan('app:prune-expired-customer-searches')->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelExists($current);
});
