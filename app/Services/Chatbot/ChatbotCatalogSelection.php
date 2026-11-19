<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/** @phpstan-type CatalogContext array{products: list<array<string, mixed>>, clarification?: string, clarification_code?: string, search_query?: string, price_selection?: array<string, mixed>, selection_constraints?: array<string, mixed>, no_match_message?: string, shopping_message?: string, branch_availability_message?: string, branches?: list<array<string, mixed>>} */
class ChatbotCatalogSelection
{
    public function __construct(private ProductCatalogRepository $catalog, private ResolveProductContext $facts, private ChatbotPriceIntent $prices, private ChatbotProductRanking $ranking) {}

    /** @param array<string, mixed> $intent
     * @param  array<string, mixed>  $state
     * @return CatalogContext */
    public function select(string $message, array $intent, array $state = []): array
    {
        $previous = $state['model_constraints'] ?? [];
        $intent = ['branch' => '', 'availability' => 'any', ...$intent];
        $comparisonPosition = $intent['position'];
        $reducer = app(ChatbotIntentState::class);
        $intent = $reducer->apply($intent, $previous);
        $price = $this->prices->resolve($message, $intent);
        if (($intent['constraint_updates']['budget'] ?? null) === 'clear') {
            $price['minimum'] = $price['maximum'] = $price['sort'] = null;
            $price['has_minimum'] = $price['has_maximum'] = false;
        }
        $followUp = $intent['reference'] === 'previous';
        if ($followUp) {
            foreach (['minimum', 'maximum'] as $bound) {
                if ($reducer->inherits($intent, 'budget') && ! $price['has_'.$bound] && isset($previous['price'][$bound])) {
                    $price[$bound] = $previous['price'][$bound];
                    $price[$bound.'_inclusive'] = $previous['price'][$bound.'_inclusive'];
                }
            }
            if ($reducer->inherits($intent, 'budget')) {
                $price['sort'] ??= $previous['price']['sort'] ?? null;
            }
            foreach (['brand', 'capacity', 'use_case', 'branch'] as $field) {
                if ($reducer->inherits($intent, $field) && ($intent[$field] ?? '') === '') {
                    $intent[$field] = $previous[$field] ?? '';
                }
            }
            if ($reducer->inherits($intent, 'excluded_brands') && $intent['excluded_brands'] === []) {
                $intent['excluded_brands'] = $previous['excluded_brands'] ?? [];
            }
            if ($intent['category_key'] === 'other') {
                $intent['category_key'] = $previous['category_key'] ?? 'other';
            }
            if ($reducer->inherits($intent, 'availability') && $intent['availability'] === 'any') {
                $intent['availability'] = $previous['availability'] ?? 'any';
            }
            if (($previous['intent'] ?? null) === 'branch_availability' && $intent['intent'] === 'discovery') {
                $intent['intent'] = 'branch_availability';
            }
        }
        $canonical = app(ChatbotLanguage::class)->canonical($message);
        if (preg_match('/^(?:any brand|all brands|remove (?:the )?brand(?: filter)?)[?.!]*$/iu', $canonical)
            && ($previous['category_key'] ?? 'other') === 'other'
            && ($previous['brand'] ?? '') !== ''
            && in_array(mb_strtolower(trim($previous['search_query'] ?? '')), ['', mb_strtolower($previous['brand'])], true)) {
            return ['products' => [], 'clarification' => 'Which product type would you like to browse?', 'clarification_code' => 'identify_product_type'];
        }
        if (preg_match('/\b(?:any brand|all brands|remove (?:the )?brand(?: filter)?)\b/iu', $canonical)) {
            $intent['brand'] = '';
            $intent['excluded_brands'] = [];
            $price['query'] = trim(preg_replace('/\b(?:any brand|all brands|remove (?:the )?brand(?: filter)?)\b/iu', '', $price['query']) ?? $price['query']);
        }
        if (preg_match('/\b(?:any branch|all branches)\b/iu', $canonical)) {
            $intent['branch'] = '';
        }
        if (preg_match('/\b(?:no budget|any price|remove (?:the )?(?:budget|price limit))\b/iu', $canonical)) {
            $price['minimum'] = $price['maximum'] = null;
            $price['query'] = trim(preg_replace('/\b(?:no budget|any price|remove (?:the )?(?:budget|price limit))\b/iu', '', $canonical) ?? $canonical);
        }
        if ($price['minimum'] !== null && $price['maximum'] !== null && (bccomp($price['minimum'], $price['maximum'], 2) > 0
            || (bccomp($price['minimum'], $price['maximum'], 2) === 0 && (! $price['minimum_inclusive'] || ! $price['maximum_inclusive'])))) {
            $price['error'] = 'Please provide a price range whose minimum is below its maximum.';
        }
        if ($price['error'] !== null) {
            if ($intent['intent'] === 'branch_availability' || $intent['branch'] !== '') {
                app(ChatbotFailureTrace::class)->recordBranchSearch(null, 'invalid_intent', 0);
            }

            return ['products' => [], 'clarification' => $price['error'], 'clarification_code' => 'invalid_price_range'];
        }
        $constraints = [...$intent, ...$price];
        $constraints['product_role'] = $this->ranking->accessoryRequest($message) ? 'accessory' : ($intent['product_role'] ?? ($followUp ? ($previous['product_role'] ?? 'main') : 'main'));
        if ($followUp && ($previous['product_role'] ?? null) === 'accessory' && ! $this->ranking->accessoryRequest($message)) {
            $constraints['product_role'] = 'accessory';
        }
        $mentions = $intent['product_mentions'];
        $previousIds = array_slice($this->productIds($state['references']['product'] ?? []), 0, 6);
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
            $ids = array_values(array_map('intval', Product::query()->customerEligible()->where(fn ($query) => $query->whereIn('name', $mentions)->orWhereIn('product_code', $mentions))->limit(6)->pluck('id')->all()));
        }
        $branchSearch = $intent['intent'] === 'branch_availability' || $intent['branch'] !== '';
        if (($explicitPrevious || $scope === 'named') && ! $alternatives && ($ids === [] || $ids === null) && ! $branchSearch) {
            return ['products' => [], 'clarification' => 'Which product do you mean? Please provide its catalog name.', 'clarification_code' => 'identify_product'];
        }
        $category = array_search($intent['category_key'], ChatbotProductRanking::FAMILIES, true);
        $query = in_array($intent['category_key'], ['laptop', 'desktop'], true) ? $intent['category_key'] : ($category ?: $intent['search_query']);
        if (! $branchSearch) {
            $literalSource = $intent['search_query'] !== '' ? $this->prices->resolve(app(ChatbotLanguage::class)->canonical($intent['search_query']), $intent)['query'] : $price['query'];
            $literalSource = trim(preg_replace('/\b(?:no budget|any price|remove (?:the )?(?:budget|price limit)|any brand|all brands|remove (?:the )?brand(?: filter)?)\b/iu', '', $literalSource) ?? $literalSource);
            $literalSource = app(ChatbotLanguage::class)->withoutStockTiming($literalSource);
            $literalQuery = trim(preg_replace('/^(?:available|do you have|show me)\s+/iu', '', $literalSource) ?? $literalSource, ' ?.!');
            if (app(CatalogSearchIntent::class)->resolve($literalQuery) !== null && ! preg_match('/\b(?:recommend|compare|not|without|except|excluding|for)\b/iu', $literalQuery)) {
                $query = $literalQuery;
            }
            if ($followUp && ($query === '' || $intent['category_key'] === 'other')) {
                $query = $previous['search_query'] ?? $query;
            }
        }
        $broad = preg_match('/^\s*(?:what products (?:do you have|are available)|what do you (?:sell|have)|browse (?:the )?catalog|(?:cheapest|most expensive) (?:products|items)(?: including accessories)?)\s*[?.!]*$/iu', $message) === 1;
        if ($broad) {
            $query = '';
        }
        if (preg_match('/\bincluding accessories\b/iu', $message)) {
            $constraints['product_role'] = 'any';
        }
        $anchors = $ids ?? ($alternatives ? ($comparisonPosition > 0 ? array_slice($previousIds, $comparisonPosition - 1, 1) : $previousIds) : []);
        if ($alternatives) {
            if (count($anchors) !== 1) {
                return ['products' => [], 'clarification' => $price['cheaper']
                    ? 'Which product should I compare prices with? You can also give me a maximum budget.'
                    : 'Please identify one exact product for alternatives.', 'clarification_code' => 'identify_alternative_anchor'];
            }
            $anchor = $this->facts->forProduct($anchors[0])['products'][0] ?? null;
            if ($anchor === null) {
                return ['products' => [], 'clarification' => 'The referenced product is no longer available in the catalog.', 'clarification_code' => 'product_unavailable'];
            }
            $query = $followUp && ($previous['search_query'] ?? '') !== '' ? $previous['search_query'] : $anchor['category'];
            $classification = $this->ranking->classify($anchor['category'], $anchor['name']);
            if (in_array($classification['product_family'], ['laptop', 'desktop'], true)) {
                $query = $classification['product_family'];
            }
            if ($price['cheaper']) {
                $anchorPrice = (string) ($anchor['discount_price'] ?? $anchor['price']);
                if ($price['maximum'] === null || bccomp($anchorPrice, $price['maximum'], 2) <= 0) {
                    $price['maximum'] = $anchorPrice;
                    $price['maximum_inclusive'] = false;
                }
                $price['sort'] = 'asc';
                $constraints = [...$constraints, ...$price];
            }
            $ids = null;
        }
        $request = null;
        if ($branchSearch) {
            try {
                $request = app(ResolveBranchStockSearch::class)->resolve($canonical, $intent, $constraints, $previous, $ids);
                $ids = $request->productIds;
                $constraints = array_replace($constraints, $request->filters);
                $intent['brand'] = $request->filters['brand'];
                $query = $request->scope['label'] ?? '';
                if ($request->location['outcome'] === 'sagay'
                    && (! $request->canCheckStock() || $ids === [] || ($scope === 'named' && $ids === null))) {
                    app(ChatbotFailureTrace::class)->recordBranchSearch($request, 'unresolved_input', 0);

                    return ['products' => [], 'clarification' => 'Which product do you mean? Please provide its catalog name.', 'clarification_code' => 'identify_product'];
                }
                $localIds = array_values(array_map('intval', $this->catalog->branchStockCandidates($request)->modelKeys()));
                if ($ids !== null && $price['sort'] === null) {
                    $localIds = array_values(array_intersect($ids, $localIds));
                }
                $branchProducts = $this->facts->forProducts($localIds);
            } catch (QueryException $exception) {
                app(ChatbotFailureTrace::class)->recordBranchSearch($request, 'retrieval_failure', 0);

                throw $exception;
            }
        }
        $exact = $ids !== null;
        $provenance = ['exact_sql' => $ids ?? []];
        if ($request !== null) {
            $context = ['products' => $branchProducts];
            $provenance = [$exact ? 'exact_sql' : 'lexical_sql' => $localIds];
        } elseif ($exact) {
            $context = ['products' => $this->facts->forProducts($ids)];
        } else {
            $local = $this->catalog->chatbotCandidates($query, $constraints);
            $localIds = array_map('intval', $local->modelKeys());
            $rankings = [$localIds];
            $extreme = $price['sort'] !== null;
            if (! isset($intent['other_than_brand']) && (config('ai_integration.full_catalog_pipeline') || (! $extreme && ($intent['intent'] === 'recommendation' || $intent['use_case'] !== '' || $local->isEmpty())))) {
                $semanticFilters = ['product_role' => $constraints['product_role']];
                if (in_array($intent['category_key'], ['laptop', 'desktop'], true)) {
                    $semanticFilters['product_family'] = $intent['category_key'];
                }
                $rankings[] = app(SemanticCatalogCandidates::class)->ids(trim($query.' '.$intent['use_case']), $semanticFilters);
                $seeds = $alternatives ? $anchors : array_slice($localIds, 0, 2);
                $rankings[] = app(GraphCatalogCandidates::class)->forSeeds($seeds);
            }
            $candidateIds = array_values(array_unique(array_merge(...$rankings)));
            $provenance = array_combine(array_slice(['lexical_sql', 'qdrant', 'neo4j'], 0, count($rankings)), $rankings);
            $filterQuery = app(CatalogSearchIntent::class)->resolve($query) === null
                ? (in_array($intent['category_key'], ['laptop', 'desktop'], true) ? $intent['category_key'] : ($category ?: '')) : $query;
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
            $context = ['products' => $this->facts->forProducts(array_values($current->modelKeys()))];
            if ($broad && ! $extreme) {
                $representatives = $this->catalog->chatbotCandidates('', [...$constraints, 'diversify' => true]);
                $context['products'] = [...$context['products'], ...$this->facts->forProducts(array_values($representatives->modelKeys()))];
            }
        }
        $products = collect($context['products'])->unique('id')->filter(function (array $product) use ($price, $constraints, $exact, $intent, $alternatives, $anchors, $request): bool {
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
            foreach (['brand', 'capacity'] as $field) {
                $expected = str_replace(' ', '', mb_strtolower($constraints[$field] ?? ''));
                $actual = str_replace(' ', '', mb_strtolower(($field === 'brand' ? ($product['brand'] ?? '').' ' : '').$product['name']));
                if ($expected !== '' && ! str_contains($actual, $expected)) {
                    return false;
                }
            }
            if ($request === null && $exact && ! in_array($intent['intent'], ['comparison', 'compatibility'], true) && $intent['category_key'] !== 'other') {
                $classification = $this->ranking->classify($product['category'], $product['name']);
                $families = $intent['category_key'] === 'computer' ? ['computer', 'laptop', 'desktop'] : [$intent['category_key']];
                if (! in_array($classification['product_family'], $families, true)
                    && ! ($intent['intent'] === 'facts' && $classification['product_family'] === 'unknown')) {
                    return false;
                }
            }
            if (($request !== null || $constraints['availability'] === 'in_stock') && ! in_array($product['inventory']['status'], ['in_stock', 'low_stock'], true)) {
                return false;
            }

            return $exact && ! in_array($intent['intent'], ['discovery', 'branch_availability', 'recommendation'], true)
                || (! $product['is_demo'] && in_array($product['inventory']['status'], ['in_stock', 'low_stock'], true));
        })->values()->all();
        $products = array_values($products);
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
        $trace = app(ChatbotFailureTrace::class);
        if (count($trace->retrieval) < 2) {
            $trace->retrieval[] = ['candidates' => array_map(fn (array $ids): array => array_slice($ids, 0, 40), $provenance),
                'selected_ids' => array_column($products, 'id'), 'scope' => $scope];
        }

        $context = ['products' => $products, 'search_query' => $query, 'price_selection' => $priceEvidence,
            'selection_constraints' => ['category_key' => $intent['category_key'], 'search_query' => $query,
                'branch' => $intent['branch'], 'availability' => $intent['availability'], 'intent' => $intent['intent'], 'operation' => $intent['operation'], 'attributes' => $intent['attributes'] ?? [],
                'price' => array_intersect_key($price, array_flip(['sort', 'minimum', 'maximum', 'minimum_inclusive', 'maximum_inclusive'])),
                'product_role' => $constraints['product_role'], 'brand' => $intent['brand'], 'capacity' => $intent['capacity'],
                'excluded_brands' => $constraints['excluded_brands'], 'use_case' => $intent['use_case']]];
        if ($request?->productIds !== null) {
            $context['selection_constraints']['branch_product_ids'] = $request->productIds;
        }
        if ($products === []) {
            $labels = array_filter([$intent['brand'], $intent['category_key'] === 'other' ? 'the requested product' : $intent['category_key'],
                $price['minimum'] !== null ? ($price['minimum_inclusive'] ? 'at least' : 'above').' PHP '.$price['minimum'] : '',
                $price['maximum'] !== null ? ($price['maximum_inclusive'] ? 'up to' : 'under').' PHP '.$price['maximum'] : '',
                $constraints['excluded_brands'] !== [] ? 'excluding '.implode(', ', $constraints['excluded_brands']) : '']);
            $context['no_match_message'] = 'I found no available products matching these constraints: '.implode(', ', $labels).'. Try changing the brand, model or budget.';
            $context['recovery_options'] = [];
            if (! $exact && ($request === null || $request->canCheckStock()) && app(ChatbotProviderBudget::class)->remainingSeconds() > 1) {
                foreach (['brand', 'budget'] as $field) {
                    $relaxed = $constraints;
                    $relaxedQuery = $query;
                    if ($field === 'brand') {
                        if ($intent['brand'] === '' && $constraints['excluded_brands'] === []) {
                            continue;
                        }
                        if ($intent['brand'] !== '') {
                            $relaxedQuery = trim(preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($intent['brand'], '/').'(?![\p{L}\p{N}])/iu', '', $relaxedQuery) ?? $relaxedQuery);
                        }
                        $relaxed['brand'] = '';
                        $relaxed['excluded_brands'] = [];
                    } else {
                        if ($price['minimum'] === null && $price['maximum'] === null) {
                            continue;
                        }
                        $relaxed['minimum'] = $relaxed['maximum'] = null;
                    }
                    $recovery = $request === null
                        ? $this->catalog->chatbotCandidates($relaxedQuery, $relaxed, useProvider: false)
                        : $this->catalog->branchStockCandidates(new BranchStockSearch($request->scope, null, array_replace($request->filters, array_intersect_key($relaxed, $request->filters)), $request->location));
                    if ($recovery->isNotEmpty()) {
                        $context['recovery_options'][] = $field;
                    }
                }
            }
        }

        if ($request !== null) {
            $context = app(ChatbotBranchAvailability::class)->reply($request, $context);
            $trace->recordBranchSearch($request, match ($request->location['outcome']) {
                'sagay' => $products === [] ? 'zero_matches' : 'matched',
                'unresolved' => 'unresolved_input',
                default => $request->location['outcome'],
            }, count($context['products']));
        }

        if (isset($intent['other_than_brand']) && ($request === null || $request->canCheckStock()) && ! isset($context['clarification'])) {
            $context['shopping_message'] = match (true) {
                $products !== [] && $intent['other_than_brand'] !== '' => 'There are other brands in stock at Sagay for this search.',
                $products !== [] => 'Here are the current brand options in stock at Sagay for this search.',
                $intent['other_than_brand'] !== '' => 'I found no other identifiable brands in stock at Sagay for this search.',
                default => 'I found no available brand options matching this search.',
            };
        }

        return $context;
    }

    /** @return list<int> */
    private function productIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, fn (mixed $id): bool => is_int($id) && $id > 0));
    }
}
