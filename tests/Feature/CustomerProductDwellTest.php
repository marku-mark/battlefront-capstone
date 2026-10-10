<?php

use App\Models\CustomerProductView;
use App\Models\GuestRecommendationProfile;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;

test('delayed dwell remains recordable after a viewed product sells out', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->get(route('products.show', $product))->assertOk();
    $view = $customer->productViews()->sole();
    Inventory::query()->where('product_id', $product->id)->update(['quantity' => 0]);

    $this->postJson(route('products.dwell.store', $product), ['seconds' => 60])->assertNoContent();

    expect($view->refresh()->dwell_seconds)->toBe(60);
    $this->get(route('products.show', $product))->assertNotFound();
});

test('dwell updates only the current owner latest view and never decreases its duration', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    $foreign = CustomerProductView::factory()->for($product)->create(['dwell_seconds' => 90]);
    $older = CustomerProductView::factory()->for($customer)->for($product)->create(['created_at' => now()->subHour()]);
    $current = CustomerProductView::factory()->for($customer)->for($product)->create(['expires_at' => now()->addDay()]);
    $this->actingAs($customer)->postJson(route('products.dwell.store', $product), ['seconds' => 60])->assertNoContent();
    $this->postJson(route('products.dwell.store', $product), ['seconds' => 10])->assertNoContent();
    expect($current->refresh()->dwell_seconds)->toBe(60)
        ->and($current->expires_at->toDateTimeString())->toBe(now()->addDays(90)->toDateTimeString())
        ->and($older->refresh()->dwell_seconds)->toBe(0)
        ->and($foreign->refresh()->dwell_seconds)->toBe(90);
});

test('dwell rejects invalid durations without changing history', function (mixed $seconds) {
    $customer = User::factory()->customer()->create();
    $view = CustomerProductView::factory()->for($customer)->create();
    $this->actingAs($customer)->postJson(route('products.dwell.store', $view->product_id), ['seconds' => $seconds])
        ->assertUnprocessable()->assertJsonValidationErrors('seconds');
    expect($view->refresh()->dwell_seconds)->toBe(0);
})->with([[null], [0], [4], [3601], [5.5], ['text'], [[5]]]);

test('paused personalization or viewing leaves retained dwell unchanged', function (string $preference) {
    $customer = User::factory()->customer()->create([$preference => false]);
    $view = CustomerProductView::factory()->for($customer)->create(['dwell_seconds' => 15]);
    $this->actingAs($customer)->postJson(route('products.dwell.store', $view->product_id), ['seconds' => 50])->assertNoContent();
    expect($view->refresh()->dwell_seconds)->toBe(15);
})->with(['personalized_recommendations_enabled', 'product_view_recommendations_enabled']);

test('expired or missing views and prefetch requests do not gain dwell', function () {
    $customer = User::factory()->customer()->create();
    $expired = CustomerProductView::factory()->for($customer)->create(['expires_at' => now()->subSecond()]);
    $current = CustomerProductView::factory()->for($customer)->create();
    $this->actingAs($customer)->postJson(route('products.dwell.store', $expired->product_id), ['seconds' => 60])->assertNoContent();
    $this->postJson(route('products.dwell.store', Product::factory()->create()), ['seconds' => 60])->assertNoContent();
    $this->withHeader('Purpose', 'prefetch')->postJson(route('products.dwell.store', $current->product_id), ['seconds' => 60])->assertNoContent();
    expect($expired->refresh()->dwell_seconds)->toBe(0)->and($current->refresh()->dwell_seconds)->toBe(0);
});

test('guest dwell belongs only to the opaque browser profile', function () {
    $token = 'guest-dwell-token';
    $profile = GuestRecommendationProfile::factory()->create(['token_hash' => hash('sha256', $token)]);
    $product = Product::factory()->create();
    $view = $profile->productViews()->create(['product_id' => $product->id, 'expires_at' => now()->addDay()]);
    $this->withCredentials()->withCookie('battlefront_recommendation_profile', $token)
        ->postJson(route('products.dwell.store', $product), ['seconds' => 3600])->assertNoContent();
    expect($view->refresh()->dwell_seconds)->toBe(3600)->and($view->user_id)->toBeNull();
});

test('administrators cannot submit product dwell', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->postJson(route('products.dwell.store', Product::factory()->create()), ['seconds' => 60])->assertForbidden();
});
