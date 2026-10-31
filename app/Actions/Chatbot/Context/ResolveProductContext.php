<?php

namespace App\Actions\Chatbot\Context;

use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Chatbot\ChatbotLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ResolveProductContext
{
    /** @var list<string> */
    private const QUERY_WORDS = [
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
    public function execute(string $message): array
    {
        $terms = $this->meaningfulTerms($message);

        if ($terms === []) {
            return ['resolution' => 'needs_details', 'products' => []];
        }

        $normalizedMessage = $this->normalizedText($message);
        $namedProducts = $this->namedProducts($message);
        if ($namedProducts->isEmpty()) {
            $literalTerms = $this->meaningfulTerms($message, canonical: false);
            $candidates = $this->products->contextMatches($literalTerms);
            if ($terms !== $literalTerms) {
                $candidates = $candidates->merge($this->products->contextMatches($terms));
            }
            $namedProducts = $candidates
                ->filter(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
        }
        $products = $namedProducts->isNotEmpty()
            ? $namedProducts
            : $this->products->contextMatches($terms, availableOnly: true, includeDescription: false);

        if ($products->isEmpty() && count($terms) > 1) {
            $products = $this->products->contextMatches($terms, availableOnly: true);
        }

        return [
            'resolution' => $products->isEmpty() ? 'not_found' : 'matched',
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

        return ['resolution' => $product === null ? 'not_found' : 'matched', 'products' => $product === null ? [] : [$this->mapProduct($product)]];
    }

    public function namesProduct(string $message): bool
    {
        $normalizedMessage = $this->normalizedText($message);

        return $this->namedProducts($message)->isNotEmpty() || $this->products->contextMatches($this->meaningfulTerms($message, canonical: false))
            ->contains(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
    }

    public function matchesCatalogTerms(string $message): bool
    {
        $candidates = $this->products->contextMatches($this->meaningfulTerms($message), limit: 6, includeDescription: false);

        $productIds = array_values($candidates->map(fn (Product $product): int => $product->id)->all());

        return $this->clarificationChoices($productIds, $message) !== [];
    }

    /** @return Collection<int, Product> */
    private function namedProducts(string $message): Collection
    {
        $normalized = $this->normalizedText($message);

        return Product::query()->customerEligible()->with(['category', 'inventory', 'tags'])
            ->whereRaw('INSTR(LOWER(?), LOWER(name)) > 0', [$message])
            ->orderBy('name')->orderBy('id')->limit(6)->get()
            ->filter(fn (Product $product): bool => $this->messageNamesProduct($normalized, $product));
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

        return $context;
    }
}
