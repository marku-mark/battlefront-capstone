<?php

use App\Mail\VerifyEmailOtp;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function directPurchaseProduct(): Product
{
    $product = Product::factory()->standard()->create();
    Inventory::factory()->for($product)->create(['quantity' => 10]);

    return $product;
}

test('guest buy now retains selection and checkout destination without adding cart rows', function () {
    $product = directPurchaseProduct();
    $this->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2])->assertRedirect(route('login'));
    expect(session('direct_purchase.quantity'))->toBe(2);
    expect(session('url.intended'))->toContain('direct_purchase_key=');
    $this->assertDatabaseCount('cart_items', 0);
});

test('buy now purchases its selection independently of the customer cart', function (bool $withCart) {
    config(['services.paymongo.enabled' => false]);
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $item = $withCart ? app(CartService::class)->add($customer, $product->id, 3) : null;
    $response = $this->actingAs($customer)->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $key = session('direct_purchase.key');
    $response->assertRedirect(route('checkout.index', ['direct_purchase_key' => $key]));
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Checkout/Index')->where('cart.total_quantity', 2));
    $this->post(route('orders.store'), ['direct_purchase_key' => $key, 'recipient_name' => $customer->name, 'contact_number' => '09171234567', 'fulfillment_method' => 'pickup', 'payment_method' => 'cash'])->assertSessionHasNoErrors()->assertRedirect();
    if ($item !== null) {
        expect($item->refresh()->quantity)->toBe(3);
    } else {
        $this->assertDatabaseCount('cart_items', 0);
    }
    expect($product->inventory()->first()->quantity)->toBe(8);
    $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2]);
    expect(session('direct_purchase'))->toBeNull();
})->with([true, false]);

test('buy now rejects unavailable quantities and expired selections', function () {
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $this->actingAs($customer)->postJson(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 11])->assertUnprocessable();
    $this->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 1]);
    $key = session('direct_purchase.key');
    $this->travel(16)->minutes();
    $this->getJson(route('checkout.index', ['direct_purchase_key' => $key]))->assertUnprocessable();
    $this->assertDatabaseCount('orders', 0);
});

test('guest buy now resumes after customer login', function () {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $this->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $destination = session('url.intended');
    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertRedirect($destination);
    $this->get($destination)->assertOk();
    $this->assertDatabaseCount('cart_items', 0);
});

test('buy now refreshes prices and announces a change before purchase', function () {
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $response = $this->actingAs($customer)->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $product->update(['price' => '1234.00', 'discount_price' => null]);
    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Checkout/Index')->where('cart.total', '2468.00')->where('cart.price_notice', 'The product price has changed. Review the current price before placing your order.'));
});

test('guest buy now survives registration and email verification before checkout', function () {
    Mail::fake();
    $this->seed(BranchSeeder::class);
    $product = directPurchaseProduct();
    $this->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $destination = session('url.intended');
    $this->post(route('register.store'), ['name' => 'New Buyer', 'email' => 'buyer@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirectToRoute('verification.notice');
    $this->assertGuest();
    $code = Mail::sent(VerifyEmailOtp::class)->sole()->code;
    $this->post(route('verification.verify'), ['code' => $code])->assertRedirect($destination);
    $customer = User::query()->where('email', 'buyer@example.test')->sole();
    $this->assertAuthenticatedAs($customer);
    $this->get($destination)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Checkout/Index')->where('cart.total_quantity', 2));
    $this->assertDatabaseCount('cart_items', 0);
    Mail::assertSentCount(1);
});

test('repeated buy now order submissions cannot create another order or deduct stock again', function () {
    config(['services.paymongo.enabled' => false]);
    $this->seed(BranchSeeder::class);
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $this->actingAs($customer)->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    $input = ['direct_purchase_key' => session('direct_purchase.key'), 'checkout_submission_key' => (string) Str::uuid(),
        'recipient_name' => $customer->name, 'contact_number' => '09171234567', 'fulfillment_method' => 'pickup', 'payment_method' => 'cash'];
    $this->postJson(route('orders.store'), $input)->assertRedirect();
    $this->postJson(route('orders.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    expect($product->inventory()->first()->quantity)->toBe(8);
});

test('buy now rejects noncustomer accounts and another customers session selection', function () {
    $product = directPurchaseProduct();
    $this->actingAs(User::factory()->administrator()->create())->postJson(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 1])->assertForbidden();
    $this->actingAs(User::factory()->customer()->create())->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 1]);
    $key = session('direct_purchase.key');
    $this->actingAs(User::factory()->customer()->create())->getJson(route('checkout.index', ['direct_purchase_key' => $key]))->assertUnprocessable();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 0);
});

test('browser buy now recovery returns to the product when stock changes or selection expires', function (bool $expired) {
    $customer = User::factory()->customer()->create();
    $product = directPurchaseProduct();
    $response = $this->actingAs($customer)->post(route('buy-now.store'), ['product_id' => $product->id, 'quantity' => 2]);
    if ($expired) {
        $this->travel(16)->minutes();
    } else {
        $product->inventory()->update(['quantity' => 0]);
    }
    $this->get($response->headers->get('Location'))->assertRedirectToRoute('products.show', $product)->assertInertiaFlash('toast.type', 'error');
    expect(session('direct_purchase'))->toBeNull();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 0);
})->with([false, true]);
