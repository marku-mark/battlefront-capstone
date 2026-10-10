<?php

namespace App\Actions\Chatbot\Context;

use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Support\Str;

class ResolveProductContext
{
    /** @var list<string> */
    private const QUERY_WORDS = [
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
     * @return array{products: list<array{
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
            return ['products' => []];
        }

        $normalizedMessage = $this->normalizedText($message);
        $namedProducts = $this->products->contextMatches($terms)
            ->filter(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
        $products = $namedProducts->isNotEmpty()
            ? $namedProducts
            : $this->products->contextMatches($terms, availableOnly: true);

        return [
            'products' => array_values($products
                ->map(fn (Product $product): array => $this->mapProduct($product))
                ->all()),
        ];
    }

    /** @return array{products: list<array<string, mixed>>} */
    public function forProduct(int $productId): array
    {
        $product = Product::query()->customerEligible()
            ->with(['category', 'inventory', 'tags'])->find($productId);

        return ['products' => $product === null ? [] : [$this->mapProduct($product)]];
    }

    public function namesProduct(string $message): bool
    {
        $normalizedMessage = $this->normalizedText($message);

        return $this->products->contextMatches($this->meaningfulTerms($message))
            ->contains(fn (Product $product): bool => $this->messageNamesProduct($normalizedMessage, $product));
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
    private function meaningfulTerms(string $message): array
    {
        $normalizedMessage = Str::of($message)
            ->trim()
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
            ->squish()
            ->toString();

        $normalizedMessage = preg_replace('/\b(?:at|in) (?:the )?(?:sagay|san carlos|escalante|guihulngan)(?: city)?(?: branch| store)?\b/u', '', $normalizedMessage);

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
