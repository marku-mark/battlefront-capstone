<?php

namespace App\Repositories\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Services\Chatbot\BranchStockSearch;
use App\Services\Chatbot\ChatbotProductRanking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Expression;
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
                'price', 'discount_price', 'image_path', 'is_featured', 'shipping_profile',
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
        $intent = isset($filters['q']) ? app(CatalogSearchIntent::class)->resolve($filters['q']) : null;
        $query = $this->catalogQuery($filters, $intent)->customerAvailable();
        $sort = $filters['sort'] ?? 'featured';

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $query->orderByRaw('CAST(COALESCE(discount_price, price) AS DECIMAL(12, 2)) '.($sort === 'price_asc' ? 'asc' : 'desc'));
        } else {
            if ($intent !== null) {
                $this->prioritizePrimaryProducts($query, $intent);
            }
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

    /** @param list<string> $excludedBrands
     * @return EloquentCollection<int, Product> */
    public function chatbotSearch(string $search, bool $useProvider = true, ?string $priceSort = null, ?string $minimum = null, ?string $maximum = null, bool $minimumInclusive = true, bool $maximumInclusive = true, array $excludedBrands = []): EloquentCollection
    {
        return $this->chatbotCandidates($search, ['sort' => $priceSort, 'minimum' => $minimum, 'maximum' => $maximum,
            'minimum_inclusive' => $minimumInclusive, 'maximum_inclusive' => $maximumInclusive, 'excluded_brands' => $excludedBrands], useProvider: $useProvider);
    }

    /** @param array<string, mixed> $constraints
     * @param list<int>|null $ids
     * @return EloquentCollection<int, Product> */
    public function chatbotCandidates(string $search, array $constraints = [], ?array $ids = null, bool $useProvider = false): EloquentCollection
    {
        return $this->readChatbotCandidates($search, $constraints, $ids, $useProvider, null);
    }

    /** @param list<int>|null $candidateIds
     * @return EloquentCollection<int, Product> */
    public function branchStockCandidates(BranchStockSearch $request, ?array $candidateIds = null): EloquentCollection
    {
        if (! $request->canCheckStock()) {
            return new EloquentCollection;
        }

        $ids = $request->productIds;
        if ($candidateIds !== null) {
            $ids = $ids === null ? $candidateIds : array_values(array_intersect($ids, $candidateIds));
        }

        return $this->readChatbotCandidates('', $request->filters, $ids, false, $request->scope);
    }

    /** @param array<string, mixed> $constraints
     * @param list<int>|null $ids
     * @param array{category: string, query: string, label: string}|null $stockScope
     * @return EloquentCollection<int, Product> */
    private function readChatbotCandidates(string $search, array $constraints, ?array $ids, bool $useProvider, ?array $stockScope): EloquentCollection
    {
        $priceSort = $constraints['sort'] ?? null;
        $minimum = $constraints['minimum'] ?? null;
        $maximum = $constraints['maximum'] ?? null;
        $minimumInclusive = $constraints['minimum_inclusive'] ?? true;
        $maximumInclusive = $constraints['maximum_inclusive'] ?? true;
        $intent = $stockScope ?? app(CatalogSearchIntent::class)->resolve($search);
        $computerType = null;
        if (($intent['category'] ?? null) === 'Laptops & Desktops' && preg_match('/^(laptop|desktop)(?:\s|$)/u', $intent['query'], $subtype)) {
            $computerType = $subtype[1];
            $intent['query'] = trim(substr($intent['query'], strlen($computerType)));
        }
        $query = $this->catalogQuery(['q' => $search], $intent, $useProvider, chatbot: true)->customerAvailable()
            ->with('inventory:id,product_id,quantity,reorder_level');
        if ($stockScope !== null) {
            $this->whereSearchCategory($query, $stockScope['category']);
            foreach (explode(' ', $intent['query']) as $term) {
                if ($term !== '') {
                    $this->whereChatbotCatalogTerm($query, $term, $stockScope['category']);
                }
            }
        }
        if ($computerType !== null) {
            $patterns = $computerType === 'laptop' ? ['%laptop%', '%notebook%', '%macbook%'] : ['%desktop%', '%system unit%', '%pc%', '%cpu%'];
            $query->where(function (Builder $computer) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $computer->orWhereRaw('LOWER(products.name) LIKE ?', [$pattern]);
                }
            });
        }
        $ranking = app(ChatbotProductRanking::class);
        $accessory = ($constraints['product_role'] ?? null) === 'accessory' || $ranking->accessoryRequest($search);
        if ($ids !== null) {
            $query->whereIn('products.id', array_slice($ids, 0, 80));
        }
        $query->whereRaw('LOWER(products.name) NOT LIKE ?', ['[demo]%'])
            ->where(fn (Builder $product): Builder => $product->whereNull('image_path')->orWhere('image_path', 'not like', 'images/demo-products/%'));
        if ($intent !== null) {
            $this->prioritizePrimaryProducts($query, $intent, onlyPrimary: true);
            if (! $accessory && isset(ChatbotProductRanking::FAMILIES[$intent['category']])) {
                $query->whereRaw(new Expression($ranking->primarySql()));
            }
        }
        if ($search === '' && $stockScope === null && ($constraints['product_role'] ?? null) !== 'any') {
            $ranking->prioritize($query);
        }
        foreach (['brand', 'capacity'] as $field) {
            if (($constraints[$field] ?? '') !== '') {
                $term = $field === 'capacity' ? str_replace(' ', '', $constraints[$field]) : $constraints[$field];
                $pattern = '%'.strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                if ($field === 'brand') {
                    $query->where(fn (Builder $product): Builder => $product->whereRaw("LOWER(COALESCE(brand, '')) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern]));
                } else {
                    $query->whereRaw("REPLACE(LOWER(products.name), ' ', '') LIKE ? ESCAPE '!'", [$pattern]);
                }
            }
        }

        if (($constraints['other_than_brand'] ?? '') !== '') {
            $query->whereRaw("TRIM(COALESCE(products.brand, '')) <> ''")
                ->whereRaw('LOWER(TRIM(products.brand)) <> ?', [mb_strtolower($constraints['other_than_brand'])]);
        }
        foreach ($constraints['excluded_brands'] ?? [] as $brand) {
            if ($brand !== '') {
                $pattern = '%'.strtr(mb_strtolower($brand), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                $query->whereRaw("LOWER(COALESCE(brand, '')) NOT LIKE ? ESCAPE '!' AND LOWER(products.name) NOT LIKE ? ESCAPE '!'", [$pattern, $pattern]);
            }
        }
        if ($minimum !== null) {
            $query->whereRaw('COALESCE(discount_price, price) '.($minimumInclusive ? '>=' : '>').' CAST(? AS DECIMAL(12, 2))', [$minimum]);
        }
        if ($maximum !== null) {
            $query->whereRaw('COALESCE(discount_price, price) '.($maximumInclusive ? '<=' : '<').' CAST(? AS DECIMAL(12, 2))', [$maximum]);
        }
        if ($constraints['diversify'] ?? false) {
            $representativeQuery = clone $query;
            if (($constraints['product_role'] ?? 'main') === 'main') {
                $representativeQuery->whereRaw(new Expression($ranking->primarySql()));
            }
            $representatives = $representativeQuery->reorder()->select('category_id')->selectRaw('MIN(products.id) AS representative_id')
                ->groupBy('category_id')->limit(20)->toBase()->pluck('representative_id')->all();
            $query->whereIn('products.id', $representatives);
        }
        $priceOrder = match ($priceSort) {
            'asc' => 'CAST(COALESCE(discount_price, price) AS DECIMAL(12, 2)) asc',
            'desc' => 'CAST(COALESCE(discount_price, price) AS DECIMAL(12, 2)) desc',
            default => null,
        };
        if ($priceOrder !== null) {
            $query->orderByRaw($priceOrder)->orderBy('products.id');
        }

        return $query->orderByDesc('is_featured')->orderBy('products.id')->limit($ids === null ? 20 : 80)->get();
    }

    public function hasChatbotCategory(string $search): bool
    {
        $intent = app(CatalogSearchIntent::class)->resolve($search);

        return $intent !== null && Category::query()->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($intent['category'])])->exists();
    }

    /** Rehydrate semantic IDs through the same live intent and device constraints, without calling a search provider.
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Product>
     */
    public function chatbotSemanticMatches(array $ids, ?string $search = null): EloquentCollection
    {
        $intent = $search === null ? null : app(CatalogSearchIntent::class)->resolve($search);
        $query = $this->catalogQuery($search === null ? [] : ['q' => $search], $intent, useProvider: false, chatbot: true)
            ->customerAvailable()->whereIn('products.id', array_slice($ids, 0, 20))
            ->with('inventory:id,product_id,quantity,reorder_level');
        if ($intent !== null) {
            $this->prioritizePrimaryProducts($query, $intent, onlyPrimary: true);
        }

        return $query->limit(20)->get()->sortBy(fn (Product $product): int => (int) array_search($product->id, $ids, true))->values();
    }

    public function findEligibleOrFail(int $productId): Product
    {
        return $this->catalogQuery()->findOrFail($productId);
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array{category: string, query: string}  $intent
     */
    private function prioritizePrimaryProducts(Builder $query, array $intent, bool $onlyPrimary = false): void
    {
        if (preg_match('/\b(ink|toner|cartridge|cable|balun|adapter|adaptor|battery|bracket|mount|mousepad|enclosure|reader|paste|grease)\b/u', $intent['query'])) {
            return;
        }

        $exclusions = match ($intent['category']) {
            'Printer' => ['printer cable%'],
            'Storage' => ['%enclosure%', '%external case%', '%card reader%', '%adapter%', '%cable%'],
            'CCTV & Security' => ['%cable%', '%balun%', '%power supply%', '%hdd%', '%sd card%', '%otg%', '%nap box%'],
            'Networking' => ['%adapter%', '%adaptor%', '%cable%', '%connector%', '%coupler%'],
            'Peripherals' => ['%mousepad%', '%mouse pad%', '%mouse-pad%', '%keycap%', '%keyboard cover%'],
            'Monitor' => ['%bracket%', '%wall mount%'],
            'Cooling Components' => ['%thermal paste%', '%thermal grease%'],
            'Power Accessories' => preg_match('/\b(ups|avr)\b/u', $intent['query']) ? ['%battery%', '%cable%', '%adapter%', '%adaptor%'] : [],
            default => [],
        };
        if ($exclusions === []) {
            return;
        }

        $conditions = [];
        $bindings = [];
        if ($intent['category'] === 'Printer') {
            $conditions[] = 'LOWER(products.name) LIKE ?';
            $bindings[] = 'printer %';
        }
        if ($intent['category'] === 'Monitor' && ! preg_match('/\b(tv|television)\b/u', $intent['query'])) {
            $conditions[] = 'LOWER(products.name) LIKE ?';
            $bindings[] = '%monitor%';
        }
        foreach ($exclusions as $pattern) {
            $conditions[] = 'LOWER(products.name) NOT LIKE ?';
            $bindings[] = $pattern;
        }
        if ($onlyPrimary) {
            $query->whereRaw('('.implode(' AND ', $conditions).')', $bindings);
        } else {
            $query->orderByRaw('CASE WHEN '.implode(' AND ', $conditions).' THEN 0 ELSE 1 END', $bindings);
        }
    }

    /** @param Builder<Product> $query */
    private function whereCatalogTerm(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            $pattern = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            foreach (['product_code', 'name', 'brand', 'description'] as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            }
        });
    }

    /** @param Builder<Product> $query */
    private function whereChatbotCatalogTerm(Builder $query, string $term, string $category): void
    {
        $patterns = app(CatalogSearchIntent::class)->chatbotProductNamePatterns($category, $term);
        if ($patterns === []) {
            $this->whereCatalogTerm($query, $term);

            return;
        }

        $query->where(function (Builder $match) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $match->orWhereRaw('LOWER(products.name) LIKE ?', [$pattern]);
            }
        });
    }

    /** @param Builder<Product> $query */
    private function whereSearchCategory(Builder $query, string $categoryName): void
    {
        $query->where(function (Builder $match) use ($categoryName): void {
            $match->whereHas('category', fn (Builder $category): Builder => $category->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)]));
            if ($categoryName === 'Storage') {
                $match->orWhere(function (Builder $surveillance): void {
                    $surveillance->whereHas('category', fn (Builder $category): Builder => $category->whereRaw('LOWER(name) = ?', ['cctv & security']))
                        ->where(function (Builder $names): void {
                            foreach (['hdd %', 'surveillance hdd %', 'sd card %', 'hiksemi otg %'] as $pattern) {
                                $names->orWhereRaw('LOWER(products.name) LIKE ?', [$pattern]);
                            }
                        });
                });
            }
        });
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
    public function contextMatches(array $terms, int $limit = 5, bool $availableOnly = false, array $excludedProductIds = [], bool $includeDescription = true, bool $loadRelations = true): EloquentCollection
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

        if ($includeDescription) {
            $tiers[] = fn (Builder $query): Builder => $this->whereContextTerms($query, $terms, includeDescription: true);
        }

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

        return $loadRelations ? $matches->load(['category:id,name', 'inventory:id,product_id,quantity,reorder_level', 'tags:id,name']) : $matches;
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
                'price', 'discount_price', 'image_path', 'shipping_profile',
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
     * @param  array{category: string, query: string}|null  $intent
     * @return Builder<Product>
     */
    private function catalogQuery(array $filters = [], ?array $intent = null, bool $useProvider = true, bool $chatbot = false): Builder
    {
        return Product::query()
            ->customerEligible()
            ->select([
                'id', 'name', 'description', 'category_id', 'brand',
                'price', 'discount_price', 'image_path', 'is_featured', 'shipping_profile',
            ])
            ->with([
                'category:id,name',
                'inventory:id,product_id,quantity',
                'tags:id,name',
            ])
            ->withExists('lowStockInventory as is_low_stock')
            ->when($filters['q'] ?? null, function (Builder $query, string $search) use ($intent, $useProvider, $chatbot): void {
                $catalogIntent = app(CatalogSearchIntent::class);
                $quoted = $catalogIntent->quotedSearch($search);
                $original = $quoted['query'] ?? $catalogIntent->normalizeQuery($search);
                $search = $original;
                if ($intent !== null) {
                    $this->whereSearchCategory($query, $intent['category']);
                    $search = $intent['query'];
                    if ($search === '' && ! $chatbot) {
                        return;
                    }
                }

                $providerQuery = $search === '' && $chatbot ? $original : $search;
                $ids = $useProvider && trim($providerQuery) !== '' ? app(CatalogSearch::class)->matchingIds($providerQuery) : null;

                if ($ids !== null) {
                    $query->whereKey($ids);
                    if ($chatbot && $intent !== null) {
                        $scope = $catalogIntent->knownStockScope($original);
                        if ($scope !== null && $catalogIntent->chatbotProductNamePatterns($scope['category'], $scope['query']) !== []) {
                            $this->whereChatbotCatalogTerm($query, $scope['query'], $scope['category']);
                        }
                    }
                }

                $terms = $quoted['phrases'] ?? [];
                if ($ids === null) {
                    $terms = $quoted !== null
                        ? [...$terms, ...$quoted['terms']]
                        : (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                }
                foreach ($terms as $term) {
                    if ($chatbot && $intent !== null) {
                        $this->whereChatbotCatalogTerm($query, $term, $intent['category']);
                    } else {
                        $this->whereCatalogTerm($query, $term);
                    }
                }
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
