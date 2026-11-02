<?php

namespace App\Actions\Chatbot\Context;

use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotProviderBudget;
use App\Services\Chatbot\SemanticCatalogCandidates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ResolveProductContext
{
    /** @var list<string> */
    private const QUERY_WORDS = [
        'and', 'also', 'plus', 'still', 'about', 'much', 'thanks', 'thank',
        'rin', 'din',
        'ang', 'sang', 'sa', 'ng', 'mga', 'ba', 'bala', 'gid', 'bi', 'palihog', 'nga', 'pud', 'sad', 'naman', 'lang', 'na', 'man', 'pa', 'po', 'kamo', 'kayo', 'ninyo', 'niyo', 'inyong', 'inyo', 'ko', 'nako', 'it', 'there', 'inquiry', 'pila', 'magkano', 'tagpila', 'may', 'ara', 'naa', 'moy', 'meron', 'mayroon', 'kayong',
        'a',
        'an',
        'any',
        'anything',
        'are',
        'at',
        'available',
        'availability',
        'can',
        'cost',
        'currently',
        'do',
        'does',
        'for',
        'find',
        'has',
        'have',
        'how',
        'i',
        'in',
        'is',
        'item',
        'items',
        'looking',
        'me',
        'much',
        'my',
        'named',
        'need',
        'of',
        'on',
        'please',
        'price',
        'prices',
        'pricing',
        'product',
        'products',
        'sell',
        'selling',
        'show',
        'something',
        'stock',
        'that',
        'the',
        'this',
        'what',
        'which',
        'with',
        'you',
        'your',
    ];

    public function __construct(private ProductCatalogRepository $products) {}

    /**
     * Resolve authoritative catalog and Sagay inventory facts for a product inquiry.
     *
     * @return array{resolution: 'matched'|'needs_details'|'not_found', products: list<array{
     *     name: string,
     *     description: string|null,
     *     brand: string|null,
     *     category: string,
     *     tags: list<string>,
     *     price: string,
     *     discount_price: string|null,
     *     is_demo: bool,
     *     demo_notice?: string,
     *     inventory: array{quantity: int|null, status: 'unavailable'|'out_of_stock'|'low_stock'|'in_stock'}
     * }>}
     */
    public function execute(string $message, ?string $semanticMessage = null): array
    {
        if (config('ai_integration.enabled') && preg_match('/^\s*(?:what products (?:do you have|are available)|what do you (?:sell|have)|browse (?:the )?catalog)\s*[?.!]*$/iu', $message) === 1) {
            $products = Product::query()->customerAvailable()->with(['category', 'inventory', 'tags'])
                ->orderBy('price')->orderBy('id')->limit(6)->get();
            $products->loadMissing('componentProfile.currentApprovedRevision');

            return ['resolution' => $products->isEmpty() ? 'not_found' : 'matched', 'products' => array_values($products->map(fn (Product $product): array => $this->mapProduct($product))->all())];
        }
        $catalogQuery = $this->catalogSearchQuery($message);
        $useCatalogIntent = $catalogQuery !== null && ! $this->namesProduct($message);
        $catalogFallback = null;
        $requiresReasoning = $this->requiresReasoning($semanticMessage ?? $message);
        $reasoningProducts = $requiresReasoning && $useCatalogIntent
            ? $this->products->chatbotSearch($catalogQuery, useProvider: false) : null;
        if (! $requiresReasoning && ($this->isShortSearch($message) || $useCatalogIntent)) {
            $search = $useCatalogIntent ? $catalogQuery : $message;
            $useProvider = config('scout.driver') === 'algolia';
            $matches = $this->products->chatbotSearch($search, useProvider: $useProvider);
            if ($useProvider && $matches->isEmpty()) {
                $matches = $this->products->chatbotSearch($search, useProvider: false);
            }
            if ($matches->isNotEmpty()) {
                if (config('ai_integration.enabled')) {
                    $matches->loadMissing('componentProfile.currentApprovedRevision');
                }

                return ['resolution' => 'matched', 'search_query' => $search, 'products' => array_values($matches->map(fn (Product $product): array => ['id' => $product->id, ...$this->mapProduct($product)])->all())];
            }
            if (! $this->namedProducts($message)->contains(fn (Product $product): bool => ($product->inventory->quantity ?? 0) === 0)) {
                $catalogFallback = $useCatalogIntent ? $search : null;
            }
        }
        $terms = $this->meaningfulTerms($message);

        if ($terms === []) {
            return ['resolution' => 'needs_details', 'products' => []];
        }

        $normalizedMessage = $this->normalizedText($message);
        $namedProducts = $catalogFallback === null ? $this->namedProducts($message) : (new Product)->newCollection();
        if ($catalogFallback === null && $namedProducts->isEmpty()) {
            $literalTerms = $this->meaningfulTerms($message, canonical: false);
            $candidates = $this->products->contextMatches($literalTerms);
            if ($terms !== $literalTerms) {
                $candidates = $candidates->merge($this->products->contextMatches($terms));
            }
            $namedProducts = $candidates
                ->filter(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
        }
        $products = $catalogFallback !== null ? (new Product)->newCollection() : ($namedProducts->isNotEmpty()
            ? $namedProducts
            : $this->products->contextMatches($terms, availableOnly: true, includeDescription: false));

        if ($catalogFallback === null && $products->isEmpty() && count($terms) > 1) {
            $products = $this->products->contextMatches($terms, availableOnly: true);
        }
        if ($reasoningProducts !== null && $namedProducts->isEmpty()) {
            $products = $reasoningProducts;
        }

        $semanticDiscovery = $namedProducts->isEmpty()
            && preg_match('/\b(?:for|gaming|office|editing|streaming|affordable|budget|cheap|barato|work|recommend|suggest)\b/iu', $semanticMessage ?? $message) === 1;
        if (($products->isEmpty() || $semanticDiscovery) && config('ai_integration.enabled') && config('ai_integration.qdrant.enabled')) {
            $ids = app(SemanticCatalogCandidates::class)->ids($semanticMessage ?? $message);
            $semanticProducts = $this->products->chatbotSemanticMatches($ids, $catalogFallback ?? ($reasoningProducts !== null ? $catalogQuery : null));
            $scores = [];
            foreach ([$products->values(), $semanticProducts->values()] as $ranking) {
                foreach ($ranking as $rank => $candidate) {
                    $scores[$candidate->id] = ($scores[$candidate->id] ?? 0.0) + 1 / (60 + $rank + 1);
                }
            }
            $products = $products->merge($semanticProducts)->unique('id')
                ->sort(function (Product $first, Product $second) use ($scores): int {
                    return ($scores[$second->id] <=> $scores[$first->id]) ?: ($first->id <=> $second->id);
                })->take(6);
        }

        if (config('ai_integration.enabled')) {
            $products->loadMissing('componentProfile.currentApprovedRevision');
        }

        return [
            'resolution' => $products->isEmpty() ? 'not_found' : 'matched',
            ...($catalogFallback !== null && $products->isNotEmpty() ? ['search_query' => $catalogFallback] : []),
            'products' => array_values($products
                ->map(fn (Product $product): array => $this->mapProduct($product))
                ->all()),
        ];
    }

    /** @return array{resolution: 'matched'|'not_found', products: list<array<string, mixed>>} */
    public function forProduct(int $productId): array
    {
        $product = Product::query()->customerEligible()
            ->with(['category', 'inventory', 'tags'])->find($productId);
        if (config('ai_integration.enabled')) {
            $product?->loadMissing('componentProfile.currentApprovedRevision');
        }

        return ['resolution' => $product === null ? 'not_found' : 'matched', 'products' => $product === null ? [] : [$this->mapProduct($product)]];
    }

    public function isShortSearch(string $message): bool
    {
        $words = preg_split('/\s+/u', trim($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ! $this->requiresReasoning($message) && config('scout.driver') === 'algolia' && count($words) >= 1 && count($words) <= 5 && $this->meaningfulTerms($message) !== []
            && array_intersect(array_map(fn (string $word): string => mb_strtolower(trim($word, '?.!,')), $words), self::QUERY_WORDS) === []
            && ! preg_match('/\b(?:price|cost|stock|available|specs|details|where|how|order|refund|payment|delivery)\b/iu', $message);
    }

    public function isCatalogSearch(string $message): bool
    {
        return ! $this->requiresReasoning($message) && $this->catalogSearchQuery($message) !== null && ! $this->namesProduct($message);
    }

    private function requiresReasoning(string $message): bool
    {
        return preg_match('/\b(?:compare|comparison|difference|versus|vs|compatible|compatibility|recommend|suggest|budget|affordable|for (?:gaming|office|school|editing|streaming|work))\b/iu', (new ChatbotLanguage)->canonical($message)) === 1;
    }

    public function namesProduct(string $message): bool
    {
        $normalizedMessage = $this->normalizedText($message);

        return $this->namedProducts($message)->isNotEmpty() || $this->products->contextMatches($this->meaningfulTerms($message, canonical: false))
            ->contains(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
    }

    public function matchesCatalogTerms(string $message): bool
    {
        $catalogQuery = $this->catalogSearchQuery($message);
        if ($catalogQuery !== null) {
            return true;
        }
        if ($this->isShortSearch($message) && $this->products->chatbotSearch($message)->isNotEmpty()) {
            return true;
        }
        $candidates = $this->products->contextMatches($this->meaningfulTerms($message), limit: 6, includeDescription: false);

        $productIds = array_values($candidates->map(fn (Product $product): int => $product->id)->all());

        return $this->clarificationChoices($productIds, $message) !== [];
    }

    private function catalogSearchQuery(string $message): ?string
    {
        $language = new ChatbotLanguage;
        $ignored = array_values(array_diff([...self::QUERY_WORDS, 'specs', 'specifications', 'details', 'description'], ['for', 'with', 'a', 'an']));
        $pattern = '/\b(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), $ignored)).')\b/iu';
        $clean = fn (string $query): string => Str::squish(trim(preg_replace($pattern, ' ', $query) ?? $query, " \t\n\r\0\x0B?.!"));
        $query = $clean($language->canonical($message));
        $literal = $clean($language->withoutLanguageRequest($message));
        $resolver = app(CatalogSearchIntent::class);
        $intent = $resolver->resolve($query);
        $literalIntent = $resolver->resolve($literal);
        if ($literalIntent !== null && ($intent === null || $literalIntent['category'] !== $intent['category'])) {
            return $this->products->hasChatbotCategory($literal) ? $literal : null;
        }

        return $intent !== null && $this->products->hasChatbotCategory($query) ? $query : null;
    }

    /** @return Collection<int, Product> */
    private function namedProducts(string $message): Collection
    {
        $normalized = $this->normalizedText($message);

        return app(ChatbotProviderBudget::class)->memoize('named-products:'.hash('sha256', $message), fn (): Collection => Product::query()->customerEligible()->with(['category', 'inventory', 'tags'])
            ->whereRaw('INSTR(LOWER(?), LOWER(name)) > 0', [$message])
            ->orderBy('name')->orderBy('id')->limit(6)->get()
            ->filter(fn (Product $product): bool => $this->messageNamesProduct($normalized, $product)));
    }

    public function hasSearchTerms(string $message): bool
    {
        return array_diff($this->meaningfulTerms($message), ['still', 'its', 'earlier', 'previous', 'and', 'the', 'specifications', 'specs', 'details']) !== [];
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array{id: int, name: string}>
     */
    public function clarificationChoices(array $productIds, ?string $message = null): array
    {
        $terms = $message === null ? [] : $this->meaningfulTerms($message, canonical: false);
        if ($message !== null && $terms === []) {
            return [];
        }

        return array_values(Product::query()->customerEligible()->with(['category:id,name', 'tags:id,name'])
            ->whereIn('id', array_slice($productIds, 0, 6))->limit(6)->get()
            ->sortBy(fn (Product $product): int => (int) array_search($product->id, $productIds, true))
            ->filter(function (Product $product) use ($terms): bool {
                $text = ' '.$this->normalizedText(implode(' ', [$product->name, $product->brand, $product->category->name, ...$product->tags->pluck('name')->all()])).' ';
                foreach ($terms as $term) {
                    if (! str_contains($text, ' '.$term.' ')) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])->all());
    }

    private function messageNamesProduct(string $normalizedMessage, Product $product): bool
    {
        $name = $this->normalizedText(Str::of($product->name)->replaceMatches('/^\[DEMO\]\s*/iu', '')->toString());

        return $name !== '' && str_contains(" {$normalizedMessage} ", " {$name} ");
    }

    private function normalizedText(string $text): string
    {
        return Str::of($text)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
    }

    /**
     * @return list<string>
     */
    private function meaningfulTerms(string $message, bool $canonical = true): array
    {
        $language = new ChatbotLanguage;
        $normalizedMessage = Str::of($canonical ? $language->canonical($message) : $language->withoutClarificationPrefix($language->withoutLanguageRequest($message)))
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        $normalizedMessage = preg_replace('/\b(?:at|in|sa) (?:the )?(?:sagay|san carlos|escalante|guihulngan|bacolod)(?: city)?(?: branch| store)?\b/u', '', $normalizedMessage);
        $normalizedMessage = preg_replace('/\b(?:on|from|in) (?:the )?(?:battlefront s? )?facebook(?: page| post)?\b/u', '', $normalizedMessage);
        $normalizedMessage = preg_replace('/\b(?:saw|seen|facebook|page|post|battlefront|from)\b/u', '', $normalizedMessage);

        if ($normalizedMessage === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalizedMessage),
            fn (string $term): bool => Str::length($term) >= 2
                && ! in_array($term, self::QUERY_WORDS, strict: true),
        )));
    }

    /**
     * @return array{
     *     name: string,
     *     description: string|null,
     *     brand: string|null,
     *     category: string,
     *     tags: list<string>,
     *     price: string,
     *     discount_price: string|null,
     *     is_demo: bool,
     *     demo_notice?: string,
     *     inventory: array{quantity: int|null, status: 'unavailable'|'out_of_stock'|'low_stock'|'in_stock'}
     * }
     */
    private function mapProduct(Product $product): array
    {
        $isDemo = Str::startsWith($product->name, '[DEMO]')
            || Str::startsWith((string) $product->image_path, 'images/demo-products/');
        $quantity = $isDemo ? null : $product->inventory?->quantity;

        $context = [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand,
            'category' => $product->category->name,
            'tags' => array_values($product->tags
                ->map(fn (Tag $tag): string => $tag->name)
                ->sort()
                ->all()),
            'price' => $product->price,
            'discount_price' => $product->discount_price,
            'is_demo' => $isDemo,
            'inventory' => [
                'quantity' => $quantity,
                'status' => match (true) {
                    $quantity === null => 'unavailable',
                    $quantity === 0 => 'out_of_stock',
                    $quantity <= $product->inventory->reorder_level => 'low_stock',
                    default => 'in_stock',
                },
            ],
        ];

        if ($isDemo) {
            $context['demo_notice'] = 'Demo item only; listed prices are samples and Battlefront stock is unconfirmed.';
        }

        if (config('ai_integration.enabled') || config('ai_integration.memory.enabled') || config('ai_integration.advice_enabled')) {
            $context['id'] = $product->id;
        }
        if (config('ai_integration.enabled')) {
            $profile = $product->componentProfile;
            $revision = $profile?->review_status === 'approved'
                ? $profile->currentApprovedRevision
                : null;
            $context['specification_review_status'] = $revision === null ? 'unreviewed' : 'approved';
            $context['approved_specifications'] = $revision === null ? [] : array_filter($revision->specifications,
                fn ($value): bool => is_scalar($value) || $value === null);
        }

        return $context;
    }
}
