<?php

namespace App\Services;

use App\Actions\Catalog\CatalogName;
use App\Actions\Product\ProductImagePaths;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RealCatalogImportService
{
    public function __construct(private readonly CatalogImagePipeline $images) {}

    public function writeTemplate(string $outputPath, ?string $manifestPath = null): int
    {
        $entries = $this->images->entries($manifestPath);
        if (! is_dir(dirname($outputPath))) {
            throw new RuntimeException('The verified details template directory does not exist.');
        }

        $handle = @fopen($outputPath, 'x');
        if ($handle === false) {
            throw new RuntimeException('The verified details template already exists or cannot be created.');
        }

        try {
            if (fputcsv($handle, ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level'], ',', '"', '') === false) {
                throw new RuntimeException('The verified details template could not be written.');
            }
            foreach ($entries as $entry) {
                if (fputcsv($handle, [$entry['product_code'], $entry['name'], $entry['category'], '', '', ''], ',', '"', '') === false) {
                    throw new RuntimeException('The verified details template could not be written.');
                }
            }
            if (! fflush($handle)) {
                throw new RuntimeException('The verified details template could not be flushed.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            unlink($outputPath);

            throw $exception;
        }
        fclose($handle);

        return count($entries);
    }

    /**
     * Validate all images and verified supplemental fields before any database write.
     *
     * @return list<array<string, mixed>>
     */
    public function inspect(string $mappingPath, ?string $manifestPath = null): array
    {
        $prepared = $this->inspectFiles($mappingPath, $manifestPath);

        foreach ($prepared as $row) {
            $code = $row['product_code'];
            $existing = Product::query()->where('product_code', $code)->first();
            if ($existing !== null && (! $existing->is_catalog_imported || $existing->product_code !== $code)) {
                throw new RuntimeException("Product code $code conflicts with an existing product. No products were imported.");
            }
        }

        $this->ensureUniqueProductNames($prepared);

        return $prepared;
    }

    /**
     * Validate the restore files without requiring an existing database schema.
     *
     * @return list<array<string, mixed>>
     */
    public function inspectFiles(string $mappingPath, ?string $manifestPath = null): array
    {
        $entries = $this->images->importEntries($manifestPath);
        $mapping = $this->readMapping($mappingPath);
        $prepared = [];
        $seenNames = [];

        foreach ($entries as $entry) {
            $code = $entry['product_code'];
            if (($entry['quantity_source'] ?? null) === 'development_demo' && ! app()->environment(['local', 'testing'])) {
                throw new RuntimeException('Demo catalog inventory is only allowed in local and testing environments.');
            }
            if (! isset($mapping[$code])) {
                throw new RuntimeException("Verified product details are missing for code $code.");
            }

            $details = $mapping[$code];
            $name = $entry['name'] ?? null;
            $category = $entry['category'] ?? null;
            $price = $entry['price'] ?? null;
            $sourceQuantity = $entry['quantity'] ?? null;
            if (! is_string($name) || $name === '' || mb_strlen($name) > 255
                || ! is_string($category) || $category === '' || mb_strlen($category) > 255
                || ! is_string($price) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $price) || ! is_numeric($price)
                || bccomp($price, '9999999999.99', 2) > 0) {
                throw new RuntimeException("Invalid catalog fields for code $code.");
            }
            if ($details['name'] !== $name || $details['category'] !== $category) {
                throw new RuntimeException("Verified details do not match the exact source name and category for code $code.");
            }

            $nameKey = CatalogName::key($name);
            if (isset($seenNames[$nameKey])) {
                throw new RuntimeException("Duplicate normalized product names for codes {$seenNames[$nameKey]} and {$code}. No products were imported.");
            }
            $seenNames[$nameKey] = $code;

            if ($sourceQuantity === null || $sourceQuantity === '') {
                if ($details['quantity_override'] === '') {
                    throw new RuntimeException("Verified quantity is required for code $code.");
                }
                $quantity = $details['quantity_override'];
            } else {
                if ($details['quantity_override'] !== '') {
                    throw new RuntimeException("Quantity override is not allowed for code $code.");
                }
                $quantity = $sourceQuantity;
            }

            if (! $this->isUnsignedInventoryValue($quantity)) {
                throw new RuntimeException("Invalid quantity for code $code.");
            }

            $tagNames = [];
            foreach (['price_tier_tags', 'use_case_tags', 'special_traits_tags'] as $field) {
                foreach (explode(',', (string) ($entry[$field] ?? '')) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '' && ! in_array($tag, $tagNames, true)) {
                        if (mb_strlen($tag) > 255) {
                            throw new RuntimeException("Tag is too long for code $code.");
                        }
                        $tagNames[] = $tag;
                    }
                }
            }

            $prepared[] = [
                'product_code' => $code,
                'name' => $name,
                'category' => $category,
                'brand' => $details['brand'] === '' ? null : $details['brand'],
                'price' => $price,
                'quantity' => (int) $quantity,
                'reorder_level' => (int) $details['reorder_level'],
                'image_path' => $entry['image_path'],
                'tags' => $tagNames,
            ];
            unset($mapping[$code]);
        }

        if ($mapping !== []) {
            throw new RuntimeException('Verified product details contain codes absent from the catalog manifest.');
        }

        return $prepared;
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function execute(string $mappingPath, ?string $manifestPath = null): array
    {
        $prepared = $this->inspect($mappingPath, $manifestPath);

        try {
            return DB::transaction(function () use ($prepared): array {
                $this->ensureUniqueProductNames($prepared);
                $created = 0;
                $updated = 0;
                foreach ($prepared as $row) {
                    $category = Category::query()->where('name_key', CatalogName::key($row['category']))->first()
                        ?? Category::query()->create(['name' => $row['category']]);
                    $product = Product::query()->where('product_code', $row['product_code'])->lockForUpdate()->first()
                        ?? new Product(['product_code' => $row['product_code']]);
                    $isNew = ! $product->exists;
                    if (! $isNew && (! $product->is_catalog_imported || $product->product_code !== $row['product_code'])) {
                        throw new RuntimeException("Product code {$row['product_code']} conflicts with an existing product.");
                    }
                    $product->fill([
                        'name' => $row['name'],
                        'category_id' => $category->id,
                        'brand' => $row['brand'] ?? $product->brand,
                        'price' => $row['price'],
                    ]);
                    if (! ProductImagePaths::isAdminOwned($product->image_path, $product->id)) {
                        $product->image_path = $row['image_path'];
                    }
                    $product->is_catalog_imported = true;
                    $product->save();

                    $inventory = Inventory::query()->firstOrCreate(
                        ['product_id' => $product->id],
                        ['quantity' => $row['quantity'], 'reorder_level' => $row['reorder_level']],
                    );
                    if ($inventory->reorder_level !== $row['reorder_level']) {
                        $inventory->update(['reorder_level' => $row['reorder_level']]);
                    }

                    $tagIds = [];
                    foreach ($row['tags'] as $tagName) {
                        $tagIds[] = Tag::query()->firstOrCreate(['name' => $tagName])->id;
                    }
                    $product->tags()->sync($tagIds);

                    if ($isNew) {
                        $created++;
                    } else {
                        $updated++;
                    }
                }

                return ['created' => $created, 'updated' => $updated];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('Catalog names or product codes conflict with existing records. No products were imported.', previous: $exception);
        }
    }

    /** @param list<array<string, mixed>> $prepared */
    private function ensureUniqueProductNames(array $prepared): void
    {
        foreach ($prepared as $row) {
            if (Product::query()->where('name_key', CatalogName::key($row['name']))
                ->where('product_code', '!=', $row['product_code'])->exists()) {
                throw new RuntimeException("Product name for code {$row['product_code']} conflicts with an existing product. No products were imported.");
            }
        }
    }

    /**
     * @return array<string, array{name: string, category: string, brand: string, quantity_override: string, reorder_level: string}>
     */
    private function readMapping(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The verified product details CSV is not readable.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('The verified product details CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if ($header !== ['product_code', 'product_name', 'category', 'brand', 'quantity_override', 'reorder_level']) {
                throw new RuntimeException('The verified product details CSV has unexpected columns.');
            }

            $mapping = [];
            $seenCodes = [];
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== 6 || ! is_string($cells[0]) || ! is_string($cells[1]) || ! is_string($cells[2]) || ! is_string($cells[3]) || ! is_string($cells[4]) || ! is_string($cells[5])) {
                    throw new RuntimeException('The verified product details CSV contains an invalid row.');
                }

                [$code, $name, $category, $brand, $quantityOverride, $reorderLevel] = $cells;
                $brand = trim($brand);
                if (! preg_match(Product::CODE_PATTERN, $code) || isset($seenCodes[strtolower($code)])
                    || mb_strlen($brand) > 255
                    || ! $this->isUnsignedInventoryValue($reorderLevel)
                    || ($quantityOverride !== '' && ! $this->isUnsignedInventoryValue($quantityOverride))) {
                    throw new RuntimeException("Invalid or duplicate verified product details for code $code.");
                }

                $seenCodes[strtolower($code)] = true;
                $mapping[$code] = [
                    'name' => $name,
                    'category' => $category,
                    'brand' => $brand,
                    'quantity_override' => $quantityOverride,
                    'reorder_level' => $reorderLevel,
                ];
            }

            return $mapping;
        } finally {
            fclose($handle);
        }
    }

    private function isUnsignedInventoryValue(string $value): bool
    {
        return preg_match('/^\d+$/D', $value) === 1 && strlen($value) <= 10 && (float) $value <= 4294967295;
    }
}
