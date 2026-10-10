<?php

namespace App\Actions\Product;

use App\Actions\Catalog\CatalogName;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateProduct
{
    public function __construct(
        private readonly StoreProductImage $storeProductImage,
        private readonly DeleteManagedProductImage $deleteManagedProductImage,
    ) {}

    /**
     * Create a product, attach tags, and manage its uploaded image.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function execute(array $attributes, array $tagIds, ?UploadedFile $image): Product
    {
        $attributes['name'] = CatalogName::normalize($attributes['name']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $attributes['product_code'] = 'ADMIN'.Str::ulid();
            $storedImage = null;
            $productId = null;

            try {
                return DB::transaction(function () use ($attributes, $tagIds, $image, &$storedImage, &$productId): Product {
                    $product = Product::query()->create($attributes);
                    $productId = $product->id;
                    $storedImage = $this->storeProductImage->execute($image, $productId);
                    if ($storedImage !== null) {
                        $product->update(['image_path' => $storedImage['path']]);
                    }
                    $product->tags()->sync($tagIds);

                    return $product;
                });
            } catch (Throwable $exception) {
                if ($storedImage !== null && $storedImage['created'] && $productId !== null) {
                    $this->deleteManagedProductImage->execute($storedImage['path'], $productId);
                }

                if ($exception instanceof UniqueConstraintViolationException) {
                    $driverMessage = $exception->errorInfo[2] ?? '';
                    if (str_contains($driverMessage, 'products_product_code_unique')
                        || str_contains($driverMessage, 'products.product_code')) {
                        continue;
                    }
                    CatalogName::handleUniqueFailure($exception, 'products', CatalogName::PRODUCT_MESSAGE);
                }

                throw $exception;
            }
        }

        throw ValidationException::withMessages([
            'product_code' => 'A product code could not be generated. Please try saving the product again.',
        ]);
    }
}
