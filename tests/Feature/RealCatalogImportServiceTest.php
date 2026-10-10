<?php

use App\Enums\ShippingProfile;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use App\Services\CatalogImagePipeline;
use App\Services\RealCatalogImportService;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

test('deleting imported catalog records preserves sources and images for explicit restoration', function (bool $adminImage) {
    Storage::fake('public');
    Storage::fake('local');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $importer = app(RealCatalogImportService::class);
        $importer->execute($mapping, $manifest);
        $product = Product::query()->sole();
        $category = $product->category;
        $manifestBytes = file_get_contents($manifest);
        $mappingBytes = file_get_contents($mapping);
        $importedImagePath = $product->image_path;
        $imageBytes = Storage::disk('public')->get($importedImagePath);
        Storage::disk('local')->put('catalog/original.csv', 'original catalog CSV bytes');
        Storage::disk('local')->put('catalog/original.xlsx', 'original workbook bytes');
        if ($adminImage) {
            $ownedPath = 'products/admin/'.$product->id.'/'.str_repeat('a', 64).'.webp';
            Storage::disk('public')->put($ownedPath, 'admin-owned bytes');
            $product->update(['image_path' => $ownedPath]);
        }
        $this->actingAs(User::factory()->administrator()->create());

        $this->delete(route('administration.products.destroy', $product))->assertSessionHasNoErrors();
        $this->delete(route('administration.categories.destroy', $category))->assertSessionHasNoErrors();

        $this->assertModelMissing($product);
        $this->assertModelMissing($category);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('product_tag', 0);
        expect(file_get_contents($manifest))->toBe($manifestBytes);
        expect(file_get_contents($mapping))->toBe($mappingBytes);
        expect(Storage::disk('public')->get($importedImagePath))->toBe($imageBytes);
        expect(Storage::disk('local')->get('catalog/original.csv'))->toBe('original catalog CSV bytes');
        expect(Storage::disk('local')->get('catalog/original.xlsx'))->toBe('original workbook bytes');
        if ($adminImage) {
            Storage::disk('public')->assertMissing($ownedPath);
        }

        expect($importer->execute($mapping, $manifest))->toBe(['created' => 1, 'updated' => 0]);
        $restored = Product::query()->sole();
        expect($restored->product_code)->toBe('00123');
        expect($restored->name)->toBe('Exact Product™');
        expect($restored->is_catalog_imported)->toBeTrue();
        expect($restored->inventory->quantity)->toBe(5);
        expect($restored->category->name)->toBe('Graphics Card');
        expect($restored->tags)->toHaveCount(3);
        expect(file_get_contents($manifest))->toBe($manifestBytes);
        expect(file_get_contents($mapping))->toBe($mappingBytes);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
})->with(['imported image' => false, 'admin replacement' => true]);

test('import name conflicts fail before database writes and source changes', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();
    $existing = Product::factory()->create(['name' => ' exact  PRODUCT™ ', 'is_active' => false]);

    try {
        $manifestBytes = file_get_contents($manifest);
        $mappingBytes = file_get_contents($mapping);

        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'conflicts with an existing product');

        $this->assertDatabaseCount('products', 1);
        expect($existing->refresh()->name)->toBe(' exact  PRODUCT™ ');
        expect(file_get_contents($manifest))->toBe($manifestBytes);
        expect(file_get_contents($mapping))->toBe($mappingBytes);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('imports reuse a category with the same normalized name and preserve exact source names', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['name' => ' Exact  Product™ ']);
    $mapping = realCatalogMapping([['00123', ' Exact  Product™ ', 'Graphics Card', 'Biostar', '', '3']]);
    $category = Category::factory()->inactive()->create(['name' => ' graphics   card ']);

    try {
        app(RealCatalogImportService::class)->execute($mapping, $manifest);

        $product = Product::query()->sole();
        expect($product->category_id)->toBe($category->id);
        expect($product->name)->toBe(' Exact  Product™ ');
        expect($category->refresh()->name)->toBe(' graphics   card ');
        expect($category->is_active)->toBeFalse();
        $this->assertDatabaseCount('categories', 1);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('duplicate source names across verified product codes block import without changing sources', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $secondManifest = realCatalogManifest([
        'product_code' => '00456', 'name' => ' exact   PRODUCT™ ',
        'image_path' => 'products/graphics-card/00456.webp',
    ]);
    $mapping = realCatalogMapping([
        ['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '', '3'],
        ['00456', ' exact   PRODUCT™ ', 'Graphics Card', 'Biostar', '', '3'],
    ]);

    try {
        $state = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode(file_get_contents($secondManifest), true, flags: JSON_THROW_ON_ERROR)['entries'][0];
        $image = imagecreatetruecolor(1024, 1024);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 50, 20));
        imagewebp($image, Storage::disk('public')->path($second['image_path']), 85);
        $bytes = Storage::disk('public')->get($second['image_path']);
        $second['image_sha256'] = hash('sha256', $bytes);
        $second['image_bytes'] = strlen($bytes);
        $state['entries'][] = $second;
        file_put_contents($manifest, json_encode($state, JSON_THROW_ON_ERROR));
        $manifestBytes = file_get_contents($manifest);
        $mappingBytes = file_get_contents($mapping);

        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Duplicate normalized product names');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 0);
        expect(file_get_contents($manifest))->toBe($manifestBytes);
        expect(file_get_contents($mapping))->toBe($mappingBytes);
    } finally {
        unlink($manifest);
        unlink($secondManifest);
        unlink($mapping);
    }
});

function realCatalogManifest(array $overrides = []): string
{
    $disk = Storage::disk('public');
    $path = $overrides['image_path'] ?? 'products/graphics-card/00123.webp';
    $disk->makeDirectory(dirname($path));
    $image = imagecreatetruecolor(1024, 1024);
    imagefill($image, 0, 0, imagecolorallocate($image, 25, 25, 25));
    imagewebp($image, $disk->path($path), 85);
    $bytes = file_get_contents($disk->path($path));

    $manifest = tempnam(sys_get_temp_dir(), 'real-catalog-manifest-');
    $entry = array_replace([
        'product_code' => '00123',
        'name' => 'Exact Product™',
        'category' => 'Graphics Card',
        'category_slug' => 'graphics-card',
        'quantity' => '5',
        'price' => '7500',
        'price_tier_tags' => 'Mid-Range',
        'use_case_tags' => 'Gaming, Productivity',
        'special_traits_tags' => '',
        'image_path' => $path,
        'status' => 'complete',
        'image_sha256' => hash('sha256', $bytes),
        'image_bytes' => strlen($bytes),
        'image_width' => 1024,
        'image_height' => 1024,
    ], $overrides);
    file_put_contents($manifest, json_encode(['version' => 1, 'entries' => [$entry]], JSON_THROW_ON_ERROR));

    return $manifest;
}

function realCatalogMapping(array $rows = [['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '', '3']]): string
{
    $path = tempnam(sys_get_temp_dir(), 'real-catalog-mapping-');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

test('historical fixtures and verified catalog imports remain separate across reseeding and reimport', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $importer = app(RealCatalogImportService::class);
        $importer->execute($mapping, $manifest);
        $realProduct = Product::with(['inventory', 'category', 'tags'])->where('product_code', '00123')->sole();
        $before = $realProduct->toArray();

        $this->seed(DevelopmentHistoricalSalesSeeder::class);
        expect($realProduct->fresh(['inventory', 'category', 'tags'])->toArray())->toBe($before);
        $fixtureProducts = Product::with('inventory')->where('is_catalog_imported', false)->orderBy('id')->get()->toArray();
        $coverageBefore = Storage::disk('local')->get(config('forecasting.development_manifest'));
        $second = $importer->execute($mapping, $manifest);

        expect($second)->toBe(['created' => 0, 'updated' => 1]);
        expect(Storage::disk('local')->get(config('forecasting.development_manifest')))->toBe($coverageBefore);
        expect(app(SalesHistoryCoverageRepository::class)->forProductCode('00123'))->toBeNull();
        expect(Product::with('inventory')->where('is_catalog_imported', false)->orderBy('id')->get()->toArray())->toBe($fixtureProducts);
        $this->assertDatabaseCount('products', 14);
        $this->assertDatabaseCount('sales', 96);
        $this->seed(DevelopmentHistoricalSalesSeeder::class);
        expect($realProduct->fresh(['inventory', 'category', 'tags'])->toArray())->toBe($before);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('details template lists exact product codes without inventing missing fields or overwriting a review', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['status' => 'pending']);
    $template = sys_get_temp_dir().'/verified-details-'.bin2hex(random_bytes(8)).'.csv';

    try {
        $count = app(RealCatalogImportService::class)->writeTemplate($template, $manifest);
        $rows = array_map('str_getcsv', file($template));

        expect($count)->toBe(1)
            ->and($rows)->toBe([
                ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'],
                ['00123', 'Exact Product™', 'Graphics Card', '', '', ''],
            ])
            ->and(fn () => app(RealCatalogImportService::class)->writeTemplate($template, $manifest))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($manifest);
        if (is_file($template)) {
            unlink($template);
        }
    }
});

test('dry run validates complete images and verified details without changing product data', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $status = Artisan::call('catalog:import-real', [
            'mapping' => $mapping,
            '--manifest' => $manifest,
        ]);

        expect($status)->toBe(0);
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('reimport updates the verified reorder level without resetting quantity or shipping profile', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $first = app(RealCatalogImportService::class)->execute($mapping, $manifest);
        $product = Product::query()->where('product_code', '00123')->firstOrFail();
        expect($product->shipping_profile)->toBe(ShippingProfile::Standard);
        $product->update(['shipping_profile' => ShippingProfile::Fragile]);
        $product->inventory()->update(['quantity' => 2, 'reorder_level' => 7]);
        $administratorProduct = Product::factory()->create();
        $administratorInventory = $administratorProduct->inventory()->create([
            'quantity' => 9,
            'reorder_level' => 4,
        ]);
        $second = app(RealCatalogImportService::class)->execute($mapping, $manifest);

        expect($first)->toBe(['created' => 1, 'updated' => 0])
            ->and($second)->toBe(['created' => 0, 'updated' => 1])
            ->and($product->refresh()->name)->toBe('Exact Product™')
            ->and($product->brand)->toBe('Biostar')
            ->and($product->image_path)->toBe('products/graphics-card/00123.webp')
            ->and($product->category->name)->toBe('Graphics Card')
            ->and($product->inventory->quantity)->toBe(2)
            ->and($product->inventory->reorder_level)->toBe(3)
            ->and($product->shipping_profile)->toBe(ShippingProfile::Fragile)
            ->and($product->tags->pluck('name')->sort()->values()->all())->toBe(['Gaming', 'Mid-Range', 'Productivity']);
        expect($administratorInventory->refresh()->quantity)->toBe(9);
        expect($administratorInventory->reorder_level)->toBe(4);
        $this->assertDatabaseCount('products', 2);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('blank verified brands import as null and later admin-entered brands survive blank reimports', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping([['00123', 'Exact Product™', 'Graphics Card', '', '', '3']]);

    try {
        $importer = app(RealCatalogImportService::class);
        $first = $importer->execute($mapping, $manifest);
        $product = Product::query()->sole();

        expect($first['created'])->toBe(1);
        expect($product->brand)->toBeNull();
        expect($product->inventory->reorder_level)->toBe(3);

        $product->update(['brand' => 'Verified later']);
        $second = $importer->execute($mapping, $manifest);

        expect($second['updated'])->toBe(1);
        expect($product->refresh()->brand)->toBe('Verified later');
        $this->assertDatabaseCount('products', 1);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('missing verified quantity blocks an import before any product is created', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['quantity' => null]);
    $mapping = realCatalogMapping();

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Verified quantity is required for code 00123.');
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('verified details cannot replace the exact spreadsheet product name', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping([['00123', 'Changed Product Name', 'Graphics Card', 'Biostar', '', '3']]);

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Verified details do not match the exact source name and category for code 00123.');
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('verified quantity fills a blank spreadsheet cell without changing the source name', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['quantity' => null]);
    $mapping = realCatalogMapping([['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '7', '3']]);

    try {
        app(RealCatalogImportService::class)->execute($mapping, $manifest);

        $product = Product::query()->where('product_code', '00123')->firstOrFail();
        expect($product->name)->toBe('Exact Product™')
            ->and($product->inventory->quantity)->toBe(7);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('an admin replacement preserves its manifest image and survives future imports', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();
    $administrator = User::factory()->administrator()->create();

    try {
        $importer = app(RealCatalogImportService::class);
        $importer->execute($mapping, $manifest);
        $product = Product::query()->sole();
        $manifestImage = $product->image_path;
        $manifestBytes = Storage::disk('public')->get($manifestImage);
        $manifestContents = file_get_contents($manifest);

        $this->actingAs($administrator)->post(route('administration.products.update', $product), [
            '_method' => 'put',
            'product_code' => $product->product_code,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'brand' => $product->brand,
            'price' => $product->price,
            'is_featured' => false,
            'image' => UploadedFile::fake()->image('admin.jpg', 640, 480),
        ])->assertSessionHasNoErrors();
        $adminImage = $product->refresh()->image_path;
        $importer->execute($mapping, $manifest);

        expect($product->refresh()->image_path)->toBe($adminImage)->not->toBe($manifestImage);
        expect(Storage::disk('public')->get($manifestImage))->toBe($manifestBytes);
        expect(file_get_contents($manifest))->toBe($manifestContents);
        expect(app(CatalogImagePipeline::class)->audit($manifest)['issues'])->toBe([]);
        Storage::disk('public')->assertExists($adminImage);

        Storage::disk('public')->delete($adminImage);
        $importer->execute($mapping, $manifest);
        expect($product->refresh()->image_path)->toBe($adminImage);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('import preserves existing products and their order and cart history', function () {
    Storage::fake('public');
    $category = Category::factory()->create(['name' => 'Storage']);
    $orderedProduct = Product::factory()->for($category)->create([
        'name' => 'Samsung Sprint NVMe SSD',
        'is_featured' => true,
    ]);
    $cartProduct = Product::factory()->for($category)->create(['name' => 'Crucial Archive SATA SSD']);
    foreach ([$orderedProduct, $cartProduct] as $existingProduct) {
        Inventory::factory()->for($existingProduct)->create(['quantity' => 10]);
    }
    $orderItem = OrderItem::factory()->for($orderedProduct)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $cartItem = CartItem::factory()->for($cartProduct)->create();
    $orderedProductAttributes = $orderedProduct->refresh()->getAttributes();
    $cartProductAttributes = $cartProduct->refresh()->getAttributes();
    $orderedInventoryAttributes = $orderedProduct->inventory->getAttributes();
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $result = app(RealCatalogImportService::class)->execute($mapping, $manifest);

        expect($result)->toBe(['created' => 1, 'updated' => 0]);
        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('inventories', 3);
        $this->assertDatabaseHas('order_items', ['id' => $orderItem->id, 'product_id' => $orderedProduct->id, 'quantity' => 2, 'price_at_time' => '100.00']);
        $this->assertModelExists($cartItem);
        expect($orderedProduct->refresh()->getAttributes())->toBe($orderedProductAttributes);
        expect($cartProduct->refresh()->getAttributes())->toBe($cartProductAttributes);
        expect($orderedProduct->inventory->getAttributes())->toBe($orderedInventoryAttributes);

        app(RealCatalogImportService::class)->execute($mapping, $manifest);
        $this->assertDatabaseCount('products', 3);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('incomplete reorder levels leave existing products and all catalog tables untouched', function () {
    Storage::fake('public');
    $existingProduct = Product::factory()->create(['name' => 'Existing Admin Product']);
    Inventory::factory()->for($existingProduct)->create();
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping([['00123', 'Exact Product™', 'Graphics Card', '', '', '']]);
    $before = Product::query()->orderBy('id')->get()->toArray();

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Invalid or duplicate verified product details for code 00123.');

        expect(Product::query()->orderBy('id')->get()->toArray())->toBe($before);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('inventories', 1);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('an import code cannot silently take over an administrator product', function () {
    Storage::fake('public');
    $product = Product::factory()->create(['product_code' => '00123']);
    $original = $product->refresh()->getAttributes();
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'conflicts with an existing product');
        expect($product->refresh()->getAttributes())->toBe($original);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('inventories', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('a database failure rolls back imported catalog changes', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest();
    $mapping = realCatalogMapping();

    try {
        $importer = app(RealCatalogImportService::class);
        $importer->execute($mapping, $manifest);
        $product = Product::query()->sole();
        $product->update(['discount_price' => '7000.00']);
        $state = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $state['entries'][0]['price'] = '6000';
        file_put_contents($manifest, json_encode($state, JSON_THROW_ON_ERROR));

        expect(fn () => $importer->execute($mapping, $manifest))->toThrow(QueryException::class);

        expect($product->refresh()->price)->toBe('7500.00');
        expect($product->discount_price)->toBe('7000.00');
        $this->assertDatabaseCount('inventories', 1);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('the private mapping and manifest agree on approved demo inventory while retaining spreadsheet provenance', function () {
    $path = storage_path('app/imports/product_catalog/verified-product-details.csv');
    $manifestPath = storage_path('app/private/product-catalog-images/manifest.json');
    if (! is_file($path) || ! is_file($manifestPath)) {
        $this->markTestSkipped('The private catalog data is unavailable.');
    }
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, escape: '');
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[$row[0]] = array_combine($header, $row);
    }
    fclose($handle);
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $approvals = json_decode(file_get_contents(storage_path('app/private/product-catalog-images/development-value-approvals.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(654);
    expect($approvals['products'])->toHaveCount(654);
    expect($approvals['purpose'])->toContain('not verified operational or historical client data');
    $stocks = [];
    foreach ($manifest['entries'] as $entry) {
        $row = $rows[$entry['product_code']];
        $approval = $approvals['products'][$entry['product_code']];
        expect($row['product_name'])->toBe($entry['name'])->not->toContain('[DEMO]');
        expect($row['category'])->toBe($entry['category']);
        expect($entry['quantity_source'])->toBe('development_demo');
        expect($entry['source_quantity'])->toBe($approval['source_quantity']);
        expect($row['reorder_level'])->toBe((string) $approval['reorder_level']);
        expect($row['quantity_override'])->toBe($entry['product_code'] === '80520996' ? '1' : '');
        $quantity = (int) ($entry['quantity'] ?? $row['quantity_override']);
        expect($quantity)->toBe($approval['quantity']);
        $stocks[] = ['quantity' => $quantity, 'reorder_level' => (int) $row['reorder_level']];
    }
    expect(collect($stocks)->filter(fn ($stock) => $stock['quantity'] > $stock['reorder_level']))->toHaveCount(650);
    expect(collect($stocks)->filter(fn ($stock) => $stock['quantity'] > 0 && $stock['quantity'] <= $stock['reorder_level']))->toHaveCount(3);
    expect(collect($stocks)->where('quantity', 0))->toHaveCount(1);
    $source = collect($manifest['entries'])->firstWhere('product_code', '80520996');
    expect($source['quantity'])->toBeNull();
    expect($source['source_quantity'])->toBeNull();
    expect($approvals['quantity_overrides'][0])->toMatchArray(['product_code' => '80520996', 'value' => 1]);
});

test('the real package name and approved temporary stock import exactly with test-only supplemental brand data', function () {
    Storage::fake('public');
    $name = 'BRAND NEW COMPUTER PACKAGE AMD RYZEN 5 5600G 16GB RAM/256GB SSD , 19\\ MONITOR';
    $manifest = realCatalogManifest([
        'product_code' => '80520996', 'name' => $name, 'category' => 'Bundles & Packages',
        'category_slug' => 'bundles-packages', 'quantity' => null,
        'image_path' => 'products/bundles-packages/80520996.webp',
    ]);
    $mapping = realCatalogMapping([['80520996', $name, 'Bundles & Packages', 'Test-only brand fixture', '1', '3']]);

    try {
        app(RealCatalogImportService::class)->execute($mapping, $manifest);

        $product = Product::query()->sole();
        expect($product->name)->toBe($name)->not->toContain('[DEMO]');
        expect($product->product_code)->toBe('80520996');
        expect($product->inventory->quantity)->toBe(1);
        expect($product->inventory->reorder_level)->toBe(3);
        expect($product->image_path)->toBe('products/bundles-packages/80520996.webp');
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});

test('demo inventory imports are restricted to development while genuine sources remain importable', function (string $environment, bool $demo) {
    Storage::fake('public');
    $manifest = realCatalogManifest($demo ? ['quantity_source' => 'development_demo', 'source_quantity' => '5'] : []);
    $mapping = realCatalogMapping();
    $this->app->instance('env', $environment);

    try {
        $importer = app(RealCatalogImportService::class);
        if ($demo && in_array($environment, ['production', 'staging'], true)) {
            expect(fn () => $importer->execute($mapping, $manifest))->toThrow(RuntimeException::class, 'Demo catalog inventory is only allowed');
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('inventories', 0);
        } else {
            expect($importer->execute($mapping, $manifest))->toBe(['created' => 1, 'updated' => 0]);
            $this->assertDatabaseHas('inventories', ['quantity' => 5, 'reorder_level' => 3]);
        }
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
})->with(['local', 'testing', 'production', 'staging'])->with([false, true]);

test('a CSV override cannot replace a populated manifest quantity', function () {
    Storage::fake('public');
    $manifest = realCatalogManifest(['quantity_source' => 'development_demo', 'source_quantity' => '5']);
    $mapping = realCatalogMapping([['00123', 'Exact Product™', 'Graphics Card', 'Biostar', '8', '3']]);

    try {
        expect(fn () => app(RealCatalogImportService::class)->execute($mapping, $manifest))
            ->toThrow(RuntimeException::class, 'Quantity override is not allowed for code 00123.');
        $this->assertDatabaseCount('products', 0);
    } finally {
        unlink($manifest);
        unlink($mapping);
    }
});
