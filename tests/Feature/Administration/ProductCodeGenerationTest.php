<?php

use App\Actions\Catalog\CatalogName;
use App\Actions\Product\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Uid\Ulid;

function generatedProductPayload(Category $category, string $name = 'New administrator product'): array
{
    return ['name' => $name, 'category_id' => $category->id, 'price' => '100.00', 'is_featured' => false];
}

test('administrator creation assigns distinct valid product codes without manual entry', function () {
    $category = Category::factory()->create();
    $this->actingAs(User::factory()->administrator()->create());

    foreach (['New processor', 'New graphics card'] as $name) {
        $this->post(route('administration.products.store'), generatedProductPayload($category, $name))
            ->assertSessionHasNoErrors()->assertRedirect(route('administration.products.index'));
    }

    $products = Product::query()->get();
    expect($products)->toHaveCount(2);
    foreach ($products as $product) {
        expect($product->product_code)->toMatch('/^ADMIN[0-9A-HJKMNP-TV-Z]{26}$/D');
        expect($product->is_catalog_imported)->toBeFalse();
    }
    expect($products->pluck('product_code')->unique())->toHaveCount(2);
});

test('administrator creation ignores submitted codes and forged import origin', function (mixed $submittedCode) {
    $existing = Product::factory()->create(['product_code' => 'SOURCE001', 'is_catalog_imported' => true]);

    $this->actingAs(User::factory()->administrator()->create())->post(route('administration.products.store'), [
        ...generatedProductPayload($existing->category), 'product_code' => $submittedCode, 'is_catalog_imported' => true,
    ])->assertSessionHasNoErrors();

    $created = Product::query()->whereKeyNot($existing->id)->sole();
    expect($created->product_code)->toStartWith('ADMIN')->not->toBe($submittedCode);
    expect($created->is_catalog_imported)->toBeFalse();
    expect($existing->refresh()->product_code)->toBe('SOURCE001');
    expect($existing->is_catalog_imported)->toBeTrue();
})->with([
    'provided code' => ['CUSTOM001'],
    'existing imported code' => ['SOURCE001'],
    'unsafe code' => ['../unsafe'],
    'nonstring code' => [['CUSTOM001']],
    'null code' => [null],
]);

test('a generated code collision retries without changing the existing product or losing tags and images', function (bool $imported, bool $lowercase) {
    Storage::fake('public');
    $first = '01HRDBNHHCKNW2AK4Z29SN82T9';
    $second = '01HRDBNHHCKNW2AK4Z29SN82TA';
    $code = 'ADMIN'.$first;
    $existing = Product::factory()->create([
        'product_code' => $lowercase ? strtolower($code) : $code,
        'is_catalog_imported' => $imported, 'name' => 'Existing source product',
        'image_path' => 'products/graphics-card/source.webp',
    ]);
    Storage::disk('public')->put($existing->image_path, 'existing image bytes');
    $tag = Tag::factory()->create();
    Str::createUlidsUsingSequence([new Ulid($first), new Ulid($second)]);

    try {
        $this->actingAs(User::factory()->administrator()->create())->post(route('administration.products.store'), [
            ...generatedProductPayload($existing->category), 'tag_ids' => [$tag->id],
            'image' => UploadedFile::fake()->image('new.png'),
        ])->assertSessionHasNoErrors();
    } finally {
        Str::createUlidsNormally();
    }

    $created = Product::query()->whereKeyNot($existing->id)->sole();
    expect($created->product_code)->toBe('ADMIN'.$second);
    expect($created->tags->modelKeys())->toBe([$tag->id]);
    Storage::disk('public')->assertExists($created->image_path);
    expect(Storage::disk('public')->get($existing->image_path))->toBe('existing image bytes');
    expect($existing->refresh()->product_code)->toBe($lowercase ? strtolower($code) : $code);
    expect($existing->name)->toBe('Existing source product');
    expect($existing->is_catalog_imported)->toBe($imported);
})->with([
    'manual product' => [false, false], 'imported product' => [true, false], 'case-insensitive collision' => [false, true],
]);

test('repeated generated code collisions return a recoverable error without creating product data or images', function () {
    Storage::fake('public');
    $ulid = '01HRDBNHHCKNW2AK4Z29SN82T9';
    $existing = Product::factory()->create(['product_code' => 'ADMIN'.$ulid]);
    $attempts = 0;
    Str::createUlidsUsing(function () use ($ulid, &$attempts): Ulid {
        $attempts++;

        return new Ulid($ulid);
    });

    try {
        $this->actingAs(User::factory()->administrator()->create())->post(route('administration.products.store'), [
            ...generatedProductPayload($existing->category), 'image' => UploadedFile::fake()->image('new.png'),
        ])->assertSessionHasErrors(['product_code' => 'A product code could not be generated. Please try saving the product again.']);
    } finally {
        Str::createUlidsNormally();
    }

    expect($attempts)->toBe(5);
    $this->assertDatabaseCount('products', 1);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('duplicate name failures are not retried as product code collisions', function () {
    $existing = Product::factory()->create(['name' => 'products.product_code']);
    $attempts = 0;
    Str::createUlidsUsing(function () use (&$attempts): Ulid {
        $attempts++;

        return new Ulid('01HRDBNHHCKNW2AK4Z29SN82T9');
    });

    try {
        app(CreateProduct::class)->execute(generatedProductPayload($existing->category, 'products.product_code'), [], null);
        $this->fail('A duplicate product name was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['name' => [CatalogName::PRODUCT_MESSAGE]]);
    } finally {
        Str::createUlidsNormally();
    }

    expect($attempts)->toBe(1);
    $this->assertDatabaseCount('products', 1);
});
