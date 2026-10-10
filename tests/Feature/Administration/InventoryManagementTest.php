<?php

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing inventory', function () {
    $this->get(route('administration.inventory.index'))
        ->assertRedirect(route('login'));
});

test('customers are forbidden from viewing inventory', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('administration.inventory.index'))
        ->assertForbidden();
});

test('administrators can view initialized and uninitialized product inventory', function () {
    $administrator = User::factory()->administrator()->create();
    $initializedProduct = Product::factory()->create([
        'name' => 'AMD Ryzen 7 9700X',
        'brand' => 'AMD',
    ]);
    Inventory::factory()->for($initializedProduct)->create([
        'quantity' => 12,
        'reorder_level' => 4,
    ]);
    Product::factory()->inactive()->create([
        'name' => 'Legacy Graphics Card',
        'brand' => 'Legacy Brand',
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Inventory')
        ->where('filters.stock', 'all')
        ->where('low_stock_count', 0)
        ->where('out_of_stock_count', 0)
        ->has('products.data', 2)
        ->where('products.data.0.name', 'AMD Ryzen 7 9700X')
        ->where('products.data.0.is_low_stock', false)
        ->where('products.data.0.inventory.quantity', 12)
        ->where('products.data.0.inventory.reorder_level', 4)
        ->where('products.data.1.name', 'Legacy Graphics Card')
        ->where('products.data.1.is_active', false)
        ->where('products.data.1.is_low_stock', false)
        ->where('products.data.1.inventory', null));
});

test('administrators can filter products with low stock remaining', function () {
    $administrator = User::factory()->administrator()->create();
    $lowStockProduct = Product::factory()->create(['name' => 'Low Stock Product']);
    $inactiveLowStockProduct = Product::factory()->inactive()->create([
        'name' => 'Inactive Low Stock Product',
    ]);
    $boundaryProduct = Product::factory()->create(['name' => 'Boundary Product']);
    $availableProduct = Product::factory()->create(['name' => 'Available Product']);
    Product::factory()->create(['name' => 'Uninitialized Product']);
    Inventory::factory()->for($lowStockProduct)->create([
        'quantity' => 4,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($inactiveLowStockProduct)->create([
        'quantity' => 0,
        'reorder_level' => 1,
    ]);
    Inventory::factory()->for($boundaryProduct)->create([
        'quantity' => 5,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($availableProduct)->create([
        'quantity' => 6,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => 'low_stock']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Administration/Inventory')
        ->where('filters.stock', 'low_stock')
        ->where('low_stock_count', 2)
        ->where('out_of_stock_count', 1)
        ->where('products.total', 2)
        ->where('products.data.0.name', 'Boundary Product')
        ->where('products.data.0.is_low_stock', true)
        ->where('products.data.0.stock_status', 'low_stock')
        ->where('products.data.1.name', 'Low Stock Product'));
});

test('administrators can filter inventory by stock status', function (string $stock, string $expectedName) {
    $administrator = User::factory()->administrator()->create();
    $inStockProduct = Product::factory()->create(['name' => 'In Stock Product']);
    $lowStockProduct = Product::factory()->create(['name' => 'Low Stock Product']);
    $outOfStockProduct = Product::factory()->create(['name' => 'Out Of Stock Product']);
    Product::factory()->create(['name' => 'Uninitialized Product']);
    Inventory::factory()->for($inStockProduct)->create([
        'quantity' => 6,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($lowStockProduct)->create([
        'quantity' => 4,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->for($outOfStockProduct)->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => $stock]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.stock', $stock)
        ->where('products.total', 1)
        ->where('products.data.0.name', $expectedName)
        ->where('products.data.0.stock_status', $stock));
})->with([
    'in stock' => ['in_stock', 'In Stock Product'],
    'out of stock' => ['out_of_stock', 'Out Of Stock Product'],
    'not initialized' => ['not_initialized', 'Uninitialized Product'],
]);

test('invalid inventory filters are rejected', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->get(route('administration.inventory.index', [
            'category_id' => PHP_INT_MAX,
            'stock' => 'unexpected',
            'page' => 0,
        ]))
        ->assertRedirect(route('administration.inventory.index'))
        ->assertSessionHasErrors(['category_id', 'stock', 'page']);
});

test('zero quantity is out of stock even when the reorder level is zero', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Zero Threshold Product']);
    Inventory::factory()->for($product)->create(['quantity' => 0, 'reorder_level' => 0]);

    $this->actingAs($administrator)
        ->get(route('administration.inventory.index', ['stock' => 'out_of_stock']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('out_of_stock_count', 1)
            ->where('low_stock_count', 0)
            ->where('products.total', 1)
            ->where('products.data.0.stock_status', 'out_of_stock'));
});

test('administrators can search and filter inventory records', function () {
    $administrator = User::factory()->administrator()->create();
    $processors = Category::factory()->create(['name' => 'Processors']);
    $graphicsCards = Category::factory()->create(['name' => 'Graphics cards']);
    $target = Product::factory()->for($processors)->inactive()->create([
        'name' => 'Ryzen inventory item',
        'brand' => 'AMD',
    ]);
    Inventory::factory()->for($target)->create([
        'quantity' => 1,
        'reorder_level' => 2,
    ]);
    $availableProduct = Product::factory()->for($processors)->create([
        'name' => 'Ryzen available item',
        'brand' => 'AMD',
    ]);
    Inventory::factory()->for($availableProduct)->create([
        'quantity' => 3,
        'reorder_level' => 2,
    ]);
    $otherCategory = Product::factory()->for($graphicsCards)->create([
        'name' => 'Ryzen graphics card',
        'brand' => 'AMD',
    ]);
    Inventory::factory()->for($otherCategory)->create([
        'quantity' => 1,
        'reorder_level' => 2,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', [
            'q' => 'Ryzen',
            'category_id' => $processors->id,
            'stock' => 'low_stock',
        ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'Ryzen')
        ->where('filters.category_id', $processors->id)
        ->where('filters.stock', 'low_stock')
        ->has('products.data', 1)
        ->where('products.data.0.id', $target->id)
        ->where('products.data.0.is_active', false)
        ->where('filter_options.categories.1.name', 'Processors'));
});

test('inventory products are paginated in stable name order', function () {
    $administrator = User::factory()->administrator()->create();
    Product::factory()->count(26)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('Product %02d', 26 - $sequence->index),
        ],
    )->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('products.data', 25)
        ->where('products.total', 26)
        ->where('products.last_page', 2)
        ->where('products.data.0.name', 'Product 01')
        ->where('products.data.24.name', 'Product 25'));
});

test('inventory pagination preserves the active query', function () {
    $administrator = User::factory()->administrator()->create();
    $category = Category::factory()->create();
    Product::factory()
        ->count(26)
        ->for($category)
        ->sequence(fn ($sequence): array => ['name' => sprintf('Matching inventory product %02d', $sequence->index + 1)])
        ->has(Inventory::factory()->state([
            'quantity' => 1,
            'reorder_level' => 2,
        ]))
        ->create();

    $response = $this
        ->actingAs($administrator)
        ->get(route('administration.inventory.index', [
            'q' => 'Matching',
            'category_id' => $category->id,
            'stock' => 'low_stock',
        ]));

    $nextPageUrl = $response->inertiaProps('products.next_page_url');

    expect($nextPageUrl)->toContain('page=2')
        ->and($nextPageUrl)->toContain('q=Matching')
        ->and($nextPageUrl)->toContain("category_id={$category->id}")
        ->and($nextPageUrl)->toContain('stock=low');
});

test('guests cannot initialize inventory', function () {
    $product = Product::factory()->create();

    $this->post(route('administration.products.inventory.store', $product), [
        'quantity' => 10,
        'reorder_level' => 3,
    ])->assertRedirect(route('login'));

    $this->assertDatabaseMissing('inventories', [
        'product_id' => $product->id,
    ]);
});

test('customers cannot initialize inventory', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();

    $this->actingAs($customer)
        ->post(route('administration.products.inventory.store', $product), [
            'quantity' => 10,
            'reorder_level' => 3,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('inventories', [
        'product_id' => $product->id,
    ]);
});

test('administrators can initialize missing inventory', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index', [
            'q' => 'Temporary product search',
            'stock' => 'low_stock',
        ]))
        ->post(route('administration.products.inventory.store', $product), [
            'quantity' => 12,
            'reorder_level' => 4,
            'product_id' => $otherProduct->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.inventory.index'))
        ->assertInertiaFlash('toast.message', 'Inventory initialized.');
    $this->assertDatabaseHas('inventories', [
        'product_id' => $product->id,
        'quantity' => 12,
        'reorder_level' => 4,
    ]);
    $this->assertDatabaseMissing('inventories', [
        'product_id' => $otherProduct->id,
    ]);
});

test('invalid initial inventory values are rejected without creating inventory', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->post(route('administration.products.inventory.store', $product));

    $response
        ->assertRedirect(route('administration.inventory.index'))
        ->assertSessionHasErrors([
            'quantity' => 'Enter the current quantity.',
            'reorder_level' => 'Enter the reorder level.',
        ]);
    $this->assertDatabaseMissing('inventories', [
        'product_id' => $product->id,
    ]);
});

test('initializing inventory twice preserves the existing stock values', function () {
    $administrator = User::factory()->administrator()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->post(route('administration.products.inventory.store', $inventory->product), [
            'quantity' => 20,
            'reorder_level' => 6,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.inventory.index'))
        ->assertInertiaFlash('toast.message', 'Inventory is already initialized.');
    expect(Inventory::query()->where('product_id', $inventory->product_id)->count())->toBe(1);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
});

test('guests cannot update inventory', function () {
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $this->patch(route('administration.inventory.update', $inventory), [
        'quantity' => 20,
        'reorder_level' => 6,
    ])->assertRedirect(route('login'));

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
});

test('customers cannot update inventory', function () {
    $customer = User::factory()->customer()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $this->actingAs($customer)
        ->patch(route('administration.inventory.update', $inventory), [
            'quantity' => 20,
            'reorder_level' => 6,
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
});

test('administrators can update inventory stock values', function () {
    $administrator = User::factory()->administrator()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->patch(route('administration.inventory.update', $inventory), [
            'quantity' => 24,
            'reorder_level' => 8,
            'product_id' => Product::factory()->create()->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('administration.inventory.index'));
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'product_id' => $inventory->product_id,
        'quantity' => 24,
        'reorder_level' => 8,
    ]);
});

test('invalid inventory values are rejected without changing stock', function (array $payload, array $errors) {
    $administrator = User::factory()->administrator()->create();
    $inventory = Inventory::factory()->create([
        'quantity' => 10,
        'reorder_level' => 3,
    ]);

    $response = $this
        ->actingAs($administrator)
        ->from(route('administration.inventory.index'))
        ->patch(route('administration.inventory.update', $inventory), $payload);

    $response
        ->assertRedirect(route('administration.inventory.index'))
        ->assertSessionHasErrors($errors);
    $this->assertDatabaseHas('inventories', [
        'id' => $inventory->id,
        'quantity' => 10,
        'reorder_level' => 3,
    ]);
})->with([
    'required values' => [
        [],
        [
            'quantity' => 'Enter the current quantity.',
            'reorder_level' => 'Enter the reorder level.',
        ],
    ],
    'negative quantity' => [
        ['quantity' => -1, 'reorder_level' => 3],
        ['quantity' => 'Quantity must be zero or greater.'],
    ],
    'negative reorder level' => [
        ['quantity' => 10, 'reorder_level' => -1],
        ['reorder_level' => 'Reorder level must be zero or greater.'],
    ],
    'fractional values' => [
        ['quantity' => 1.5, 'reorder_level' => 2.5],
        [
            'quantity' => 'Quantity must be a whole number.',
            'reorder_level' => 'Reorder level must be a whole number.',
        ],
    ],
    'non-numeric values' => [
        ['quantity' => 'many', 'reorder_level' => 'few'],
        [
            'quantity' => 'Quantity must be a whole number.',
            'reorder_level' => 'Reorder level must be a whole number.',
        ],
    ],
    'values beyond storage range' => [
        ['quantity' => 4294967296, 'reorder_level' => 4294967296],
        [
            'quantity' => 'Quantity is too large.',
            'reorder_level' => 'Reorder level is too large.',
        ],
    ],
]);
