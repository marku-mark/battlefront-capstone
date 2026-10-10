<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from the customer cart', function () {
    $this->get(route('cart.index'))
        ->assertRedirectToRoute('login');
});

test('administrators are forbidden from the customer cart', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('cart.index'))
        ->assertForbidden();
});

test('customers see an explicit empty cart', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cart/Index')
            ->where('cart.items', [])
            ->where('cart.item_count', 0)
            ->where('cart.total_quantity', 0)
            ->where('cart.total', '0.00')
            ->where('cart.conflict_count', 0)
            ->where('is_personalized', false)
            ->where('has_featured_fallback', false)
            ->where('recommendations', []));
});

test('customers see only their authoritative cart prices totals and stock conflicts', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $regularProduct = Product::factory()->create([
        'name' => 'Battlefront Processor',
        'brand' => 'AMD',
        'price' => '100.00',
    ]);
    $discountedProduct = Product::factory()->create([
        'name' => 'Battlefront Graphics Card',
        'brand' => 'NVIDIA',
        'price' => '250.00',
        'discount_price' => '200.00',
    ]);
    $otherProduct = Product::factory()->create(['price' => '999.00']);
    Inventory::factory()->for($regularProduct)->create(['quantity' => 5]);
    $discountedInventory = Inventory::factory()
        ->for($discountedProduct)
        ->create(['quantity' => 3]);
    Inventory::factory()->for($otherProduct)->create(['quantity' => 5]);
    $cartService = new CartService;
    $regularItem = $cartService->add($customer, $regularProduct->id, 2);
    $discountedItem = $cartService->add($customer, $discountedProduct->id, 3);
    $cartService->add($otherCustomer, $otherProduct->id, 1);
    $discountedInventory->update(['quantity' => 1]);

    $response = $this->actingAs($customer)->get(route('cart.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Cart/Index')
        ->has('cart.items', 2)
        ->where('cart.items.0.id', $regularItem->id)
        ->where('cart.items.0.quantity', 2)
        ->where('cart.items.0.product.name', 'Battlefront Processor')
        ->where('cart.items.0.product.brand', 'AMD')
        ->where('cart.items.0.unit_price', '100.00')
        ->where('cart.items.0.line_total', '200.00')
        ->where('cart.items.0.availability.status', 'available')
        ->where('cart.items.0.availability.available_quantity', 5)
        ->where('cart.items.1.id', $discountedItem->id)
        ->where('cart.items.1.product.name', 'Battlefront Graphics Card')
        ->where('cart.items.1.product.discount_price', '200.00')
        ->where('cart.items.1.unit_price', '200.00')
        ->where('cart.items.1.line_total', '600.00')
        ->where('cart.items.1.availability.status', 'insufficient_stock')
        ->where('cart.items.1.availability.available_quantity', 1)
        ->where('cart.item_count', 2)
        ->where('cart.total_quantity', 5)
        ->where('cart.total', '800.00')
        ->where('cart.conflict_count', 1));

    expect(collect($response->inertiaProps('cart.items'))->pluck('product.id'))
        ->not->toContain($otherProduct->id);
});

test('catalog pages share the customer cart capability', function (string $account, bool $expected) {
    $product = Product::factory()->available()->create();

    if ($account === 'customer') {
        $this->actingAs(User::factory()->customer()->create());
    }

    if ($account === 'administrator') {
        $this->actingAs(User::factory()->administrator()->create());
    }

    $this->get(route('products.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.useCustomerCart', $expected));
})->with([
    'guest' => ['guest', false],
    'customer' => ['customer', true],
    'administrator' => ['administrator', false],
]);
