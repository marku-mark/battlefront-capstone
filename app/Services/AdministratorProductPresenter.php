<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Tag;

class AdministratorProductPresenter
{
    public function __construct(private readonly CatalogProductPresenter $catalogPresenter) {}

    /** @return array<string, mixed> */
    public function archived(Product $product): array
    {
        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'is_catalog_imported' => $product->is_catalog_imported,
            'name' => $product->name,
            'brand' => $product->brand,
            'price' => $product->price,
            'discount_price' => $product->discount_price,
            'image_url' => $product->image_url,
            'is_featured' => $product->is_featured,
            'is_active' => false,
            'deleted_at' => $product->deleted_at?->toDateTimeString(),
            'deleted_by' => $product->deletedBy?->name,
            'inventory' => $product->inventory === null ? null : [
                'quantity' => $product->inventory->quantity,
                'quarantined_quantity' => $product->inventory->quarantined_quantity,
            ],
            'category' => [
                'name' => $product->category->name,
                'is_active' => $product->category->is_active,
                'is_deleted' => $product->category->trashed(),
            ],
            'tags' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function listing(Product $product, bool $canViewCost): array
    {
        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'is_catalog_imported' => $product->is_catalog_imported,
            'name' => $product->name,
            'brand' => $product->brand,
            'price' => $product->price,
            ...($canViewCost ? ['acquisition_cost' => $product->acquisition_cost] : []),
            'discount_price' => $product->discount_price,
            'image_url' => $product->image_url,
            'is_featured' => $product->is_featured,
            'is_active' => $product->is_active,
            'inventory' => $product->inventory === null ? null : [
                'quantity' => $product->inventory->quantity,
                'quarantined_quantity' => $product->inventory->quarantined_quantity,
            ],
            'storefront_visibility' => match (true) {
                ! $product->is_active => 'Inactive product',
                ! $product->category->is_active => 'Inactive category',
                $product->inventory === null => 'Inventory not initialized',
                $product->inventory->quantity === 0 => 'Out of stock',
                default => 'Visible in storefront',
            },
            'shipping_handling' => $this->catalogPresenter->handling($product->shipping_profile),
            'category' => [
                'name' => $product->category->name,
                'is_active' => $product->category->is_active,
            ],
            'tags' => $product->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->values(),
        ];
    }
}
