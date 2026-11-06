<?php

use App\Mail\VerifyEmailOtp;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('a guest wishlist save is resumed once after customer login', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();

    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id])
        ->assertRedirectToRoute('login')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id)
        ->assertSessionHas('pending_wishlist_intent.expires_at', now()->addMinutes(15)->getTimestamp());

    expect(session('pending_wishlist_intent.token'))->toBeUuid();
    $this->assertDatabaseCount('wishlist_items', 0);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.'])
        ->assertSessionMissing('pending_wishlist_intent')
        ->assertSessionMissing('url.intended');

    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
    $this->assertDatabaseCount('wishlist_activities', 1);
});

test('a guest wishlist save survives registration until email verification signs the customer in', function () {
    Mail::fake();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);

    $this->post(route('register.store'), [
        'name' => 'Wishlist Customer',
        'email' => 'wishlist-customer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirectToRoute('verification.notice')
        ->assertSessionHas('pending_wishlist_intent.product_id', $product->id);

    $this->post(route('verification.verify'), [
        'code' => Mail::sent(VerifyEmailOtp::class)->sole()->code,
    ])->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product saved to your wishlist.'])
        ->assertSessionMissing('pending_wishlist_intent');

    $customer = User::query()->where('email', 'wishlist-customer@example.com')->sole();
    $this->assertAuthenticatedAs($customer);
    $this->assertDatabaseHas('wishlist_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
});

test('guest save rejects invalid product ids without preserving an intent', function (mixed $productId) {
    $this->post(route('wishlist.intent.store'), ['product_id' => $productId])
        ->assertSessionHasErrors('product_id')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
})->with([null, 'invalid', 999]);

test('inactive product is not saved when the customer completes login', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $product->update(['is_active' => false]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('wishlist.index')
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This product cannot be saved right now.'])
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
});

test('expired wishlist intent does not change the normal login redirect', function () {
    $this->freezeTime();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.intent.store'), ['product_id' => $product->id]);
    $this->travel(15)->minutes();

    $this->withSession(['url.intended' => route('products.index')])
        ->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirectToRoute('products.index')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
});

test('authenticated accounts cannot submit a guest wishlist intent', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($user)->post(route('wishlist.intent.store'), ['product_id' => $product->id])
        ->assertRedirectToRoute('dashboard')
        ->assertSessionMissing('pending_wishlist_intent');

    $this->assertDatabaseCount('wishlist_items', 0);
})->with(['customer', 'administrator']);
