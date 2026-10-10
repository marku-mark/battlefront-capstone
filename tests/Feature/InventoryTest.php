<?php

use App\Models\Inventory;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the inventory schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('inventories'))->toEqualCanonicalizing([
        'id',
        'product_id',
        'quantity',
        'reorder_level',
        'last_updated',
    ]);
});

test('inventory persists valid stock values and casts', function () {
    $inventory = Inventory::factory()->create([
        'quantity' => 0,
        'reorder_level' => 0,
    ]);

    $this->assertModelExists($inventory);
    expect($inventory->quantity)->toBe(0)
        ->and($inventory->quantity)->toBeInt()
        ->and($inventory->reorder_level)->toBe(0)
        ->and($inventory->reorder_level)->toBeInt()
        ->and($inventory->last_updated)->toBeInstanceOf(CarbonInterface::class);
});

test('a product has at most one inventory record', function () {
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create();

    expect($inventory->product->is($product))->toBeTrue()
        ->and($product->inventory->is($inventory))->toBeTrue()
        ->and(fn () => Inventory::factory()->for($product)->create())
        ->toThrow(QueryException::class)
        ->and(fn () => $product->delete())
        ->toThrow(QueryException::class);
});

test('a product may exist without an inventory record', function () {
    $product = Product::factory()->create();

    expect($product->inventory)->toBeNull();
});

test('low stock includes only positive quantities at or below their reorder level', function () {
    $lowStockInventory = Inventory::factory()->create([
        'quantity' => 4,
        'reorder_level' => 5,
    ]);
    $boundaryInventory = Inventory::factory()->create([
        'quantity' => 5,
        'reorder_level' => 5,
    ]);
    Inventory::factory()->create([
        'quantity' => 6,
        'reorder_level' => 5,
    ]);
    $outOfStockWithZeroThreshold = Inventory::factory()->create([
        'quantity' => 0,
        'reorder_level' => 0,
    ]);
    $outOfStockInventory = Inventory::factory()->create([
        'quantity' => 0,
        'reorder_level' => 5,
    ]);

    $lowStockInventoryIds = Inventory::query()->lowStock()->pluck('id')->all();

    expect($lowStockInventoryIds)->toBe([$lowStockInventory->id, $boundaryInventory->id]);
    expect(Inventory::query()->outOfStock()->pluck('id')->all())
        ->toEqualCanonicalizing([$outOfStockWithZeroThreshold->id, $outOfStockInventory->id]);
});

test('products expose low stock through their constrained inventory relationship', function () {
    $lowStockProduct = Product::factory()
        ->has(Inventory::factory()->state([
            'quantity' => 4,
            'reorder_level' => 5,
        ]))
        ->create();
    $boundaryProduct = Product::factory()
        ->has(Inventory::factory()->state([
            'quantity' => 5,
            'reorder_level' => 5,
        ]))
        ->create();
    $uninitializedProduct = Product::factory()->create();
    $outOfStockProduct = Product::factory()
        ->has(Inventory::factory()->state([
            'quantity' => 0,
            'reorder_level' => 5,
        ]))
        ->create();

    $products = Product::query()
        ->withExists('lowStockInventory as is_low_stock')
        ->findMany([$lowStockProduct->id, $boundaryProduct->id, $uninitializedProduct->id, $outOfStockProduct->id])
        ->keyBy('id');

    expect($products[$lowStockProduct->id]->is_low_stock)->toBeTrue()
        ->and($products[$boundaryProduct->id]->is_low_stock)->toBeTrue()
        ->and($products[$uninitializedProduct->id]->is_low_stock)->toBeFalse()
        ->and($products[$outOfStockProduct->id]->is_low_stock)->toBeFalse();
});

test('negative inventory values are rejected', function (array $attributes) {
    expect(fn () => Inventory::factory()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'negative quantity' => [['quantity' => -1, 'reorder_level' => 5]],
    'negative reorder level' => [['quantity' => 5, 'reorder_level' => -1]],
]);

test('required inventory fields cannot be omitted', function (string $missingField) {
    $attributes = [
        'product_id' => Product::factory()->create()->id,
        'quantity' => 5,
        'reorder_level' => 2,
    ];
    unset($attributes[$missingField]);

    expect(fn () => Inventory::query()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'product' => 'product_id',
    'quantity' => 'quantity',
    'reorder level' => 'reorder_level',
]);
