<?php

namespace App\Actions\Product;

use App\Actions\Catalog\CatalogName;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateProduct
{
    public function __construct(
        private readonly StoreProductImage $storeProductImage,
        private readonly DeleteManagedProductImage $deleteManagedProductImage,
    ) {}

    /**
     * Update a product, synchronize tags, and replace its image safely.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function execute(Product $product, array $attributes, array $tagIds, ?UploadedFile $image): Product
    {
        if (isset($attributes['name'])) {
            $attributes['name'] = CatalogName::normalize($attributes['name']);
        }
        $storedImage = null;

        try {
            DB::transaction(function () use ($product, $attributes, $tagIds, $image, &$storedImage): void {
                $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
                if ($lockedProduct->is_catalog_imported && isset($attributes['product_code'])
                    && $attributes['product_code'] !== $lockedProduct->product_code) {
                    throw ValidationException::withMessages(['product_code' => 'Imported product codes cannot be changed.']);
                }
                $oldImagePath = $lockedProduct->image_path;
                $storedImage = $this->storeProductImage->execute($image, $lockedProduct->id);
                if ($storedImage !== null) {
                    $attributes['image_path'] = $storedImage['path'];
                }
                $lockedProduct->update($attributes);
                $lockedProduct->tags()->sync($tagIds);

                if ($storedImage !== null && $storedImage['path'] !== $oldImagePath) {
                    DB::afterCommit(fn () => $this->deleteManagedProductImage->execute($oldImagePath, $lockedProduct->id));
                }
            });
        } catch (Throwable $exception) {
            if ($storedImage !== null && $storedImage['created']) {
                $this->deleteManagedProductImage->execute($storedImage['path'], $product->id);
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                CatalogName::handleUniqueFailure($exception, 'products', CatalogName::PRODUCT_MESSAGE);
            }

            throw $exception;
        }

        return $product->refresh();
    }
}
