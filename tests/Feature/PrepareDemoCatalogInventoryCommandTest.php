<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Services\RealCatalogImportService;
use Illuminate\Support\Facades\Storage;

/** @return array{manifest: string, mapping: string, approvals: string} */
function demoInventorySources(): array
{
    Storage::fake('public');
    $examples = [
        ['80520996', 'BRAND NEW COMPUTER PACKAGE AMD RYZEN 5 5600G 16GB RAM/256GB SSD , 19\\ MONITOR', 'Bundles & Packages', '26000', null],
        ['10044', 'PROCESSOR AMD RYZEN™ 5 5600X (6CORES 12THREADS)', 'Processor', '10000', '4'],
        ['6940056198471', 'RAPOO WEB CAMERA 1080P FULL HD USB C260', 'Peripherals', '1300', '2'],
        ['301501828', 'HIKVISION KIT ANALOG 8CH 2MP COLORVU (TVI-LITE-8CH4D4B-2MP-COLORVU)', 'Bundles & Packages', '9500', '0'],
        ['57225704', 'Biostar EXtreme Gaming GTX 1660 Super', 'Graphics Card', '7500', '2'],
        ['69775271', 'CLEANING SOLUTION CUYI 100ML', 'Cleaning & Maintenance Supplies', '120', '5'],
        ['10160', '2 PORT VGA SPLITTER 200MHZ', 'Cables & Adapters', '250', '5'],
        ['21398773', 'ESGAMING PSU ATX 750W', 'Power Supply', '1600', '4'],
    ];
    foreach (range(1, 646) as $number) {
        $examples[] = [sprintf('DEMO%04d', $number), 'Test peripheral '.$number, 'Peripherals', '1500', '5'];
    }
    $paths = [
        'manifest' => tempnam(sys_get_temp_dir(), 'demo-manifest-'),
        'mapping' => tempnam(sys_get_temp_dir(), 'demo-mapping-'),
        'approvals' => tempnam(sys_get_temp_dir(), 'demo-approvals-'),
    ];
    $mapping = fopen($paths['mapping'], 'w');
    fputcsv($mapping, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'], escape: '');
    $entries = [];
    $image = imagecreatetruecolor(1024, 1024);
    ob_start();
    imagewebp($image);
    $baseImage = ob_get_clean();
    foreach ($examples as $index => [$code, $name, $category, $price, $quantity]) {
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $category)), '-');
        $imagePath = "products/$slug/$code.webp";
        Storage::disk('public')->makeDirectory(dirname($imagePath));
        $metadata = '<fixture>'.$code.'</fixture>';
        $chunk = 'XMP '.pack('V', strlen($metadata)).$metadata.(strlen($metadata) % 2 ? "\0" : '');
        $bytes = substr_replace($baseImage, pack('V', strlen($baseImage) + strlen($chunk) - 8), 4, 4).$chunk;
        Storage::disk('public')->put($imagePath, $bytes);
        $entries[] = [
            'product_code' => $code, 'name' => $name, 'category' => $category,
            'category_slug' => $slug, 'price' => $price, 'quantity' => $quantity,
            'source_file' => 'Test workbook.xlsx', 'source_row' => $index + 4,
            'source_sha256' => str_repeat('a', 64), 'prompt' => 'Test image prompt',
            'import_issues' => $quantity === null ? ['missing_quantity'] : [],
            'price_tier_tags' => 'Mid-Range', 'use_case_tags' => 'Productivity',
            'image_path' => $imagePath, 'status' => 'complete', 'attempts' => 1,
            'image_sha256' => hash_file('sha256', Storage::disk('public')->path($imagePath)),
            'image_bytes' => Storage::disk('public')->size($imagePath),
            'image_width' => 1024, 'image_height' => 1024,
        ];
        fputcsv($mapping, [$code, $name, $category, 'Test brand', $quantity === null ? '1' : '', '2'], escape: '');
    }
    fclose($mapping);
    file_put_contents($paths['manifest'], json_encode(['version' => 1, 'entries' => $entries], JSON_THROW_ON_ERROR));
    file_put_contents($paths['approvals'], '{"version":1,"purpose":"Old demo approval"}');

    return $paths;
}

/** @return list<list<string|null>> */
function demoInventoryCsv(string $path): array
{
    $handle = fopen($path, 'r');
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

test('demo preparation preserves catalog provenance and produces repeatable importable inventory without database writes', function () {
    $sentinel = Inventory::factory()->create(['quantity' => 42]);
    $paths = demoInventorySources();
    $originalManifest = json_decode(file_get_contents($paths['manifest']), true, flags: JSON_THROW_ON_ERROR);
    $originalCsv = demoInventoryCsv($paths['mapping']);
    $options = collect($paths)->mapWithKeys(fn ($path, $key) => ['--'.$key => $path])->all();

    try {
        $this->artisan('catalog:prepare-demo-inventory', $options)->assertSuccessful();

        $manifest = json_decode(file_get_contents($paths['manifest']), true, flags: JSON_THROW_ON_ERROR);
        $csv = demoInventoryCsv($paths['mapping']);
        $approvals = json_decode(file_get_contents($paths['approvals']), true, flags: JSON_THROW_ON_ERROR);
        foreach ($originalManifest['entries'] as $index => $original) {
            $entry = $manifest['entries'][$index];
            expect($entry['source_quantity'])->toBe($original['quantity']);
            expect($entry['quantity_source'])->toBe('development_demo');
            unset($entry['quantity'], $entry['source_quantity'], $entry['quantity_source']);
            unset($original['quantity']);
            expect($entry)->toBe($original);
            expect(array_slice($csv[$index + 1], 0, 4))->toBe(array_slice($originalCsv[$index + 1], 0, 4));
        }
        expect($approvals['purpose'])->toContain('not verified operational or historical client data');
        expect($approvals['products'])->toHaveCount(654);
        expect($approvals['quantity_overrides'])->toHaveCount(1);
        expect($manifest['entries'][0]['quantity'])->toBeNull();
        expect($csv[1][4])->toBe('1');
        $this->assertDatabaseCount('products', 1);
        expect($sentinel->refresh()->quantity)->toBe(42);

        $firstFiles = array_map(file_get_contents(...), $paths);
        $this->artisan('catalog:prepare-demo-inventory', $options)->assertSuccessful();
        expect(array_map(file_get_contents(...), $paths))->toBe($firstFiles);

        $rows = collect(app(RealCatalogImportService::class)->inspectFiles($paths['mapping'], $paths['manifest']))->keyBy('product_code');
        expect($rows)->toHaveCount(654);
        expect($rows->filter(fn ($row) => $row['quantity'] > $row['reorder_level']))->toHaveCount(650);
        expect($rows->filter(fn ($row) => $row['quantity'] > 0 && $row['quantity'] <= $row['reorder_level']))->toHaveCount(3);
        expect($rows->where('quantity', 0))->toHaveCount(1);
        foreach ([
            '80520996' => [1, 3], '10044' => [2, 3], '6940056198471' => [3, 5], '301501828' => [0, 3],
            '57225704' => [5, 3], '69775271' => [37, 10], '10160' => [23, 8], '21398773' => [12, 5],
        ] as $code => [$quantity, $reorderLevel]) {
            expect($rows[$code])->toMatchArray(['quantity' => $quantity, 'reorder_level' => $reorderLevel]);
        }

        $importer = app(RealCatalogImportService::class);
        expect($importer->execute($paths['mapping'], $paths['manifest']))->toBe(['created' => 654, 'updated' => 0]);
        expect(Product::query()->where('is_catalog_imported', true)->count())->toBe(654);
        expect(Inventory::query()->whereHas('product', fn ($query) => $query->where('is_catalog_imported', true))->count())->toBe(654);
        $administratorAdjusted = Product::query()->where('product_code', '10044')->sole()->inventory;
        $administratorAdjusted->update(['quantity' => 11]);
        expect($importer->execute($paths['mapping'], $paths['manifest']))->toBe(['created' => 0, 'updated' => 654]);
        expect($administratorAdjusted->refresh()->quantity)->toBe(11);

        $manifest['entries'] = array_reverse($manifest['entries']);
        file_put_contents($paths['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->artisan('catalog:prepare-demo-inventory', $options)->assertSuccessful();
        $reordered = collect($importer->inspectFiles($paths['mapping'], $paths['manifest']))->keyBy('product_code');
        expect($reordered->sortKeys()->all())->toBe($rows->sortKeys()->all());

        if (PHP_OS_FAMILY === 'Windows') {
            $beforeFailure = array_map(file_get_contents(...), $paths);
            chmod($paths['approvals'], 0444);
            try {
                $this->artisan('catalog:prepare-demo-inventory', $options)->expectsOutputToContain('Could not publish')->assertFailed();
                expect(array_map(file_get_contents(...), $paths))->toBe($beforeFailure);
            } finally {
                chmod($paths['approvals'], 0666);
            }
        }
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
});

test('demo preparation rejects unsafe environments without writing files', function (string $environment) {
    $path = tempnam(sys_get_temp_dir(), 'demo-protected-');
    file_put_contents($path, 'protected source');
    $this->app->instance('env', $environment);

    try {
        $this->artisan('catalog:prepare-demo-inventory', ['--manifest' => $path, '--mapping' => $path, '--approvals' => $path])
            ->expectsOutputToContain('only allowed in local and testing')->assertFailed();
        expect(file_get_contents($path))->toBe('protected source');
    } finally {
        unlink($path);
    }
})->with(['production', 'staging']);

test('invalid demo preparation inputs leave all private files untouched', function (string $problem) {
    $paths = demoInventorySources();
    $options = collect($paths)->mapWithKeys(fn ($path, $key) => ['--'.$key => $path])->all();
    $manifest = json_decode(file_get_contents($paths['manifest']), true, flags: JSON_THROW_ON_ERROR);
    match ($problem) {
        'wrong count' => array_pop($manifest['entries']),
        'missing provenance' => $manifest['entries'][0]['quantity_source'] = 'development_demo',
        'missing exception' => $manifest['entries'][0]['product_code'] = 'MISSINGEXAMPLE',
        'changed image' => Storage::disk('public')->put($manifest['entries'][0]['image_path'], 'invalid'),
        'path collision' => $options['--approvals'] = $paths['manifest'],
        'missing directory' => $options['--approvals'] = $paths['approvals'].'/missing/approvals.json',
    };
    file_put_contents($paths['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
    $before = array_map(file_get_contents(...), $paths);

    try {
        $this->artisan('catalog:prepare-demo-inventory', $options)->assertFailed();
        expect(array_map(file_get_contents(...), $paths))->toBe($before);
        $this->assertDatabaseCount('products', 0);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
    }
})->with(['wrong count', 'missing provenance', 'missing exception', 'changed image', 'path collision', 'missing directory']);
