<?php

namespace App\Console\Commands;

use App\Services\RealCatalogImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('catalog:prepare-demo-inventory
    {--manifest= : Path to the audited catalog image manifest}
    {--mapping= : Path to the verified product details CSV}
    {--approvals= : Path to the development inventory approvals}')]
#[Description('Prepare reproducible demo inventory in private catalog files without changing the database')]
class PrepareDemoCatalogInventoryCommand extends Command
{
    private const EXCEPTIONS = [
        '80520996' => ['quantity' => 1, 'reorder_level' => 3],
        '10044' => ['quantity' => 2, 'reorder_level' => 3],
        '6940056198471' => ['quantity' => 3, 'reorder_level' => 5],
        '301501828' => ['quantity' => 0, 'reorder_level' => 3],
    ];

    public function handle(RealCatalogImportService $importer): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Demo inventory preparation is only allowed in local and testing environments.');

            return self::FAILURE;
        }

        $staged = [];
        $originals = [];
        $published = [];

        try {
            $manifestPath = $this->option('manifest') ?? storage_path('app/private/product-catalog-images/manifest.json');
            $mappingPath = $this->option('mapping') ?? storage_path('app/imports/product_catalog/verified-product-details.csv');
            $approvalsPath = $this->option('approvals') ?? storage_path('app/private/product-catalog-images/development-value-approvals.json');
            $paths = [$manifestPath, $mappingPath, $approvalsPath];
            if (count(array_unique(array_map(fn (string $path): string => strtolower(realpath(dirname($path)).'/'.basename($path)), $paths))) !== 3) {
                throw new RuntimeException('Manifest, mapping and approvals must use different paths.');
            }
            foreach ($paths as $path) {
                if (! is_dir(dirname($path)) || (file_exists($path) && ! is_file($path))) {
                    throw new RuntimeException("Invalid output path: $path.");
                }
                $originals[$path] = is_file($path) ? $this->readFile($path) : null;
            }

            $prepared = $importer->inspectFiles($mappingPath, $manifestPath);
            $codes = array_column($prepared, 'product_code');
            if (count($prepared) !== 654 || array_diff(array_keys(self::EXCEPTIONS), $codes) !== []) {
                throw new RuntimeException('Expected 654 catalog products including all four demo stock examples.');
            }

            /** @var array{version: int, entries: list<array<string, mixed>>} $manifest */
            $manifest = json_decode($this->readFile($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $values = [];
            $approvals = [
                'version' => 2,
                'purpose' => 'Developer-approved reproducible demo inventory; not verified operational or historical client data',
                'profile' => 'retailer-demo-v1',
                'mapping' => $mappingPath,
                'manifest' => $manifestPath,
                'products' => [],
                'quantity_overrides' => [],
            ];
            foreach ($manifest['entries'] as &$entry) {
                $code = (string) $entry['product_code'];
                if (($entry['quantity_source'] ?? null) === 'development_demo' && ! array_key_exists('source_quantity', $entry)) {
                    throw new RuntimeException("Original spreadsheet quantity provenance is missing for code $code.");
                }
                $sourceQuantity = array_key_exists('source_quantity', $entry) ? $entry['source_quantity'] : $entry['quantity'];
                if ($sourceQuantity !== null && (! is_string($sourceQuantity) || ! preg_match('/^\d+$/D', $sourceQuantity))) {
                    throw new RuntimeException("Invalid original spreadsheet quantity for code $code.");
                }
                $values[$code] = $this->inventoryValues($code, (string) $entry['category'], (string) $entry['name'], (string) $entry['price']);
                $entry['source_quantity'] = $sourceQuantity;
                $entry['quantity_source'] = 'development_demo';
                $entry['quantity'] = $sourceQuantity === null ? null : (string) $values[$code]['quantity'];
                $approvals['products'][$code] = ['source_quantity' => $sourceQuantity, ...$values[$code]];
                if ($sourceQuantity === null) {
                    $approvals['quantity_overrides'][] = [
                        'product_code' => $code,
                        'value' => $values[$code]['quantity'],
                        'reason' => 'Original spreadsheet quantity is missing; approved demo value, not verified client inventory',
                    ];
                }
            }
            unset($entry);

            $manifestTemporary = $this->stage($manifestPath, $this->encodeJson($manifest));
            $staged[$manifestPath] = $manifestTemporary;
            $mappingTemporary = $this->stage($mappingPath, $this->mappingContents($mappingPath, $values, $approvals['products']));
            $staged[$mappingPath] = $mappingTemporary;
            $staged[$approvalsPath] = $this->stage($approvalsPath, $this->encodeJson($approvals));
            $validated = $importer->inspectFiles($mappingTemporary, $manifestTemporary);
            $lowStock = count(array_filter($validated, fn (array $row): bool => $row['quantity'] > 0 && $row['quantity'] <= $row['reorder_level']));
            $outOfStock = count(array_filter($validated, fn (array $row): bool => $row['quantity'] === 0));
            if (count($validated) !== 654 || $lowStock !== 3 || $outOfStock !== 1) {
                throw new RuntimeException('The prepared demo inventory must contain exactly 650 in stock, 3 low stock and 1 out of stock.');
            }

            foreach ($staged as $path => $temporary) {
                if (! @rename($temporary, $path)) {
                    throw new RuntimeException("Could not publish $path.");
                }
                $published[] = $path;
            }
            $this->info('Prepared demo inventory: 654 products; 650 in stock, 3 low stock, 1 out of stock. No database records were changed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            foreach (array_reverse($published) as $path) {
                $original = $originals[$path];
                if ($original === null ? ! @unlink($path) : file_put_contents($path, $original) === false) {
                    $this->error("Could not restore $path; review the private catalog files before importing.");
                }
            }
            $this->error('Demo inventory preparation failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    /** @return array{quantity: int, reorder_level: int} */
    private function inventoryValues(string $code, string $category, string $name, string $price): array
    {
        if (! is_numeric($price)) {
            throw new RuntimeException("Invalid catalog price for code $code.");
        }
        if (isset(self::EXCEPTIONS[$code])) {
            return self::EXCEPTIONS[$code];
        }
        if (bccomp($price, '5000', 2) >= 0
            || in_array($category, ['Graphics Card', 'Processor', 'Motherboard', 'Laptops & Desktops', 'Monitor', 'PC Case'], true)
            || ($category === 'Bundles & Packages' && bccomp($price, '3000', 2) >= 0)) {
            [$minimum, $rangeWidth, $reorderLevel] = [4, 5, 3];
        } elseif (preg_match('/\b(?:INK|TONER|BOND PAPER|CLEANING SOLUTION|THERMAL PASTE)\b/i', $name)) {
            [$minimum, $rangeWidth, $reorderLevel] = [25, 16, 10];
        } elseif (in_array($category, ['Cables & Adapters', 'Power Accessories', 'Laptop Bags & Stands'], true)
            || (in_array($category, ['Networking', 'CCTV & Security', 'Audio Equipment', 'Peripherals', 'Storage'], true) && bccomp($price, '1000', 2) <= 0)) {
            [$minimum, $rangeWidth, $reorderLevel] = [15, 10, 8];
        } else {
            [$minimum, $rangeWidth, $reorderLevel] = [8, 7, 5];
        }

        return ['quantity' => $minimum + array_sum(array_map(ord(...), str_split($code))) % $rangeWidth, 'reorder_level' => $reorderLevel];
    }

    /**
     * Preserve CSV identity and brand cells exactly; only replace stock cells.
     *
     * @param  array<string, array{quantity: int, reorder_level: int}>  $values
     * @param  array<string, array{source_quantity: string|null, quantity: int, reorder_level: int}>  $products
     */
    private function mappingContents(string $path, array $values, array $products): string
    {
        $input = fopen($path, 'r');
        $output = fopen('php://temp', 'w+');
        if ($input === false || $output === false) {
            throw new RuntimeException('Could not open the inventory mapping streams.');
        }
        try {
            while (($cells = fgetcsv($input, 0, ',', '"', '')) !== false) {
                if (isset($values[$cells[0] ?? ''])) {
                    $code = $cells[0];
                    $cells[4] = $products[$code]['source_quantity'] === null ? (string) $values[$code]['quantity'] : '';
                    $cells[5] = (string) $values[$code]['reorder_level'];
                }
                if (fputcsv($output, $cells, ',', '"', '') === false) {
                    throw new RuntimeException('Could not write the prepared inventory mapping.');
                }
            }
            rewind($output);
            $contents = stream_get_contents($output);
            if ($contents === false) {
                throw new RuntimeException('Could not read the prepared inventory mapping.');
            }

            return $contents;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read $path.");
        }

        return $contents;
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function stage(string $path, string $contents): string
    {
        $temporary = tempnam(dirname($path), 'demo-inventory-');
        if ($temporary === false) {
            throw new RuntimeException("Could not stage $path.");
        }
        if (file_put_contents($temporary, $contents) === false) {
            unlink($temporary);
            throw new RuntimeException("Could not write staged $path.");
        }

        return $temporary;
    }
}
