<?php

namespace App\Repositories\Inventory;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryRepository
{
    /**
     * Return the filtered administrator inventory ledger.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateProducts(array $filters, string $stockFilter): LengthAwarePaginator
    {
        return Product::query()
            ->select(['id', 'product_code', 'name', 'category_id', 'brand', 'is_active'])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level,last_updated',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when(
                $filters['q'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%"),
                ),
            )
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $stockFilter === 'low_stock',
                fn (Builder $query): Builder => $query->whereHas('lowStockInventory'),
            )
            ->when(
                $stockFilter === 'in_stock',
                fn (Builder $query): Builder => $query->whereHas(
                    'inventory',
                    fn (Builder $inventoryQuery): Builder => $inventoryQuery
                        ->where('quantity', '>', 0)
                        ->whereColumn('quantity', '>', 'reorder_level'),
                ),
            )
            ->when(
                $stockFilter === 'out_of_stock',
                fn (Builder $query): Builder => $query->whereHas(
                    'inventory',
                    fn (Builder $inventoryQuery): Builder => $inventoryQuery->where('quantity', 0),
                ),
            )
            ->when(
                $stockFilter === 'not_initialized',
                fn (Builder $query): Builder => $query->doesntHave('inventory'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->appends($filters);
    }

    /** @return Collection<int, Category> */
    public function categories(): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'is_active'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function lowStockCount(): int
    {
        return Inventory::query()->lowStock()->count();
    }

    public function outOfStockCount(): int
    {
        return Inventory::query()->outOfStock()->count();
    }
}
