<?php

use App\Actions\Inventory\AdjustInventoryStock;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\CatalogProductPresenter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** @return list<array<string, mixed>> */
function customerAvailabilityRows(TestResponse $response, string $channel): array
{
    return $channel === 'web' ? $response->inertiaProps('products.data') : $response->json('data');
}

test('customer catalog availability and stock labels agree at every boundary', function (string $channel, ?int $quantity, int $reorderLevel, string $status, bool $visible) {
    $product = Product::factory()->create();
    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
    }
    $repository = app(ProductCatalogRepository::class);
    $presented = app(CatalogProductPresenter::class)->present($repository->findEligibleOrFail($product->id));

    $response = $this->get(route($channel === 'web' ? 'products.index' : 'api.v1.products.index'));

    $response->assertOk();
    expect(array_column(customerAvailabilityRows($response, $channel), 'id'))->toBe($visible ? [$product->id] : []);
    expect($presented['inventory']['status'])->toBe($status);
    expect(Product::query()->customerAvailable()->whereKey($product->id)->exists())->toBe($visible);
    expect(Product::query()->cartEligible()->whereKey($product->id)->exists())->toBe($visible);
    expect(Product::query()->customerEligible()->whereKey($product->id)->exists())->toBeTrue();
    $detail = $this->get(route($channel === 'web' ? 'products.show' : 'api.v1.products.show', $product));
    if ($visible) {
        $detail->assertOk();
        if ($channel === 'web') {
            $detail->assertInertia(fn (Assert $page) => $page->where('product.inventory.status', $status));
        } else {
            $detail->assertJsonPath('data.inventory', ['status' => $status]);
        }
    } else {
        $detail->assertNotFound();
    }
    expect($product->refresh()->is_active)->toBeTrue();
    expect($product->category->is_active)->toBeTrue();
})->with(['web', 'mobile'])->with([
    'above reorder level' => [6, 5, 'in_stock', true],
    'equal to reorder level' => [5, 5, 'low_stock', true],
    'below reorder level' => [1, 5, 'low_stock', true],
    'zero stock' => [0, 5, 'out_of_stock', false],
    'missing inventory' => [null, 5, 'unavailable', false],
    'positive stock with zero reorder level' => [1, 0, 'in_stock', true],
    'zero stock with zero reorder level' => [0, 0, 'out_of_stock', false],
]);

test('restocking and depletion update catalog and filter visibility without changing activation', function (string $channel) {
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $product = Product::factory()->for($category)->create(['name' => 'Restocked Router', 'brand' => 'Atlas']);
    $product->tags()->attach($tag);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 0, 'reorder_level' => 2]);
    $soldOut = Product::factory()->for($category)->create(['brand' => 'Sold Out Brand']);
    Inventory::factory()->for($soldOut)->create(['quantity' => 0]);
    $missing = Product::factory()->for($category)->create(['brand' => 'Missing Inventory Brand']);
    $hiddenTag = Tag::factory()->create();
    $soldOut->tags()->attach($hiddenTag);
    $missing->tags()->attach($hiddenTag);
    $filters = ['category_id' => $category->id, 'brand' => 'Atlas', 'tag_id' => $tag->id, 'q' => 'Router'];
    $indexRoute = $channel === 'web' ? 'products.index' : 'api.v1.products.index';
    $assertDiscovery = function (bool $visible) use ($channel, $category, $tag, $product, $indexRoute, $filters): void {
        $response = $this->get(route($indexRoute, $filters))->assertOk();
        expect(array_column(customerAvailabilityRows($response, $channel), 'id'))->toBe($visible ? [$product->id] : []);
        $options = $channel === 'web'
            ? $response->inertiaProps('filter_options')
            : $this->get(route('api.v1.products.filters'))->assertOk()->json('data');
        expect(array_column($options['categories'], 'id'))->toBe($visible ? [$category->id] : []);
        expect($options['brands'])->toBe($visible ? ['Atlas'] : []);
        expect(array_column($options['tags'], 'id'))->toBe($visible ? [$tag->id] : []);
    };

    $assertDiscovery(false);
    app(AdjustInventoryStock::class)->set($inventory, 2, 2);
    $assertDiscovery(true);
    app(AdjustInventoryStock::class)->decrease($inventory, 2);
    $assertDiscovery(false);

    expect($product->refresh()->is_active)->toBeTrue();
    expect($category->refresh()->is_active)->toBeTrue();
})->with(['web', 'mobile']);

test('restocking never reactivates manually disabled products or categories', function (string $channel, bool $productActive, bool $categoryActive) {
    $category = Category::factory()->create(['is_active' => $categoryActive]);
    $product = Product::factory()->for($category)->create(['is_active' => $productActive]);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 0]);

    app(AdjustInventoryStock::class)->increase($inventory, 10);

    $response = $this->get(route($channel === 'web' ? 'products.index' : 'api.v1.products.index'))->assertOk();
    expect(customerAvailabilityRows($response, $channel))->toBe([]);
    $this->get(route($channel === 'web' ? 'products.show' : 'api.v1.products.show', $product))->assertNotFound();
    expect($product->refresh()->is_active)->toBe($productActive);
    expect($category->refresh()->is_active)->toBe($categoryActive);
})->with(['web', 'mobile'])->with([
    'inactive product' => [false, true],
    'inactive category' => [true, false],
    'both inactive' => [false, false],
]);

test('administrators can inspect and restock sold out products while inactive records stay manageable', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create();
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 0, 'reorder_level' => 2]);
    $inactive = Product::factory()->inactive()->for(Category::factory()->inactive())->create();
    Inventory::factory()->for($inactive)->create(['quantity' => 0]);

    $this->actingAs($administrator)->get(route('administration.products.show', $product))
        ->assertInertia(fn (Assert $page) => $page->where('product.stock_status', 'out_of_stock'));
    $this->get(route('administration.inventory.index', ['stock' => 'out_of_stock']))
        ->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
    $this->get(route('administration.products.show', $inactive))->assertOk();
    $this->patch(route('administration.inventory.update', $inventory), ['quantity' => 2, 'reorder_level' => 2])->assertRedirect();

    expect($inventory->refresh()->quantity)->toBe(2);
    expect($product->refresh()->is_active)->toBeTrue();
    expect($inactive->refresh()->is_active)->toBeFalse();
    expect($inactive->category->is_active)->toBeFalse();
    $this->get(route('administration.products.show', $product))
        ->assertInertia(fn (Assert $page) => $page->where('product.stock_status', 'low_stock'));
    expect(Product::query()->customerAvailable()->pluck('id')->all())->toBe([$product->id]);
});
