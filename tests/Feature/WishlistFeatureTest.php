<?php

use App\Models\CartItem;
use App\Models\PcBuildCartGroup;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistActivity;
use App\Models\WishlistItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('customers can save once remove and view only their own wishlist', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();

    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 1);
    $this->assertDatabaseCount('wishlist_activities', 1);
    $this->actingAs($otherCustomer)->get(route('wishlist.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Wishlist/Index')->where('wishlist.count', 0));
    $this->actingAs($customer)->get(route('wishlist.index'))->assertInertia(fn ($page) => $page
        ->where('wishlist.count', 1)
        ->where('wishlist.items.data.0.is_in_cart', false));
    $this->actingAs($customer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('wishlist_items', 1);
    $this->actingAs($customer)->get(route('wishlist.index'))->assertInertia(fn ($page) => $page->where('wishlist.items.data.0.is_in_cart', true));
    $this->actingAs($customer)->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 2);
    expect(WishlistActivity::query()->where('event_type', 'removed')->exists())->toBeTrue();
});

test('wishlist cart presence includes owned pc build components and excludes other customers carts', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $component = Product::factory()->available()->create();
    $otherProduct = Product::factory()->available()->create();
    $cart = $customer->cart()->create();
    $group = PcBuildCartGroup::factory()->for($cart)->create();
    CartItem::factory()->for($cart)->for($component)->create(['pc_build_cart_group_id' => $group->id]);
    CartItem::factory()->for($otherCustomer->cart()->create())->for($otherProduct)->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $component->id]);
    $this->post(route('wishlist.store'), ['product_id' => $otherProduct->id]);

    $this->get(route('wishlist.index'))->assertInertia(fn ($page) => $page
        ->where('wishlist.items.data.0.product_id', $otherProduct->id)
        ->where('wishlist.items.data.0.is_in_cart', false)
        ->where('wishlist.items.data.1.product_id', $component->id)
        ->where('wishlist.items.data.1.is_in_cart', true));

    $this->withToken($customer->createToken('Wishlist phone')->plainTextToken)
        ->getJson(route('api.v1.wishlist.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.is_in_cart', false)
        ->assertJsonPath('data.1.is_in_cart', true)
        ->assertJsonPath('count', 2)
        ->assertJsonStructure(['data', 'links', 'meta', 'count']);
});

test('heart responses refresh counts without reloading catalog products or recommendations', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $initial = $this->actingAs($customer)->get(route('products.index'));
    $this->actingAs($customer)->from(route('products.index'))
        ->post(route('wishlist.store'), ['product_id' => $product->id])
        ->assertRedirect(route('products.index'));

    $this->get(route('products.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $initial->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Products/Index',
        'X-Inertia-Partial-Data' => 'wishlistCount',
    ])->assertOk()
        ->assertJsonPath('props.wishlistCount', 1)
        ->assertJsonMissingPath('props.products')
        ->assertJsonMissingPath('props.recommendations');

    $this->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 0);
    $this->assertDatabaseCount('wishlist_activities', 2);
});

test('out of stock wishlist products stay saved and failed cart additions do not alter them', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);
    $product->inventory()->update(['quantity' => 0]);

    $this->get(route('wishlist.index'))->assertInertia(fn ($page) => $page
        ->where('wishlist.items.data.0.is_available', true)
        ->where('wishlist.items.data.0.can_add_to_cart', false)
        ->where('wishlist.items.data.0.is_in_cart', false));

    $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
        ->assertSessionHasErrors('quantity');
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseCount('wishlist_items', 1);
    $this->assertDatabaseCount('wishlist_activities', 1);
});

test('wishlist actions require an authenticated customer and reject unavailable products', function () {
    $product = Product::factory()->available()->create();
    $this->post(route('wishlist.store'), ['product_id' => $product->id])->assertRedirect(route('login'));

    $customer = User::factory()->customer()->create();
    $unavailable = Product::factory()->create(['is_active' => false]);
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $unavailable->id])->assertSessionHasErrors('product_id');
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('wishlist pagination clamps to the last page after the final item is removed', function () {
    $customer = User::factory()->customer()->create();

    foreach (range(1, 13) as $index) {
        $product = Product::factory()->available()->create();
        WishlistItem::query()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'save_cycle_id' => (string) Str::uuid(),
        ]);
    }

    $this->actingAs($customer)->get(route('wishlist.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.count', 13)
            ->where('wishlist.items.current_page', 2)
            ->where('wishlist.items.last_page', 2));

    $lastItem = WishlistItem::query()->where('user_id', $customer->id)->orderByDesc('id')->firstOrFail();
    $this->actingAs($customer)->delete(route('wishlist.destroy', $lastItem->product_id))->assertRedirect();

    $this->actingAs($customer)->get(route('wishlist.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.count', 12)
            ->where('wishlist.items.current_page', 1)
            ->where('wishlist.items.last_page', 1));
});

test('soft deleted products remain removable and are presented as unavailable', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);

    $product->delete();

    $this->actingAs($customer)->get(route('wishlist.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wishlist.items.data.0.is_available', false)
            ->where('wishlist.items.data.0.product', null));

    $this->actingAs($customer)->delete(route('wishlist.destroy', $product->id))->assertRedirect();
    $this->assertDatabaseCount('wishlist_items', 0);
});

test('admin wishlist analytics require reporting permission', function () {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer)->get(route('administration.reports.wishlist'))->assertForbidden();
    $this->actingAs($customer)->get(route('administration.reports.wishlist.csv'))->assertForbidden();
});

test('authorized report users receive aggregated wishlist analytics and csv export', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);

    $this->actingAs($owner)->get(route('administration.reports.wishlist'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Administration/Reports/Wishlist')
            ->where('overview.entries', 1)
            ->where('overview.customers', 1)
            ->where('overview.products', 1)
            ->has('activity.daily')
            ->where('conversion.wishlist_to_cart', null));
    $this->actingAs($owner)->get(route('administration.reports.wishlist.csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('wishlist report applies product filters to totals activity rows and csv', function () {
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create(['discount_price' => '499.00']);
    $otherProduct = Product::factory()->available()->create();
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $product->id]);
    $this->actingAs($customer)->post(route('wishlist.store'), ['product_id' => $otherProduct->id]);
    $today = now(config('battlefront.timezone'))->toDateString();
    $filters = ['from' => $today, 'to' => $today, 'q' => $product->name];

    $this->actingAs($owner)->get(route('administration.reports.wishlist', $filters))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('overview.entries', 1)
            ->where('overview.customers', 1)
            ->where('overview.products', 1)
            ->where('activity.additions', 1)
            ->has('products.data', 1));

    $response = $this->actingAs($owner)->get(route('administration.reports.wishlist.csv', $filters))->assertOk();
    expect($response->streamedContent())->toContain($product->name, '499.00');
    expect($response->streamedContent())->not->toContain($otherProduct->name);
});

test('wishlist report rejects a date range longer than 365 days with a field error', function () {
    $owner = User::factory()->administrator()->create();

    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => now(config('battlefront.timezone'))->subDays(365)->toDateString(),
        'to' => now(config('battlefront.timezone'))->toDateString(),
    ]))->assertRedirect()->assertSessionHasErrors('to');

    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => now(config('battlefront.timezone'))->subDays(365)->toDateString(),
    ]))->assertRedirect()->assertSessionHasErrors('to');
});

test('wishlist report marks interest increasing only when both periods have a positive baseline', function (string $localTime) {
    config(['battlefront.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse($localTime, 'Asia/Manila'));
    $owner = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->available()->create();
    $yesterday = CarbonImmutable::now(config('battlefront.timezone'))->subDay()->startOfDay()->utc();
    WishlistItem::query()->create([
        'user_id' => $customer->id,
        'product_id' => $product->id,
        'save_cycle_id' => (string) Str::uuid(),
    ]);

    WishlistActivity::query()->create([
        'save_cycle_id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'event_type' => 'added',
        'occurred_at' => $yesterday,
    ]);

    foreach (range(1, 3) as $addition) {
        WishlistActivity::query()->create([
            'save_cycle_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'event_type' => 'added',
            'occurred_at' => now('UTC'),
        ]);
    }

    $today = now(config('battlefront.timezone'))->toDateString();
    $this->actingAs($owner)->get(route('administration.reports.wishlist', [
        'from' => $today,
        'to' => $today,
        'minimum_saves' => 1,
        'minimum_net_additions' => 2,
        'minimum_increase_percent' => 20,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('activity.additions', 3)
        ->where('activity.previous.additions', 1)
        ->where('coverage.complete_for_period', true)
        ->where('increasing_interest.0.id', $product->id)
        ->where('increasing_interest.0.demand.increase_percent', 200));
})->with([
    'after Manila midnight' => '2026-10-30 00:15:00',
    'before UTC midnight' => '2026-10-30 07:59:59',
    'at UTC midnight' => '2026-10-30 08:00:00',
    'before Manila midnight' => '2026-10-30 23:59:59',
]);
