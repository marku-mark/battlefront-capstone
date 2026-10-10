<?php

use App\Actions\Catalog\CatalogName;
use App\Actions\Product\CreateProduct;
use App\Actions\Product\UpdateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

function catalogNamePayload(Category $category, string $name, string $code = 'NEW001'): array
{
    return ['product_code' => $code, 'name' => $name, 'category_id' => $category->id,
        'price' => '100.00', 'is_featured' => false];
}

test('product create and update reject normalized names across categories and activation states', function (string $name, bool $active, bool $imported) {
    $administrator = User::factory()->administrator()->create();
    $existing = Product::factory()->create(['name' => '  Model  RTX  4060  ', 'is_active' => $active, 'is_catalog_imported' => $imported]);
    $other = Product::factory()->create(['name' => 'Other model']);
    $payload = catalogNamePayload($other->category, $name, $other->product_code);

    $this->actingAs($administrator)->post(route('administration.products.store'), [
        ...$payload, 'product_code' => 'NEW001',
    ])->assertSessionHasErrors(['name' => CatalogName::PRODUCT_MESSAGE]);
    $this->put(route('administration.products.update', $other), $payload)
        ->assertSessionHasErrors(['name' => CatalogName::PRODUCT_MESSAGE]);

    expect($other->refresh()->name)->toBe('Other model');
    expect($existing->refresh()->name)->toBe('  Model  RTX  4060  ');
    $this->assertDatabaseCount('products', 2);
})->with([
    'active exact' => ['Model RTX 4060', true, false],
    'inactive case' => ['model rtx 4060', false, false],
    'imported whitespace' => [" MODEL\tRTX   4060 ", true, true],
    'inactive imported' => ['Model RTX 4060', false, true],
]);

test('category create and update reject normalized names including inactive records', function (string $name, bool $active) {
    $administrator = User::factory()->administrator()->create();
    Category::factory()->create(['name' => 'Graphics  Cards', 'is_active' => $active]);
    $other = Category::factory()->create(['name' => 'Other category']);

    $this->actingAs($administrator)->post(route('administration.categories.store'), ['name' => $name])
        ->assertSessionHasErrors(['name' => CatalogName::CATEGORY_MESSAGE]);
    $this->put(route('administration.categories.update', $other), ['name' => $name])
        ->assertSessionHasErrors(['name' => CatalogName::CATEGORY_MESSAGE]);

    expect($other->refresh()->name)->toBe('Other category');
    $this->assertDatabaseCount('categories', 2);
})->with([
    'active case' => ['graphics cards', true],
    'inactive whitespace' => [" GRAPHICS\t Cards  ", false],
]);

test('administrator names are normalized and their unchanged edits succeed', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.categories.store'), ['name' => '  Graphics   Cards '])
        ->assertSessionHasNoErrors();
    $category = Category::query()->sole();
    $this->post(route('administration.products.store'), catalogNamePayload($category, '  RTX   4060  Ti '))
        ->assertSessionHasNoErrors();
    $product = Product::query()->sole();
    $this->put(route('administration.products.update', $product), catalogNamePayload($category, $product->name, $product->product_code))
        ->assertSessionHasNoErrors();
    $this->put(route('administration.categories.update', $category), ['name' => $category->name])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->name)->toBe('RTX 4060 Ti');
    expect($category->refresh()->name)->toBe('Graphics Cards');
    expect($product->toArray())->not->toHaveKey('name_key');
    expect($category->toArray())->not->toHaveKey('name_key');
});

test('distinct model and variant names remain valid', function (string $name) {
    $administrator = User::factory()->administrator()->create();
    $existing = Product::factory()->create(['name' => 'RTX 4060']);

    $this->actingAs($administrator)->post(route('administration.products.store'), catalogNamePayload($existing->category, $name))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('products', ['name' => $name]);
})->with(['RTX 4060 Ti', 'RTX 4060 8GB', 'RTX 4070', 'RTX-4060']);

test('normalized name constraints protect model writes and do not merge accented names', function () {
    Product::factory()->create(['name' => '  Café  Model ']);
    Category::factory()->create(['name' => '  Café  Category ']);

    expect(fn () => Product::factory()->create(['name' => 'CAFÉ MODEL']))->toThrow(QueryException::class);
    expect(fn () => Category::factory()->create(['name' => 'CAFÉ CATEGORY']))->toThrow(QueryException::class);

    Product::factory()->create(['name' => 'Cafe Model']);
    $this->assertDatabaseCount('products', 2);
});

test('product actions translate a persistence uniqueness conflict into a name error', function () {
    $existing = Product::factory()->create(['name' => 'RTX 4060']);
    $other = Product::factory()->create(['name' => 'Another product']);
    $payload = catalogNamePayload($other->category, 'rtx   4060', 'NEW001');

    try {
        app(CreateProduct::class)->execute($payload, [], null);
        $this->fail('Duplicate creation succeeded.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['name' => [CatalogName::PRODUCT_MESSAGE]]);
    }
    try {
        app(UpdateProduct::class)->execute($other, [...$payload, 'product_code' => $other->product_code], [], null);
        $this->fail('Duplicate update succeeded.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['name' => [CatalogName::PRODUCT_MESSAGE]]);
    }

    expect($other->refresh()->name)->toBe('Another product');
    $this->assertModelExists($existing);
});
