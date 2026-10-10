<?php

namespace App\Repositories\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class ProductCatalogRepository
{
    /**
     * Provide live catalog facts for deterministic recommendation rules.
     *
     * @return Builder<Product>
     */
    public function recommendationInputs(): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'product_code', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path', 'is_featured',
            ])
            ->with([
                'category:id,name',
                'tags:id,name',
                'inventory:id,product_id,quantity',
            ])
            ->withExists([
                'inventory as is_available' => fn (Builder $query): Builder => $query->where('quantity', '>', 0),
            ])
            ->withExists('lowStockInventory as is_low_stock');
    }

    /**
     * Return customer-available products for the catalog directory.
     *
     * @param  array{q: string|null, category_id: int|null, brand: string|null, tag_id: int|null, category_ids?: list<int>, min_price?: string, max_price?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->catalogQuery($filters)->customerAvailable();
        $sort = $filters['sort'] ?? 'featured';

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $query->orderByRaw('CAST(COALESCE(discount_price, price) AS DECIMAL(12, 2)) '.($sort === 'price_asc' ? 'asc' : 'desc'));
        } else {
            $query->orderByDesc('is_featured');
        }

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(12)
            ->appends(array_filter(
                $filters,
                fn (mixed $value): bool => $value !== null
                    && $value !== ''
                    && ! (is_array($value) && $value === []),
            ));
    }

    public function findEligibleOrFail(int $productId): Product
    {
        return $this->catalogQuery()->findOrFail($productId);
    }

    public function findAvailableOrFail(int $productId): Product
    {
        return $this->catalogQuery()->customerAvailable()->findOrFail($productId);
    }

    /**
     * Find customer-eligible products whose catalog attributes match every search term.
     *
     * @param  list<string>  $terms
     * @param  array<int, int>  $excludedProductIds
     * @return EloquentCollection<int, Product>
     */
    public function contextMatches(array $terms, int $limit = 5, bool $availableOnly = false, array $excludedProductIds = []): EloquentCollection
    {
        $matches = (new Product)->newCollection();

        if ($terms === [] || $limit < 1) {
            return $matches;
        }

        $phrase = implode(' ', $terms);
        $tiers = [
            fn (Builder $query): Builder => $this->whereContextPhrase($query, $phrase, exact: true),
            fn (Builder $query): Builder => $this->whereContextPhrase($query, $phrase),
        ];

        if (count($terms) > 1) {
            $tiers[] = fn (Builder $query): Builder => $this->whereContextTerms($query, $terms);
        }

        $tiers[] = fn (Builder $query): Builder => $this->whereContextTerms($query, $terms, includeDescription: true);

        foreach ($tiers as $tier) {
            if ($matches->count() >= $limit) {
                break;
            }

            $query = $this->contextQuery()
                ->without(['category', 'inventory', 'tags'])
                ->when($availableOnly, fn (Builder $query): Builder => $query->customerAvailable())
                ->whereNotIn('products.id', $excludedProductIds)
                ->where(fn (Builder $query): Builder => $tier($query))
                ->orderBy('name')
                ->orderBy('id')
                ->limit($limit - $matches->count());

            if ($matches->isNotEmpty()) {
                $query->whereNotIn('products.id', $matches->modelKeys());
            }

            foreach ($query->get() as $product) {
                $matches->push($product);
            }
        }

        return $matches->load(['category:id,name', 'inventory:id,product_id,quantity,reorder_level', 'tags:id,name']);
    }

    /**
     * Find products with related category, brand, or tag attributes for a viewed product.
     *
     * @param  EloquentCollection<int, Product>  $anchors
     * @param  array<int, int>  $excludedProductIds
     * @return Builder<Product>
     */
    public function similarProducts(EloquentCollection $anchors, array $excludedProductIds = []): Builder
    {
        return $this->contextQuery()
            ->customerAvailable()
            ->whereNotIn('products.id', array_values(array_unique([...$excludedProductIds, ...$anchors->modelKeys()])))
            ->where(function (Builder $query) use ($anchors): void {
                $query->whereRaw('1 = 0');

                foreach ($anchors as $anchor) {
                    $price = $anchor->discount_price ?? $anchor->price;
                    if (! is_numeric($price)) {
                        throw new UnexpectedValueException('Catalog product price must be numeric.');
                    }

                    if (bccomp($price, '0', 2) <= 0) {
                        continue;
                    }

                    $query->orWhere(function (Builder $match) use ($anchor, $price): void {
                        $match->whereRaw('COALESCE(discount_price, price) >= CAST(? AS DECIMAL(12, 2))', [bcmul($price, '0.5', 2)])
                            ->whereRaw('COALESCE(discount_price, price) <= CAST(? AS DECIMAL(12, 2))', [bcmul($price, '2', 2)])
                            ->where(function (Builder $attributes) use ($anchor): void {
                                $attributes->where('category_id', $anchor->category_id);

                                if ($anchor->brand !== null) {
                                    $attributes->orWhereRaw('LOWER(brand) = ?', [mb_strtolower($anchor->brand)]);
                                }

                                if ($anchor->tags->isNotEmpty()) {
                                    $attributes->orWhereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $anchor->tags->modelKeys()));
                                }
                            });
                    });
                }
            });
    }

    /**
     * Provide a small catalog fallback when there is not enough purchase history.
     *
     * @param  array<int, int>  $excludedProductIds
     * @return EloquentCollection<int, Product>
     */
    public function featuredFallback(array $excludedProductIds = [], int $limit = 40): EloquentCollection
    {
        return $this->contextQuery()
            ->customerAvailable()
            ->whereNotIn('products.id', $excludedProductIds)
            ->where('is_featured', true)
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<Product>
     */
    private function contextQuery(): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity,reorder_level',
                'tags:id,name',
            ]);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function whereContextPhrase(Builder $query, string $phrase, bool $exact = false): Builder
    {
        $pattern = $exact ? $phrase : "%{$phrase}%";

        $query
            ->whereLike('name', $pattern)
            ->orWhereLike('brand', $pattern)
            ->orWhereHas(
                'category',
                fn (Builder $categoryQuery): Builder => $categoryQuery->whereLike('name', $pattern),
            )
            ->orWhereHas(
                'tags',
                fn (Builder $tagQuery): Builder => $tagQuery->whereLike('name', $pattern),
            );

        if ($exact) {
            $query
                ->orWhereLike('name', "[DEMO] {$phrase}")
                ->orWhereHas(
                    'category',
                    fn (Builder $categoryQuery): Builder => $categoryQuery->whereLike('name', "[DEMO] {$phrase}"),
                );
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     * @param  list<string>  $terms
     * @return Builder<Product>
     */
    private function whereContextTerms(Builder $query, array $terms, bool $includeDescription = false): Builder
    {
        foreach ($terms as $term) {
            $query->where(function (Builder $attributeQuery) use ($term, $includeDescription): void {
                $this->whereContextPhrase($attributeQuery, $term);

                if ($includeDescription) {
                    $attributeQuery->orWhereLike('description', "%{$term}%");
                }
            });
        }

        return $query;
    }

    /**
     * @return array{
     *     categories: Collection<int, Category>,
     *     brands: Collection<int, string>,
     *     tags: Collection<int, Tag>
     * }
     */
    public function filterOptions(): array
    {
        return [
            'categories' => Category::query()
                ->active()
                ->whereIn('id', Product::query()->customerAvailable()->select('category_id'))
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'brands' => Product::query()
                ->customerAvailable()
                ->whereNotNull('brand')
                ->select('brand')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand'),
            'tags' => Tag::query()
                ->whereHas('products', fn (Builder $query): Builder => $query->whereIn(
                    'products.id',
                    Product::query()->customerAvailable()->select('id'),
                ))
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @param  array{q?: string|null, category_id?: int|null, brand?: string|null, tag_id?: int|null, category_ids?: list<int>, min_price?: string, max_price?: string, sort?: string}  $filters
     * @return Builder<Product>
     */
    private function catalogQuery(array $filters = []): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path', 'is_featured',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('brand', "%{$search}%")
                        ->orWhereLike('description', "%{$search}%");
                });
            })
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $filters['category_ids'] ?? [],
                fn (Builder $query, array $categoryIds): Builder => $query->whereIn('category_id', $categoryIds),
            )
            ->where(function (Builder $query) use ($filters): void {
                if (isset($filters['min_price']) && $filters['min_price'] !== '') {
                    $query->whereRaw('COALESCE(discount_price, price) >= CAST(? AS DECIMAL(12, 2))', [$filters['min_price']]);
                }

                if (isset($filters['max_price']) && $filters['max_price'] !== '') {
                    $query->whereRaw('COALESCE(discount_price, price) <= CAST(? AS DECIMAL(12, 2))', [$filters['max_price']]);
                }
            })
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
            );
    }
}
