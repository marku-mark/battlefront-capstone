<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Support\Facades\Log;

class ChatbotCatalogSelection
{
    public function __construct(private ProductCatalogRepository $catalog, private ResolveProductContext $facts, private ChatbotPriceIntent $prices, private ChatbotProductRanking $ranking) {}

    /** @param array<string, mixed> $intent
     * @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function select(string $message, array $intent, array $state = []): array
    {
        $price = $this->prices->resolve($message, $intent);
        $previous = $state['model_constraints'] ?? [];
        $followUp = $intent['reference'] === 'previous';
        if ($followUp) {
            foreach (['minimum', 'maximum'] as $bound) {
                if (! $price['has_'.$bound] && isset($previous['price'][$bound])) {
                    $price[$bound] = $previous['price'][$bound];
                    $price[$bound.'_inclusive'] = $previous['price'][$bound.'_inclusive'];
                }
            }
            $price['sort'] ??= $previous['price']['sort'] ?? null;
            foreach (['brand', 'capacity', 'use_case'] as $field) {
                if (($intent[$field] ?? '') === '') {
                    $intent[$field] = $previous[$field] ?? '';
                }
            }
            if ($intent['excluded_brands'] === []) {
                $intent['excluded_brands'] = $previous['excluded_brands'] ?? [];
            }
            if ($intent['category_key'] === 'other') {
                $intent['category_key'] = $previous['category_key'] ?? 'other';
            }
        }
        if (preg_match('/\b(?:no budget|any price|remove (?:the )?(?:budget|price limit))\b/iu', $message)) {
            $price['minimum'] = $price['maximum'] = null;
        }
        if ($price['minimum'] !== null && $price['maximum'] !== null && (bccomp($price['minimum'], $price['maximum'], 2) > 0
            || (bccomp($price['minimum'], $price['maximum'], 2) === 0 && (! $price['minimum_inclusive'] || ! $price['maximum_inclusive'])))) {
            $price['error'] = 'Please provide a price range whose minimum is below its maximum.';
        }
        if ($price['error'] !== null) {
            return ['products' => [], 'clarification' => $price['error']];
        }
        $constraints = [...$intent, ...$price];
        $constraints['product_role'] = $this->ranking->accessoryRequest($message) ? 'accessory' : ($intent['product_role'] ?? ($followUp ? ($previous['product_role'] ?? 'main') : 'main'));
        if ($followUp && ($previous['product_role'] ?? null) === 'accessory' && ! $this->ranking->accessoryRequest($message)) {
            $constraints['product_role'] = 'accessory';
        }
        $mentions = $intent['product_mentions'];
        $previousIds = array_slice($state['references']['product'] ?? [], 0, 6);
        $prior = Product::query()->customerEligible()->whereIn('id', $previousIds)->get(['id', 'name', 'product_code']);
        $allowed = $message.' '.implode(' ', $prior->pluck('name')->all());
        $mentions = array_values(array_filter($mentions, fn (string $name): bool => mb_stripos($allowed, $name) !== false));
        $scope = $price['scope'];
        $explicitPrevious = $intent['position'] > 0 || $scope === 'previous'
            || ($followUp && in_array($intent['intent'], ['facts', 'comparison', 'compatibility'], true));
        $alternatives = $price['cheaper'] || preg_match('/\b(?:alternatives?|similar to)\b/iu', $message) === 1;
        $ids = null;
        if ($explicitPrevious && ! $alternatives) {
            $ids = $intent['position'] > 0 ? (isset($previousIds[$intent['position'] - 1]) ? [$previousIds[$intent['position'] - 1]] : []) : $previousIds;
        } elseif ($mentions !== []) {
            $ids = Product::query()->customerEligible()->where(fn ($query) => $query->whereIn('name', $mentions)->orWhereIn('product_code', $mentions))->limit(6)->pluck('id')->all();
        }
        if (($explicitPrevious || $scope === 'named') && ! $alternatives && ($ids === [] || $ids === null)) {
            return ['products' => [], 'clarification' => 'Which product do you mean? Please provide its catalog name.'];
        }
        $category = array_search($intent['category_key'], ChatbotProductRanking::FAMILIES, true);
        $query = in_array($intent['category_key'], ['laptop', 'desktop'], true) ? $intent['category_key'] : ($category ?: $intent['search_query']);
        $literal = app(CatalogSearchIntent::class)->resolve($price['query']);
        if ($literal !== null && ($this->ranking->accessoryRequest($price['query']) || in_array($intent['category_key'], ['laptop', 'desktop', 'other'], true))) {
            $query = $price['query'];
        }
        if ($followUp && ($query === '' || $intent['category_key'] === 'other')) {
            $query = $previous['search_query'] ?? $query;
        }
        $broad = preg_match('/^\s*(?:what products (?:do you have|are available)|what do you (?:sell|have)|browse (?:the )?catalog|(?:cheapest|most expensive) (?:products|items)(?: including accessories)?)\s*[?.!]*$/iu', $message) === 1;
        if ($broad) {
            $query = '';
        }
        if (preg_match('/\bincluding accessories\b/iu', $message)) {
            $constraints['product_role'] = 'any';
        }
        $anchors = $ids ?? ($alternatives ? ($intent['position'] > 0 ? (isset($previousIds[$intent['position'] - 1]) ? [$previousIds[$intent['position'] - 1]] : []) : $previousIds) : []);
        if ($alternatives) {
            if (count($anchors) !== 1) {
                return ['products' => [], 'clarification' => 'Please identify one exact product for alternatives.'];
            }
            $anchor = $this->facts->forProduct($anchors[0])['products'][0] ?? null;
            if ($anchor === null) {
                return ['products' => [], 'clarification' => 'The referenced product is no longer available in the catalog.'];
            }
            $query = $anchor['category'];
            $classification = $this->ranking->classify($anchor['category'], $anchor['name']);
            if (in_array($classification['product_family'], ['laptop', 'desktop'], true)) {
                $query = $classification['product_family'];
            }
            if ($price['cheaper']) {
                $price['maximum'] = (string) ($anchor['discount_price'] ?? $anchor['price']);
                $price['maximum_inclusive'] = false;
                $price['sort'] = 'asc';
                $constraints = [...$constraints, ...$price];
            }
            $ids = null;
        }
        $exact = $ids !== null;
        if ($exact) {
            $context = ['products' => $this->facts->forProducts($ids)];
        } else {
            $local = $this->catalog->chatbotCandidates($query, $constraints);
            $rankings = [$local->modelKeys()];
            $extreme = $price['sort'] !== null;
            if (! $extreme && ($intent['intent'] === 'recommendation' || $intent['use_case'] !== '' || $local->isEmpty())) {
                $semanticFilters = ['product_role' => $constraints['product_role']];
                if (in_array($intent['category_key'], ['laptop', 'desktop'], true)) {
                    $semanticFilters['product_family'] = $intent['category_key'];
                }
                $rankings[] = app(SemanticCatalogCandidates::class)->ids(trim($query.' '.$intent['use_case']), $semanticFilters);
                $seeds = $alternatives ? $anchors : array_slice($local->modelKeys(), 0, 2);
                $rankings[] = app(GraphCatalogCandidates::class)->forSeeds($seeds);
            }
            $candidateIds = array_values(array_unique(array_merge(...$rankings)));
            $filterQuery = app(CatalogSearchIntent::class)->resolve($query) === null ? '' : $query;
            $current = $extreme ? $local : $this->catalog->chatbotCandidates($filterQuery, $constraints, $candidateIds);
            $scores = [];
            foreach ($rankings as $list) {
                foreach ($list as $rank => $id) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (61 + $rank);
                }
            }
            if (! $extreme) {
                $current = $current->sort(fn (Product $a, Product $b): int => (($scores[$b->id] ?? 0) <=> ($scores[$a->id] ?? 0)) ?: ($a->id <=> $b->id));
            }
            $context = ['products' => $this->facts->forProducts($current->modelKeys())];
            if ($broad && ! $extreme) {
                $representatives = $this->catalog->chatbotCandidates('', [...$constraints, 'diversify' => true]);
                $context['products'] = [...$context['products'], ...$this->facts->forProducts($representatives->modelKeys())];
            }
        }
        $products = collect($context['products'])->unique('id')->filter(function (array $product) use ($price, $constraints, $exact, $intent, $alternatives, $anchors): bool {
            if (! $this->prices->matches($price, $product)) {
                return false;
            }
            if ($alternatives && in_array($product['id'], $anchors, true)) {
                return false;
            }
            foreach ($constraints['excluded_brands'] as $brand) {
                if ($brand !== '' && mb_stripos(($product['brand'] ?? '').' '.$product['name'], $brand) !== false) {
                    return false;
                }
            }

            return $exact && ! in_array($intent['intent'], ['discovery', 'recommendation'], true)
                || (! $product['is_demo'] && in_array($product['inventory']['status'], ['in_stock', 'low_stock'], true));
        })->values()->all();
        $ordered = $exact && $price['sort'] === null ? $products : $this->ranking->order($products, $price['sort'], $constraints['product_role'] === 'accessory', $broad, $constraints['product_role'] === 'any' || $exact);
        $products = array_slice($ordered, 0, 6);
        $scope = $exact ? 'selected_products' : ($broad ? ($constraints['product_role'] === 'any' ? 'catalog_all_products' : 'catalog_main_products') : 'matching_catalog');
        $comparisonIds = array_column($products, 'id');
        if (! $exact && $constraints['product_role'] !== 'any') {
            $preferred = array_values(array_filter($products, fn (array $product): bool => $this->ranking->classify($product['category'], $product['name'])['product_role'] === $constraints['product_role']));
            if ($preferred !== []) {
                $comparisonIds = array_column($preferred, 'id');
            }
        }
        $priceEvidence = ['sort' => $price['sort'], 'scope' => $scope, 'ordered_ids' => array_column($products, 'id'),
            'comparison_ids' => $comparisonIds, 'product_role' => $constraints['product_role'],
            'names' => array_column($products, 'name', 'id'),
            'minimum_php' => $price['minimum'], 'maximum_php' => $price['maximum'],
            'prices' => array_column(array_map(fn (array $product): array => ['id' => $product['id'], 'price' => (string) ($product['discount_price'] ?? $product['price'])], $products), 'price', 'id')];
        Log::info('Chatbot catalog selection completed.', ['candidates' => count($context['products']), 'accepted' => count($products), 'sort' => $price['sort'] ?? 'relevance', 'scope' => $scope]);

        return ['products' => $products, 'search_query' => $query, 'price_selection' => $priceEvidence,
            'selection_constraints' => ['category_key' => $intent['category_key'], 'search_query' => $query,
                'price' => array_intersect_key($price, array_flip(['sort', 'minimum', 'maximum', 'minimum_inclusive', 'maximum_inclusive'])),
                'product_role' => $constraints['product_role'], 'brand' => $intent['brand'], 'capacity' => $intent['capacity'],
                'excluded_brands' => $constraints['excluded_brands'], 'use_case' => $intent['use_case']]];
    }
}
