<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Models\Branch;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use Illuminate\Support\Str;

class ChatbotBranchAvailability
{
    /** @param array<string, mixed> $state */
    public function supports(string $message, array $state = []): bool
    {
        $canonical = app(ChatbotLanguage::class)->canonical($message);
        if ($this->isFollowUp($message, $state)) {
            return true;
        }
        $branchWording = preg_match('/\b(?:branch|branches|where.*(?:buy|purchase)|(?:buy|purchase).*where)\b/iu', $canonical) === 1;
        $namedLocation = ! $branchWording && preg_match('/\b(?:in|at)\s+(?!(?:stock|least|most)\b)[\p{L}]/iu', $canonical) === 1
            && Branch::query()->published()->get(['name', 'city'])->contains(fn (Branch $branch): bool => $this->mentions($message, $branch));

        return ($branchWording || $namedLocation)
            && ! app(CategorizeChatbotQuery::class)->isBlocked($message)
            && preg_match('/\b(?:order|payment|refund|return|delivery|password|otp|api key)\b/iu', $canonical) !== 1
            && (app(ResolveProductContext::class)->matchesCatalogTerms($message) || app(ResolveProductContext::class)->namesProduct($message))
            && preg_match('/\b(?:hours|opening|closing|contact|phone|address)\b/iu', $canonical) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function localContext(string $message, array $state = []): array
    {
        return app(ChatbotCatalogSelection::class)->select(app(ChatbotLanguage::class)->canonical($message), $this->localIntent($message, $state), $state);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function localIntent(string $message, array $state = []): array
    {
        $language = app(ChatbotLanguage::class);
        $canonical = $language->canonical($language->withoutLanguageRequest($message));
        $price = app(ChatbotPriceIntent::class)->resolve($canonical);
        $query = $price['query'];
        $branch = '';
        $records = Branch::query()->published()->get(['city', 'name']);
        $matches = $records->filter(fn (Branch $record): bool => $this->mentions($canonical, $record));
        if ($matches->isNotEmpty()) {
            $branch = implode(' or ', $matches->pluck('city')->all());
        } elseif (preg_match('/\b(?:at|in)\s+(?:the\s+)?(?!(?:stock|least|most)\b)([\p{L}][\p{L} .-]*?)(?:\s+branch\b|[?!]|$)/iu', $canonical, $location)) {
            $branch = trim($location[1]);
            $query = str_ireplace($location[0], ' ', $query);
        }
        foreach ($records as $record) {
            foreach ([$record->city, preg_replace('/\s+city$/iu', '', $record->city), $record->name] as $alias) {
                $query = preg_replace('/\b'.preg_quote($alias, '/').'\b/iu', ' ', $query) ?? $query;
            }
        }
        $interpreted = $language->interpret($canonical);
        $query = preg_replace('/\b(?:not|without|exclud(?:e|ing)|except)\s+[\p{L}\p{N}-]+\b/iu', ' ', $query) ?? $query;
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

    /** @param array<string, mixed> $context
     * @param array<string, mixed> $intent
     * @return array<string, mixed> */
    public function resolve(string $message, array $intent, array $context): array
    {
        if (isset($context['clarification'])) {
            return $context;
        }
        $context['products'] = array_values(array_filter($context['products'] ?? [], fn (array $product): bool => in_array($product['inventory']['status'] ?? null, ['in_stock', 'low_stock'], true)));
        $branch = trim($intent['branch'] ?? '');
        $branches = Branch::query()->published()->orderBy('id')->get();
        $matches = $branches->filter(fn (Branch $record): bool => $this->mentions($branch !== '' ? $branch : $message, $record));
        if ($branch !== '' && $matches->isEmpty()) {
            return [...$context, 'products' => [], 'clarification' => 'Which Battlefront branch do you mean? Please provide its city.', 'clarification_code' => 'unknown_branch'];
        }
        if ($matches->count() > 1) {
            return [...$context, 'products' => [], 'clarification' => 'Which branch do you mean: '.implode(' or ', $matches->pluck('city')->all()).'?', 'clarification_code' => 'ambiguous_branch'];
        }
        $selected = $matches->first();
        if ($selected !== null && isset($context['selection_constraints'])) {
            $context['selection_constraints']['branch'] = $selected->city;
        }
        if ($selected === null && $branch === '') {
            $selected = $branches->first(fn (Branch $record): bool => $record->is_operational);
        }
        if ($selected === null) {
            return [...$context, 'products' => [], 'branch_availability_message' => 'Published branch information is currently unavailable. Live inventory information covers Sagay only.'];
        }
        $context['branches'] = app(ResolveStoreContext::class)->execute($selected->city)['branches'];
        if (! $selected->is_operational) {
            $context['products'] = [];
            $context['branch_availability_message'] = 'I cannot confirm stock at '.$selected->city.'. Live inventory information covers Sagay only. Please contact the branch before visiting.';
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
