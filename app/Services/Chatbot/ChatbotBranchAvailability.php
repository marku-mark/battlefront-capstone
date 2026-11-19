<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Models\Branch;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use Illuminate\Support\Str;

/** @phpstan-import-type CatalogContext from ChatbotCatalogSelection */
class ChatbotBranchAvailability
{
    private const LOCATION_PREPOSITION = '(?:in|at|from|(?:(?:diri|dinhi|dito)\s+)?sa)';

    public function isGeneralOtherBranchStockQuestion(string $message): bool
    {
        $canonical = app(ChatbotLanguage::class)->canonical($message);
        $asksStock = preg_match('/\b(?:stock|stocks|inventory|availability)\b/iu', $canonical) === 1
            || (preg_match('/\bavailable\b/iu', $canonical) === 1 && preg_match('/\bbranch(?:es)?\b/iu', $canonical) !== 1);
        if (! $asksStock
            || preg_match('/\b(?:order|payment|refund|return|delivery|password|otp|api key)\b/iu', $canonical) === 1
            || app(CategorizeChatbotQuery::class)->isBlocked($message)
            || app(CatalogSearchIntent::class)->resolve($canonical) !== null
            || $this->modelCodes($message) !== []
            || app(ResolveProductContext::class)->namesProduct($message)) {
            return false;
        }

        return preg_match('/\b(?:other branch(?:es)?|iban(?: nga)? (?:mga )?branch(?:es)?|ubang(?: mga)? branch(?:es)?|laing(?: mga)? branch(?:es)?|ibang(?: mga)? branch(?:es)?)\b/iu', $canonical) === 1
            || Branch::query()->published()->get(['city', 'name'])
                ->contains(fn (Branch $branch): bool => ! $branch->is_operational && $this->mentions($canonical, $branch));
    }

    public function catalogQuery(string $message): string
    {
        foreach (Branch::query()->published()->get(['city', 'name']) as $branch) {
            foreach ([$branch->city, preg_replace('/\s+city$/iu', '', $branch->city), $branch->name] as $alias) {
                $message = preg_replace('/\b'.self::LOCATION_PREPOSITION.'\s+(?:the\s+)?(?:branch\s+(?:(?:in|at|sa)\s+)?)?'.preg_quote($alias, '/').'(?:\s+(?:(?:nga|na)\s+)?branch)?\b/iu', ' ', $message) ?? $message;
                $message = preg_replace('/\b'.preg_quote($alias, '/').'(?:\s+(?:(?:nga|na)\s+)?branch)?\b/iu', ' ', $message) ?? $message;
            }
        }

        return Str::squish($message);
    }

    public function stockCatalogQuery(string $message): string
    {
        return Str::squish(app(ChatbotLanguage::class)->withoutStockTiming($this->catalogQuery($message)));
    }

    /** @param array<string, mixed> $state */
    public function supports(string $message, array $state = []): bool
    {
        $canonical = app(ChatbotLanguage::class)->canonical($message);
        if ($this->isFollowUp($message, $state)) {
            return true;
        }
        $branchWording = preg_match('/\b(?:branch|branches|where.*(?:buy|purchase)|(?:buy|purchase).*where)\b/iu', $canonical) === 1;
        $namedLocation = ! $branchWording
            && Branch::query()->published()->get(['name', 'city'])->contains(fn (Branch $branch): bool => $this->mentions($message, $branch));

        return ($branchWording || $namedLocation)
            && ! app(CategorizeChatbotQuery::class)->isBlocked($message)
            && preg_match('/\b(?:order|payment|refund|return|delivery|password|otp|api key)\b/iu', $canonical) !== 1
            && ($this->modelCodes($message) !== [] || app(ResolveProductContext::class)->matchesCatalogTerms($message) || app(ResolveProductContext::class)->namesProduct($message))
            && preg_match('/\b(?:hours|opening|closing|contact|phone|address)\b/iu', $canonical) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function localContext(string $message, array $state = []): array
    {
        return app(ChatbotCatalogSelection::class)->select(app(ChatbotLanguage::class)->canonical($message), $this->localIntent($message, $state), $state);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>|null */
    public function brandDiscoveryIntent(string $message, array $state): ?array
    {
        $language = app(ChatbotLanguage::class);
        $query = trim($language->canonical($message), ' .?!');
        $catalog = app(CatalogSearchIntent::class);
        $previous = ($state['active'] ?? null) === 'product' ? ($state['model_constraints'] ?? []) : [];
        $previousQuery = $previous['search_query'] ?? (($state['active'] ?? null) === 'product' ? ($state['catalog_constraints']['category'] ?? '') : '');
        $previousScope = $catalog->knownStockScope($previousQuery);
        $clear = preg_match('/^(?:any brand|all brands|remove (?:the )?brand(?: filter)?)$/iu', $query) === 1;
        $query = preg_replace('/^(?:available|do you (?:also )?have|what about|how about|kanang|katong|yung|iyong|ina nga)\s+/iu', '', $query) ?? $query;
        $query = preg_replace('/\s+(?:brand|instead)$/iu', '', $query) ?? $query;
        if ($query === '' || preg_match('/^[\p{L}\p{N} -]+$/u', $query) !== 1) {
            return null;
        }
        $brand = $clear ? '' : $this->mentionedBrand($query);
        if ($brand === '' && ! ($clear && $previousScope !== null && (($previous['brand'] ?? '') !== '' || ($previous['excluded_brands'] ?? []) !== []))) {
            return null;
        }
        $remainder = $clear ? '' : trim(preg_replace('/\b'.preg_quote($brand, '/').'\b/iu', '', $query, 1) ?? $query);
        $remainder = preg_replace('/^(?:na|nga)\s+/iu', '', $remainder) ?? $remainder;
        $explicit = $remainder === '' ? null : $catalog->resolveStandalone($remainder);
        if ($remainder !== '' && $explicit === null) {
            return null;
        }
        if ($explicit === null && $previousScope === null) {
            return null;
        }
        $scope = $explicit ?? $previousScope;
        $search = $explicit === null ? $previousScope['label'] : $remainder;
        $followUp = $previousScope !== null && $scope['category'] === $previousScope['category'] && $scope['query'] === $previousScope['query'];
        $category = in_array($scope['query'], ['laptop', 'desktop'], true) && $scope['category'] === 'Laptops & Desktops'
            ? $scope['query'] : (ChatbotProductRanking::FAMILIES[$scope['category']] ?? 'other');

        return [
            'outcome' => 'supported', 'intent' => $followUp && ($previous['intent'] ?? null) === 'branch_availability' ? 'branch_availability' : 'discovery',
            'language' => $language->select($message, $state['language'] ?? null),
            'operation' => $followUp ? 'refine' : 'new_search', 'reference' => $followUp ? 'previous' : 'new',
            'category_key' => $category, 'search_query' => $search, 'brand' => $brand,
            'branch' => '', 'availability' => 'any', 'product_mentions' => [], 'attributes' => ['stock'],
            'constraint_updates' => $clear ? ['brand' => 'clear', 'excluded_brands' => 'clear'] : ['brand' => 'set'],
            'capacity' => '', 'use_case' => '', 'excluded_brands' => [],
            'minimum_php' => null, 'maximum_php' => null, 'price_scope' => 'catalog',
            'position' => 0, 'sort' => 'relevance', 'clarification' => 'none',
        ];
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>|null */
    public function shoppingIntent(string $message, array $state): ?array
    {
        $language = app(ChatbotLanguage::class);
        $query = trim($language->canonical($language->withoutLanguageRequest($message)), ' .?!');
        $catalog = app(CatalogSearchIntent::class);
        $previous = ($state['active'] ?? null) === 'product' ? ($state['model_constraints'] ?? []) : [];
        $family = $previous['search_query'] ?? '';
        $price = app(ChatbotPriceIntent::class)->resolve($query);
        $recommendation = preg_match('/^(?:(?:can|could) you )?(?:recommend|suggest)(?: (?:me|a|an|some))* (.+)$/iu', $price['query'], $request);
        $stock = preg_match('/^(?:do you (?:have|sell|carry)|available) (.+)$/iu', trim($language->withoutStockTiming($price['query'])), $stockRequest);
        $requested = trim($recommendation ? $request[1] : ($stock ? $stockRequest[1] : ''), ' .?!');
        $bareRequest = preg_replace('/^(?:ang|the)\s+/iu', '', $price['query']);
        $computerMemory = in_array($stock || $recommendation ? $requested : $bareRequest, ['computer ram', 'computer memory'], true);
        if ($computerMemory && $requested === '') {
            $requested = $bareRequest;
        }
        if (app(CategorizeChatbotQuery::class)->isBlocked($recommendation ? $requested : $message)) {
            return null;
        }

        $existingBranchSearch = ($previous['intent'] ?? null) === 'branch_availability';
        if (! $existingBranchSearch && ($state['active'] ?? null) === 'product' && ($family !== '' || ! empty($state['references']['product']))
            && preg_match('/^(?:do you have (?:it|that|these|them)|is (?:it|that) available|what about) (?:in|at|sa) /iu', $query)) {
            $intent = $this->localIntent($message, $state);
            if ($intent['branch'] !== '') {
                return [...$intent, 'search_query' => $family, 'reference' => 'previous',
                    'price_scope' => $family === '' ? 'previous' : 'catalog'];
            }
        }

        if (preg_match('/^(?:do you (?:only|just) have|do you have only|is there only) (.+)$/iu', $query, $match)) {
            $brand = $this->mentionedBrand($match[1]);
            $explicit = $brand === '' ? $match[1] : trim(str_ireplace($brand, '', $match[1]));
            $scope = $catalog->resolveStandalone($explicit);
            if ($brand !== '' && ($scope !== null || ($explicit === '' && $family !== ''))) {
                $search = $scope === null ? $family : $explicit;
                $previousScope = $catalog->resolve($family);

                return [...$this->localIntent($message, $state), 'intent' => 'discovery', 'search_query' => $search,
                    'reference' => $scope === null || $scope === $previousScope ? 'previous' : 'new',
                    'brand' => '', 'product_mentions' => [], 'other_than_brand' => $brand,
                    'constraint_updates' => ['brand' => 'clear']];
            }
        }

        if ($family !== '' && preg_match('/^(?:(?:do you have|what about|available) )?(?:any )?(?:(?:other|another|different|ibang|laing) brands?|(?:iban|ubang) (?:nga )?brands?)$/iu', $query)) {
            $brands = Product::query()->customerEligible()->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))
                ->pluck('brand')->filter()->unique(fn (string $brand): string => mb_strtolower($brand))->values();
            $brand = ($previous['brand'] ?? '') ?: ($brands->count() === 1 ? $brands->first() : '');

            return [...$this->localIntent($message, $state), 'intent' => 'discovery', 'search_query' => $family, 'reference' => 'previous',
                'brand' => '', 'other_than_brand' => $brand, 'constraint_updates' => ['brand' => 'clear']];
        }

        $cheaper = preg_match('/^(?:(?:anything|something|any) )?(?:cheaper|more affordable|mas mura|mas barato|mas baratu)(?: than (?:the )?(first|second|third)(?: one| option)?)?$/iu', $query, $comparison);
        if (! $existingBranchSearch && $family !== '' && (($price['query'] === '' && ($price['has_minimum'] || $price['has_maximum'])) || $cheaper)) {
            return [...$this->localIntent($message, $state), 'intent' => 'discovery', 'search_query' => $family, 'reference' => 'previous',
                'position' => ['first' => 1, 'second' => 2, 'third' => 3][$comparison[1] ?? ''] ?? 0];
        }

        if ($stock && ! $computerMemory && ! $price['has_minimum'] && ! $price['has_maximum'] && config('ai_integration.enabled')
            && (config('ai_integration.model_led') || config('ai_integration.full_catalog_pipeline'))) {
            return null;
        }

        if ($requested !== '' && ($scope = $catalog->resolveStandalone($requested)) !== null) {
            return [...$this->localIntent($message, $state), 'intent' => $recommendation ? 'recommendation' : 'discovery', 'search_query' => $requested,
                'category_key' => ChatbotProductRanking::FAMILIES[$scope['category']] ?? 'other', 'reference' => 'new',
                'product_mentions' => []];
        }

        return null;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function localIntent(string $message, array $state = []): array
    {
        $language = app(ChatbotLanguage::class);
        $canonical = $language->canonical($language->withoutLanguageRequest($message));
        $price = app(ChatbotPriceIntent::class)->resolve($canonical);
        $query = $this->stockCatalogQuery($price['query']);
        $branch = '';
        $records = Branch::query()->published()->get(['city', 'name']);
        $matches = $records->filter(fn (Branch $record): bool => $this->mentions($canonical, $record));
        if ($matches->isNotEmpty()) {
            $branch = implode(' or ', $matches->pluck('city')->all());
        } elseif (preg_match('/\b'.self::LOCATION_PREPOSITION.'\s+(?:the\s+)?(?!(?:stock|least|most)\b)([\p{L}][\p{L} .-]*?)(?:\s+branch\b|[?!]|$)/iu', $canonical, $location)) {
            $branch = trim($location[1]);
            $query = str_ireplace($location[0], ' ', $query);
        }
        foreach ($records as $record) {
            foreach ([$record->city, preg_replace('/\s+city$/iu', '', $record->city), $record->name] as $alias) {
                $query = preg_replace('/\b'.preg_quote($alias, '/').'\b/iu', ' ', $query) ?? $query;
            }
        }
        $interpreted = $language->interpret($canonical);
        $query = $this->withoutExclusions($query);
        $brand = $this->mentionedBrand($query);
        if ($brand !== '') {
            $query = preg_replace('/\b'.preg_quote($brand, '/').'\b/iu', ' ', $query) ?? $query;
        }
        $query = preg_replace('/\b(?:what|which|where|branch|branches|that|i|can|buy|but|purchase|at|in|do|you|have|has|sells?|a|an|the|is|available|stock|for|me|instead|or)\b/iu', ' ', $query) ?? $query;
        $query = trim(Str::squish($query), " \t\n\r\0\x0B?.!");
        $catalog = app(CatalogSearchIntent::class)->resolve($query);
        $category = ChatbotProductRanking::FAMILIES[$catalog['category'] ?? ''] ?? 'other';
        if (($catalog['category'] ?? '') === 'Laptops & Desktops' && preg_match('/\b(laptop|desktop)\b/iu', $query, $family)) {
            $category = mb_strtolower($family[1]);
        }
        $followUp = $this->isFollowUp($message, $state);
        $previous = $state['model_constraints'] ?? [];

        return ['outcome' => 'supported', 'intent' => 'branch_availability', 'language' => $language->select($message, $state['language'] ?? null),
            'category_key' => $category, 'search_query' => $followUp ? ($previous['search_query'] ?? $query) : $query,
            'product_mentions' => app(ResolveProductContext::class)->namedProducts($canonical)->pluck('name')->all(),
            'attributes' => ['stock'], 'brand' => $brand, 'branch' => $branch, 'availability' => 'in_stock',
            'capacity' => $interpreted['capacity'] ?? '', 'use_case' => '', 'excluded_brands' => $interpreted['excluded_brands'],
            'minimum_php' => $price['minimum'], 'maximum_php' => $price['maximum'],
            'minimum_inclusive' => $price['minimum_inclusive'], 'maximum_inclusive' => $price['maximum_inclusive'],
            'sort' => match ($price['sort']) {
                'asc' => 'price_asc', 'desc' => 'price_desc', default => 'relevance'
            },
            'price_scope' => 'catalog', 'reference' => $followUp ? 'previous' : 'new', 'position' => 0, 'clarification' => 'none'];
    }

    /** @param array<string, mixed> $state */
    private function isFollowUp(string $message, array $state): bool
    {
        if (($state['active'] ?? null) !== 'product' || ($state['model_constraints']['intent'] ?? null) !== 'branch_availability') {
            return false;
        }
        $canonical = trim(app(ChatbotLanguage::class)->canonical($message), ' .?!');
        if (preg_match('/^(?:(?:what about|and|actually)\s+)?(?:under|below|above|over|between|at least|at most|up to|budget|cheapest|most expensive|not|without|exclude|excluding|except|any brand|all brands|any branch|all branches|no budget|any price|remove (?:the )?(?:brand|budget|price))\b/iu', $canonical)) {
            return ! app(CategorizeChatbotQuery::class)->isBlocked($message)
                && preg_match('/\b(?:order|payment|refund|return|delivery|password|otp|hours|contact|address)\b/iu', $canonical) !== 1
                && ! app(ResolveProductContext::class)->matchesCatalogTerms(app(ChatbotPriceIntent::class)->resolve($canonical)['query']);
        }
        $brand = $this->mentionedBrand($canonical);
        if ($brand !== '' && preg_match('/^'.preg_quote($brand, '/').'(?:\s+instead)?$/iu', $canonical)) {
            return true;
        }
        if ($brand !== '' && preg_match('/^(?:kanang|katong|yung|iyong|ina nga)\s+'.preg_quote($brand, '/').'\s+(?:(?:na|nga)\s+)?(.+)$/iu', $canonical, $reference) === 1) {
            $category = app(CatalogSearchIntent::class)->resolveStandalone($reference[1])['category'] ?? null;
            if ($category !== null && (ChatbotProductRanking::FAMILIES[$category] ?? null) === ($state['model_constraints']['category_key'] ?? null)) {
                return true;
            }
        }

        return Branch::query()->published()->get(['city', 'name'])->contains(function (Branch $branch) use ($canonical): bool {
            foreach ([$branch->city, preg_replace('/\s+city$/iu', '', $branch->city), $branch->name] as $alias) {
                if (preg_match('/^(?:(?:at|in)\s+)?'.preg_quote($alias, '/').'(?:\s+branch)?$/iu', $canonical)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function mentionedBrand(string $message): string
    {
        return Product::query()->customerEligible()->whereNotNull('brand')->distinct()->pluck('brand')
            ->first(fn (string $brand): bool => $brand !== '' && preg_match('/\b'.preg_quote($brand, '/').'\b/iu', $message) === 1) ?? '';
    }

    public function requestedBrand(string $message): string
    {
        return $this->mentionedBrand($this->withoutExclusions($message));
    }

    private function withoutExclusions(string $message): string
    {
        return preg_replace('/\b(?:not|without|exclud(?:e|ing)|except)\s+[\p{L}\p{N}-]+\b/iu', ' ', $message) ?? $message;
    }

    /** @return list<string> */
    public function modelCodes(string $message): array
    {
        preg_match_all('/(?<![\p{L}\p{N}-])(?=[\p{L}\p{N}-]*\d)[a-z\d]+(?:-[a-z\d]+)+(?![\p{L}\p{N}-])/iu', $message, $matches);

        return array_slice(array_values(array_unique(array_filter($matches[0], fn (string $code): bool => preg_match('/[a-z]/i', $code) === 1))), 0, 6);
    }

    /** @return array{branch: ?Branch, outcome: string, clarification?: string, clarification_code?: string} */
    public function locate(string $message, string $branch): array
    {
        $branch = trim($branch);
        $branches = Branch::query()->published()->orderBy('id')->get();
        $matches = $branches->filter(fn (Branch $record): bool => $this->mentions($message, $record));
        if ($matches->isEmpty() && $branch !== '') {
            $matches = $branches->filter(fn (Branch $record): bool => $this->mentions($branch, $record));
        }
        if ($branch !== '' && $matches->isEmpty()) {
            return ['branch' => null, 'outcome' => 'unresolved', 'clarification' => 'Which Battlefront branch do you mean? Please provide its city.', 'clarification_code' => 'unknown_branch'];
        }
        if ($matches->count() > 1) {
            return ['branch' => null, 'outcome' => 'unresolved', 'clarification' => 'Which branch do you mean: '.implode(' or ', $matches->pluck('city')->all()).'?', 'clarification_code' => 'ambiguous_branch'];
        }

        $selected = $matches->first() ?? $branches->first(fn (Branch $record): bool => $record->is_operational);

        return ['branch' => $selected, 'outcome' => match (true) {
            $selected === null => 'unavailable',
            $selected->is_operational => 'sagay',
            default => 'stock_unknown',
        }];
    }

    /** @param CatalogContext $context
     * @return CatalogContext */
    public function reply(BranchStockSearch $request, array $context): array
    {
        if (isset($request->location['clarification'], $request->location['clarification_code'])) {
            return [...$context, 'clarification' => $request->location['clarification'], 'clarification_code' => $request->location['clarification_code']];
        }
        $selected = $request->location['branch'];
        if ($selected === null) {
            return [...$context, 'products' => [], 'branch_availability_message' => 'Published branch information is currently unavailable. Live inventory information covers Sagay only.'];
        }
        if (isset($context['selection_constraints']) && $context['selection_constraints']['branch'] !== '') {
            $context['selection_constraints']['branch'] = $selected->city;
        }
        $context['branches'] = app(ResolveStoreContext::class)->execute($selected->city)['branches'];
        if (! $selected->is_operational) {
            $context['products'] = [];
            $context['branch_availability_message'] = 'Stock information in this chat covers the Sagay branch only. I cannot confirm stock at '.$selected->city.'. Please contact the branch before visiting.';
            if ($selected->contact_number) {
                $context['branch_availability_message'] .= ' Phone: '.$selected->contact_number.'.';
            }
        } else {
            $context['branch_availability_message'] = $context['products'] !== []
                ? 'These matching products are available at the Sagay branch. I cannot confirm stock at other branches.'
                : str_replace('I found no available products', 'I found no available Sagay products', $context['no_match_message'] ?? 'I found no available Sagay products matching all of these constraints. Try changing the brand, model or budget.').' I cannot confirm stock at other branches.';
        }

        return $context;
    }

    private function mentions(string $message, Branch $branch): bool
    {
        $normalize = fn (string $value): string => Str::of($value)->lower()->replaceMatches('/[^\p{L}\p{N}]+/u', ' ')->squish()->toString();
        $message = ' '.$normalize($message).' ';
        foreach ([$branch->city, preg_replace('/\s+city$/iu', '', $branch->city), $branch->name] as $alias) {
            $alias = $normalize($alias);
            if (mb_strlen($alias) >= 4 && str_contains($message, ' '.$alias.' ')) {
                return true;
            }
        }

        return false;
    }
}
