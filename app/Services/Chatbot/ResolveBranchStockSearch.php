<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;

class ResolveBranchStockSearch
{
    public function __construct(
        private CatalogSearchIntent $catalog,
        private ChatbotBranchAvailability $branches,
        private ResolveProductContext $products,
    ) {}

    /** @param array<string, mixed> $intent
     * @param array<string, mixed> $constraints
     * @param array<string, mixed> $previous
     * @param list<int>|null $ids */
    public function resolve(string $message, array $intent, array $constraints, array $previous, ?array $ids): BranchStockSearch
    {
        $location = $this->branches->locate($message, $intent['branch']);
        $current = $this->branches->stockCatalogQuery($message);
        $previousScope = $intent['reference'] === 'previous' ? ($previous['search_query'] ?? '') : '';
        $defaultScope = array_search($intent['category_key'], ChatbotProductRanking::FAMILIES, true);
        if (in_array($intent['category_key'], ['laptop', 'desktop'], true)) {
            $defaultScope = $intent['category_key'];
        }
        $currentScope = $this->catalog->knownStockScope($current);
        $scope = $currentScope
            ?? $this->catalog->knownStockScope($previousScope)
            ?? $this->catalog->knownStockScope($this->branches->stockCatalogQuery($intent['search_query']))
            ?? $this->catalog->knownStockScope($defaultScope ?: '');

        $ids ??= $this->identifiers($message, $intent['product_mentions']);
        if ($ids === null && $intent['reference'] === 'previous' && ! in_array($intent['constraint_updates']['brand'] ?? null, ['set', 'clear'], true)
            && isset($previous['branch_product_ids'])) {
            $ids = array_values(array_filter(array_slice($previous['branch_product_ids'], 0, 6), fn (mixed $id): bool => is_int($id) && $id > 0));
        }
        if ($ids !== null) {
            $scope = $currentScope ?? $this->catalog->knownStockScope($previousScope);
        }

        $followsPrevious = $intent['reference'] === 'previous';
        $brand = $constraints['brand'];
        if (in_array(mb_strtolower($brand), array_map('mb_strtolower', $constraints['excluded_brands']), true)) {
            $brand = '';
        }
        if (($intent['constraint_updates']['brand'] ?? null) === 'clear'
            || preg_match('/\b(?:any brand|all brands|remove (?:the )?brand(?: filter)?)\b/iu', $message)) {
            $brand = '';
        } elseif ($brand === '' || mb_stripos($message, $brand) === false) {
            $explicitBrand = $this->branches->requestedBrand($current);
            $brand = $explicitBrand !== '' ? $explicitBrand : ($followsPrevious && $brand === ($previous['brand'] ?? '') ? $brand : '');
        }

        $filters = [
            'brand' => $brand,
            'capacity' => $constraints['capacity'],
            'excluded_brands' => $constraints['excluded_brands'],
            ...array_intersect_key($constraints, ['other_than_brand' => true]),
            'product_role' => $constraints['product_role'],
            'minimum' => $constraints['minimum'],
            'maximum' => $constraints['maximum'],
            'minimum_inclusive' => $constraints['minimum_inclusive'],
            'maximum_inclusive' => $constraints['maximum_inclusive'],
            'sort' => $constraints['sort'],
        ];

        return new BranchStockSearch($scope, $ids, $filters, $location);
    }

    /** @param list<string> $mentions
     * @return list<int>|null */
    private function identifiers(string $message, array $mentions): ?array
    {
        $named = $this->products->namedProducts($message);
        if ($named->isNotEmpty()) {
            return array_values(array_map('intval', $named->modelKeys()));
        }

        $codes = $this->branches->modelCodes($message);
        $requested = array_filter($mentions, fn (string $name): bool => mb_stripos($message, $name) !== false);
        if ($codes === []) {
            return $requested === [] ? null : [];
        }

        $ids = [];
        foreach ($codes as $code) {
            $matches = Product::query()->customerEligible()
                ->where(fn ($query) => $query->whereRaw('LOWER(product_code) = ?', [mb_strtolower($code)])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($code).'%']))
                ->limit(7)->get(['id', 'name', 'product_code'])
                ->filter(fn (Product $product): bool => mb_strtolower($product->product_code ?? '') === mb_strtolower($code)
                    || preg_match('/(?<![\p{L}\p{N}-])'.preg_quote($code, '/').'(?![\p{L}\p{N}-])/iu', $product->name) === 1);
            if ($matches->count() !== 1) {
                return [];
            }
            $ids[] = (int) $matches->first()->getKey();
        }

        return array_values(array_unique($ids));
    }
}
