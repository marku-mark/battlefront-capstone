<?php

namespace App\Services;

use App\Actions\Product\OptimizeProductImage;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

class CatalogImagePipeline
{
    public function __construct(private readonly OptimizeProductImage $optimizer) {}

    /**
     * @return array<string, mixed>|null
     */
    public function next(?string $productCode = null, ?string $manifestPath = null): ?array
    {
        $manifest = $this->readManifest($this->manifestPath($manifestPath));

        if ($productCode !== null) {
            foreach ($manifest['entries'] as $entry) {
                if ($entry['product_code'] === $productCode) {
                    return $entry;
                }
            }

            return null;
        }

        foreach ($manifest['entries'] as $entry) {
            if ($entry['status'] === 'pending') {
                return $entry;
            }
        }
        foreach ($manifest['entries'] as $entry) {
            if ($entry['status'] === 'failed') {
                return $entry;
            }
        }

        return null;
    }

    public function fail(string $productCode, string $reason, ?string $manifestPath = null): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A generation failure needs a reason.');
        }

        $path = $this->manifestPath($manifestPath);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The catalog image manifest could not be locked.');
        }

        try {
            $manifest = $this->readManifest($path);
            foreach ($manifest['entries'] as $index => $entry) {
                if ($entry['product_code'] !== $productCode) {
                    continue;
                }
                if ($entry['status'] === 'complete' || Storage::disk('public')->exists($this->imagePath($entry))) {
                    throw new RuntimeException('A saved product image cannot be marked as a failed generation.');
                }

                $manifest['entries'][$index]['status'] = 'failed';
                $manifest['entries'][$index]['attempts'] = ($entry['attempts'] ?? 0) + 1;
                $manifest['entries'][$index]['last_error'] = $reason;
                $this->writeManifest($path, $manifest);

                return;
            }

            throw new RuntimeException("Unknown product code $productCode.");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function ingest(string $productCode, string $sourcePath, ?string $manifestPath = null): string
    {
        $path = $this->manifestPath($manifestPath);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The catalog image manifest could not be locked.');
        }

        try {
            $manifest = $this->readManifest($path);
            foreach ($manifest['entries'] as $index => $entry) {
                if ($entry['product_code'] !== $productCode) {
                    continue;
                }

                $imagePath = $this->imagePath($entry);
                if ($entry['status'] === 'complete') {
                    if ($this->isVerifiedImage($entry)) {
                        return $imagePath;
                    }

                    throw new RuntimeException('The completed image is missing or changed. Audit before resuming.');
                }

                $optimized = $this->optimizer->execute($sourcePath);
                $bytes = $optimized['bytes'];
                $checksum = hash('sha256', $bytes);
                $disk = Storage::disk('public');
                if ($disk->exists($imagePath)) {
                    if (hash('sha256', $disk->get($imagePath)) !== $checksum) {
                        throw new RuntimeException('A different image already occupies this product path.');
                    }
                } else {
                    if (! $disk->put($imagePath, $bytes, 'public')) {
                        throw new RuntimeException('The product image could not be written completely.');
                    }
                }

                $manifest['entries'][$index]['status'] = 'complete';
                $manifest['entries'][$index]['last_error'] = null;
                $manifest['entries'][$index]['image_sha256'] = $checksum;
                $manifest['entries'][$index]['source_image_sha256'] = hash_file('sha256', $sourcePath);
                $manifest['entries'][$index]['image_bytes'] = strlen($bytes);
                $manifest['entries'][$index]['image_width'] = $optimized['width'];
                $manifest['entries'][$index]['image_height'] = $optimized['height'];
                $this->writeManifest($path, $manifest);

                return $imagePath;
            }

            throw new RuntimeException("Unknown product code $productCode.");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{total: int, complete: int, pending: int, failed: int, issues: list<string>}
     */
    public function audit(?string $manifestPath = null): array
    {
        $manifest = $this->readManifest($this->manifestPath($manifestPath));
        $issues = [];
        $complete = 0;
        $failed = 0;
        $expectedPaths = [];
        $imageChecksums = [];

        foreach ($manifest['entries'] as $entry) {
            $imagePath = $this->imagePath($entry);
            $expectedPaths[strtolower($imagePath)] = true;

            if ($entry['status'] === 'complete') {
                $complete++;
                if (! $this->isVerifiedImage($entry)) {
                    $issues[] = "Missing or invalid completed image: $imagePath";
                } else {
                    $checksum = $entry['image_sha256'];
                    if (isset($imageChecksums[$checksum])) {
                        $issues[] = "Duplicate catalog image content: {$imageChecksums[$checksum]} and $imagePath";
                    } else {
                        $imageChecksums[$checksum] = $imagePath;
                    }
                }
            } else {
                if ($entry['status'] === 'failed') {
                    $failed++;
                    $issues[] = "Failed generation for {$entry['product_code']}: {$entry['last_error']}";
                }
                if (Storage::disk('public')->exists($imagePath)) {
                    $issues[] = "Unrecorded image at pending path: $imagePath";
                }
            }
        }

        foreach (Storage::disk('public')->allFiles('products') as $path) {
            if (! str_starts_with($path, 'products/admin/') && preg_match('~^products/[^/]+/[^/]+$~', $path) && ! isset($expectedPaths[strtolower($path)])) {
                $issues[] = "Unmapped catalog image: $path";
            }
        }

        return [
            'total' => count($manifest['entries']),
            'complete' => $complete,
            'pending' => count($manifest['entries']) - $complete - $failed,
            'failed' => $failed,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function importEntries(?string $manifestPath = null): array
    {
        $audit = $this->audit($manifestPath);
        if ($audit['total'] === 0 || $audit['complete'] !== $audit['total'] || $audit['issues'] !== []) {
            throw new RuntimeException('Every catalog image must pass the audit before product import.');
        }

        return $this->readManifest($this->manifestPath($manifestPath))['entries'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(?string $manifestPath = null): array
    {
        return $this->readManifest($this->manifestPath($manifestPath))['entries'];
    }

    private function manifestPath(?string $path): string
    {
        return $path ?? storage_path('app/private/product-catalog-images/manifest.json');
    }

    /**
     * @return array{version: int, entries: list<array<string, mixed>>}
     */
    private function readManifest(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Catalog image manifest not found at $path.");
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The catalog image manifest is invalid JSON.', previous: $exception);
        }

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! is_array($manifest['entries'] ?? null) || ! array_is_list($manifest['entries'])) {
            throw new RuntimeException('The catalog image manifest has an unsupported structure.');
        }

        $codes = [];
        $paths = [];
        $entries = [];
        foreach ($manifest['entries'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['product_code'] ?? null) || ! in_array($entry['status'] ?? null, ['pending', 'failed', 'complete'], true)) {
                throw new RuntimeException('The catalog image manifest contains an invalid row.');
            }

            $codeKey = strtolower($entry['product_code']);
            $imagePath = strtolower($this->imagePath($entry));
            if (isset($codes[$codeKey]) || isset($paths[$imagePath])) {
                throw new RuntimeException('The catalog image manifest contains duplicate codes or paths.');
            }
            $codes[$codeKey] = true;
            $paths[$imagePath] = true;
            $entries[] = $entry;
        }

        return ['version' => 1, 'entries' => $entries];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function imagePath(array $entry): string
    {
        $code = $entry['product_code'] ?? null;
        $slug = $entry['category_slug'] ?? null;
        $path = $entry['image_path'] ?? null;
        if (! is_string($code) || ! preg_match(Product::CODE_PATTERN, $code)
            || ! is_string($slug) || $slug === 'admin' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
            || $path !== "products/$slug/$code.webp") {
            throw new RuntimeException('The catalog image manifest contains an unsafe product image path.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isVerifiedImage(array $entry): bool
    {
        $imagePath = $this->imagePath($entry);
        $disk = Storage::disk('public');
        if (! is_string($entry['image_sha256'] ?? null)) {
            return false;
        }

        try {
            $contents = $disk->get($imagePath);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($contents)) {
            return false;
        }

        $details = @getimagesizefromstring($contents);

        return $details !== false
            && $details['mime'] === 'image/webp'
            && $details[0] === 1024
            && $details[1] === 1024
            && strlen($contents) === ($entry['image_bytes'] ?? null)
            && hash('sha256', $contents) === $entry['image_sha256'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('The catalog image manifest could not be saved.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
