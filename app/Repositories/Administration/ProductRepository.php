<?php

namespace App\Repositories\Administration;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductRepository
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateArchived(array $filters): LengthAwarePaginator
    {
        return Product::onlyTrashed()
            ->when($filters['q'] ?? null, fn (Builder $query, string $search): Builder => $this->search($query, $search))
            ->with([
                'category' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'is_active', 'deleted_at']),
                'deletedBy:id,name',
                'inventory:id,product_id,quantity,quarantined_quantity',
            ])
            ->orderByDesc('deleted_at')->orderBy('id')
            ->paginate(12)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, bool $canViewCost): LengthAwarePaginator
    {
        return Product::query()
            ->select([
                'id',
                'product_code',
                'is_catalog_imported',
                'name',
                'category_id',
                'brand',
                'price',
                'discount_price',
                'image_path',
                'is_featured',
                'is_active',
                'shipping_profile',
            ])
            ->when($canViewCost, fn (Builder $query): Builder => $query->addSelect('acquisition_cost'))
            ->with([
                'category:id,name,is_active',
                'tags:id,name',
                'inventory:id,product_id,quantity,quarantined_quantity',
            ])
            ->when(
                $filters['q'] ?? null,
                fn (Builder $query, string $search): Builder => $this->search($query, $search),
            )
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $filters['brand'] ?? null,
                fn (Builder $query, string $brand): Builder => $query->where('brand', $brand),
            )
            ->when(
                $filters['tag_id'] ?? null,
                fn (Builder $query, int $tagId): Builder => $query->whereHas(
                    'tags',
                    fn (Builder $query): Builder => $query->whereKey($tagId),
                ),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('is_active', $status === 'active'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(12)
            ->appends($filters);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function search(Builder $query, string $search): Builder
    {
        return $query->where(
            fn (Builder $query): Builder => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('product_code', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"),
        );
    }
}
