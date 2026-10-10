<?php

use App\Actions\Product\CreateProduct;
use App\Actions\Product\DeleteManagedProductImage;
use App\Actions\Product\UpdateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function imageProductPayload(?Product $product = null): array
{
    return [
        ...($product !== null ? ['product_code' => $product->product_code] : []),
        'name' => $product?->name ?? 'Admin-created product',
        'category_id' => $product?->category_id ?? Category::factory()->create()->id,
        'brand' => 'Verified brand',
        'price' => '100.00',
        'is_featured' => false,
    ];
}

test('administrators can create optimized images independently of catalog generation', function (string $extension) {
    Storage::fake('public');
    Storage::fake('local');
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.products.store'), [
        ...imageProductPayload(),
        'image' => UploadedFile::fake()->image('product.'.$extension, 320, 640),
    ])->assertSessionHasNoErrors()->assertRedirect(route('administration.products.index'));

    $product = Product::query()->sole();
    expect($product->image_path)->toMatch('~^products/admin/'.$product->id.'/[a-f0-9]{64}\.webp$~');
    expect($product->is_catalog_imported)->toBeFalse();
    $details = getimagesize(Storage::disk('public')->path($product->image_path));
    expect([$details[0], $details[1], $details['mime']])->toBe([1024, 1024, 'image/webp']);
    expect($product->image_url)->toBe(Storage::disk('public')->url($product->image_path));
})->with(['png', 'jpg', 'webp']);

test('replacement cleans up only obsolete admin images and identical reuploads retain the file', function () {
    Storage::fake('public');
    $product = app(CreateProduct::class)->execute(imageProductPayload(), [], UploadedFile::fake()->image('old.png'));
    $oldPath = $product->image_path;
    $source = UploadedFile::fake()->image('replacement.jpg', 300, 500);
    $canvas = imagecreatetruecolor(300, 500);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 220, 20, 20));
    imagejpeg($canvas, $source->getPathname());

    app(UpdateProduct::class)->execute($product, imageProductPayload($product), [], $source);
    $replacementPath = $product->image_path;
    app(UpdateProduct::class)->execute($product, imageProductPayload($product), [], $source);

    expect($product->image_path)->toBe($replacementPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($replacementPath);
    expect(Storage::disk('public')->allFiles('products'))->toBe([$replacementPath]);
});

test('a product code edit retains its existing admin image', function () {
    Storage::fake('public');
    $administrator = User::factory()->administrator()->create();
    $product = app(CreateProduct::class)->execute(imageProductPayload(), [], UploadedFile::fake()->image('old.png'));
    $oldPath = $product->image_path;

    $this->actingAs($administrator)->put(route('administration.products.update', $product), [
        ...imageProductPayload($product), 'product_code' => 'RENAMED001',
    ])->assertSessionHasNoErrors();

    expect($product->refresh()->product_code)->toBe('RENAMED001');
    expect($product->image_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
});

test('failed product creation removes its new image and rolls back its product', function () {
    Storage::fake('public');
    $payload = imageProductPayload();

    expect(fn () => app(CreateProduct::class)->execute($payload, [PHP_INT_MAX], UploadedFile::fake()->image('new.png')))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('products', 0);
    expect(Storage::disk('public')->allFiles('products'))->toBe([]);
});

test('failed replacement retains the old image and removes only newly written bytes', function () {
    Storage::fake('public');
    $oldPath = 'products/old.png';
    Storage::disk('public')->put($oldPath, 'original bytes');
    $product = Product::factory()->create(['image_path' => $oldPath]);

    expect(fn () => app(UpdateProduct::class)->execute($product, imageProductPayload($product), [PHP_INT_MAX], UploadedFile::fake()->image('new.png')))
        ->toThrow(QueryException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    expect(Storage::disk('public')->allFiles('products'))->toBe([$oldPath]);
    expect(Storage::disk('public')->get($oldPath))->toBe('original bytes');
});

test('failed identical replacement does not delete a previously stored image', function () {
    Storage::fake('public');
    $source = UploadedFile::fake()->image('product.png');
    $product = app(CreateProduct::class)->execute(imageProductPayload(), [], $source);
    $oldPath = $product->image_path;

    expect(fn () => app(UpdateProduct::class)->execute($product, imageProductPayload($product), [PHP_INT_MAX], $source))
        ->toThrow(QueryException::class);

    expect($product->refresh()->image_path)->toBe($oldPath);
    Storage::disk('public')->assertExists($oldPath);
});

test('cleanup refuses manifest paths, traversal, and another products admin namespace', function (string $path) {
    Storage::fake('public');
    $product = Product::factory()->create(['id' => 10]);
    $safePath = str_contains($path, '..') ? 'private.webp' : $path;
    Storage::disk('public')->put($safePath, 'preserved');

    app(DeleteManagedProductImage::class)->execute($path, $product->id);

    Storage::disk('public')->assertExists($safePath);
})->with([
    'manifest' => 'products/graphics-card/A001.webp',
    'traversal' => 'products/../private.webp',
    'different product' => 'products/admin/11/'.str_repeat('a', 64).'.webp',
]);

test('an image still referenced by another product is not removed', function () {
    Storage::fake('public');
    $path = 'products/shared.jpg';
    Storage::disk('public')->put($path, 'shared image');
    $product = Product::factory()->create();
    Product::factory()->create(['image_path' => $path]);

    app(DeleteManagedProductImage::class)->execute($path, $product->id);

    Storage::disk('public')->assertExists($path);
});
