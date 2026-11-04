<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\PcBuilder\CompatibilityEngine;

class ChatbotCatalogAdvice
{
    public function __construct(
        private ResolveProductContext $products,
        private CompatibilityEngine $compatibility,
        private ChatbotLanguage $language,
    ) {}

    public function supports(string $message): bool
    {
        return (bool) config('ai_integration.advice_enabled')
            && ! $this->language->isPcBuilderHelp($message)
            && preg_match('/\b(?:compatible|compatibility|work with|fit with|recommend|suggest|budget|affordable|barato|similar to|alternatives? to|help me choose|should i buy|which should i get|best for)\b/iu', $this->language->canonical($message)) === 1;
    }

    /** @param array<string, list<int>> $references
     * @return array{message: string, context: array<string, mixed>}
     */
    public function answer(string $message, array $references = []): array
    {
        $query = $this->language->canonical($message);
        if (preg_match('/\b(?:compatible|compatibility|work with|fit with)\b/iu', $query) === 1) {
            return $this->compatibilityAnswer($message, $references);
        }
        $price = app(ChatbotPriceIntent::class)->resolve($query);
        $catalogIntent = app(CatalogSearchIntent::class)->resolve($price['query']);
        if (($catalogIntent !== null && app(ProductCatalogRepository::class)->hasChatbotCategory($price['query'])) || $price['sort'] !== null) {
            preg_match_all('/\b(?:not|without|exclude|except)\s+([\p{L}\p{N}-]+)\b/iu', $query, $excluded);
            $context = app(ChatbotCatalogSelection::class)->select($message, [
                'intent' => 'recommendation', 'category_key' => ChatbotProductRanking::FAMILIES[$catalogIntent['category'] ?? ''] ?? 'other',
                'search_query' => $price['query'], 'product_mentions' => [], 'reference' => 'new', 'position' => 0,
                'maximum_php' => null, 'excluded_brands' => $excluded[1], 'brand' => '', 'capacity' => '', 'use_case' => '',
            ]);
            $text = app(RenderChatbotFallback::class)->execute(ChatbotQueryCategory::Product, $message, $context);

            return ['message' => $text ?? 'I could not find an available catalog option matching those constraints.', 'context' => $context];
        }
        $maximum = null;
        preg_match_all('/\b(?:not|without|exclude|except)\s+(intel|amd|nvidia)\b/iu', $query, $exclusions);
        $excludedBrands = array_map('strtolower', $exclusions[1]);
        $query = preg_replace('/\b(?:not|without|exclude|except)\s+(?:intel|amd|nvidia)\b/iu', ' ', $query) ?? $query;
        if (preg_match('/\b(?:under|below|less than|budget(?: of)?)\s*(?:PHP\s*|\x{20B1}\s*)?([\d,]+(?:\.\d{1,2})?)(?![\p{L}\p{N}])/iu', $query, $budget) === 1) {
            $maximum = (float) str_replace(',', '', $budget[1]);
            $query = str_replace($budget[0], ' ', $query);
        }
        $query = preg_replace('/\b(?:help me choose|should i buy|which should i get|best for|recommend|suggest|budget|affordable|cheap|barato|best|gaming)\b/iu', ' ', $query) ?? $query;
        $context = preg_match('/\b(?:similar to|alternatives? to)\s+(.+?)\s*[?.!]*$/iu', trim($query), $similar) === 1
            ? $this->alternatives(trim($similar[1], " \t\n\r\0\x0B.?!"))
            : $this->products->execute(trim($query), semanticMessage: $message);
        if (config('ai_integration.enabled') && config('ai_integration.neo4j.enabled') && ! $this->products->namesProduct($message)) {
            $related = [];
            foreach (array_slice($context['products'], 0, 2) as $seed) {
                if (isset($seed['id'])) {
                    $related = [...$related, ...app(GraphCatalogCandidates::class)->ids($seed['id'])];
                }
            }
            foreach (array_slice(array_values(array_unique($related)), 0, 6) as $id) {
                $context['products'] = [...$context['products'], ...$this->products->forProduct($id)['products']];
            }
            $context['products'] = collect($context['products'])->unique('id')->values()->all();
        }
        $products = collect($context['products'])->filter(fn (array $product): bool => in_array($product['inventory']['status'], ['in_stock', 'low_stock'], true) && ! $product['is_demo']);
        $products = $products->reject(function (array $product) use ($excludedBrands): bool {
            foreach ($excludedBrands as $brand) {
                if (preg_match('/\b'.preg_quote($brand, '/').'\b/iu', ($product['brand'] ?? '').' '.$product['name']) === 1) {
                    return true;
                }
            }

            return false;
        });
        if ($maximum !== null) {
            $products = $products->filter(fn (array $product): bool => (float) ($product['discount_price'] ?? $product['price']) <= $maximum);
        }
        $products = array_slice(app(ChatbotProductRanking::class)->order(array_values($products->values()->all()), $price['sort'], app(ChatbotProductRanking::class)->accessoryRequest($message)), 0, 6);
        $context = ['products' => $products, 'conversation_focus' => 'recommendation'];
        if ($products === []) {
            return ['message' => $maximum !== null && trim($query) !== ''
                ? 'I could not find an available catalog option matching those constraints. Try a higher budget or a different brand; I will keep your current component category.'
                : 'I could not find an available catalog option matching those constraints. Please provide a component type and budget in PHP.', 'context' => $context];
        }
        $facts = app(ChatbotGroundedResponse::class)->evidence($context);

        return ['message' => "These available catalog options match your search. Performance and compatibility require verified specifications.\n\n".implode("\n\n", $facts), 'context' => $context];
    }

    /** @return array{products: list<array<string, mixed>>} */
    private function alternatives(string $name): array
    {
        $seeds = Product::query()->customerEligible()->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->with(['category', 'componentProfile'])->limit(2)->get();
        if ($seeds->count() !== 1 || $seeds->first()->componentProfile === null) {
            return ['products' => []];
        }
        $seed = $seeds->first();
        $ids = app(GraphCatalogCandidates::class)->ids($seed->id);
        if ($ids === []) {
            $ids = Product::query()->customerAvailable()->where('id', '!=', $seed->id)
                ->where('category_id', $seed->category_id)
                ->whereHas('componentProfile', fn ($query) => $query->where('component_type', $seed->componentProfile->component_type))
                ->orderBy('price')->orderBy('id')->limit(6)->pluck('id')->all();
        }
        $facts = [];
        foreach ($ids as $id) {
            $facts = [...$facts, ...$this->products->forProduct($id)['products']];
        }

        return ['products' => $facts];
    }

    /** @param array<string, list<int>> $references
     * @return array{message: string, context: array<string, mixed>}
     */
    private function compatibilityAnswer(string $message, array $references): array
    {
        $products = Product::query()->customerEligible()
            ->whereRaw('INSTR(LOWER(?), LOWER(name)) > 0', [$message])
            ->with(['componentProfile.currentApprovedRevision', 'inventory', 'category', 'tags'])->orderBy('id')->limit(9)->get();
        if (preg_match('/\b(?:earlier|previous|mentioned|that|it|those|before|kanina|ganiha|kagina)\b/iu', $message) === 1) {
            $explicitTypes = $products->map(fn ($product) => $product->componentProfile?->component_type)->filter()->all();
            $ids = [];
            foreach ($references as $type => $productIds) {
                if (! in_array($type, $explicitTypes, true)) {
                    $ids = [...$ids, ...array_slice($productIds, 0, 2)];
                }
            }
            $products = $products->merge(Product::query()->customerEligible()->whereIn('id', array_slice($ids, 0, 8))
                ->with(['componentProfile.currentApprovedRevision', 'inventory', 'category', 'tags'])->limit(8)->get());
        }
        if ($products->count() < 2 || $products->count() > 8) {
            return ['message' => 'Please provide the exact catalog names of the parts to compare. I need at least two identified parts and cannot infer a model from a product family.', 'context' => []];
        }
        if ($products->filter(fn (Product $product): bool => $product->componentProfile !== null)
            ->groupBy(fn (Product $product): string => $product->componentProfile->component_type)
            ->contains(fn ($group): bool => $group->count() > 1)) {
            return ['message' => 'More than one part matches the same component type. Please identify one exact catalog model for each part before I check compatibility.', 'context' => []];
        }
        $components = [];
        $facts = [];
        foreach ($products as $product) {
            $profile = $product->componentProfile;
            if ($profile === null) {
                return ['message' => 'A selected part has no verified component profile. Compatibility is unknown until its exact specifications are reviewed.', 'context' => []];
            }
            $revision = $profile->currentApprovedRevision;
            $approved = $profile->review_status === 'approved' && $revision?->review_status === 'approved';
            $components[] = [
                'key' => (string) $product->id, 'product_id' => $product->id,
                'component_type' => $profile->component_type, 'quantity' => 1,
                'name' => $product->name, 'specifications_reviewed' => $approved,
                'specifications' => $approved ? $revision->specifications : [],
                'evidence' => $approved ? $revision->evidence : [],
            ];
            $facts = [...$facts, ...$this->products->forProduct($product->id)['products']];
        }
        $result = $this->compatibility->evaluate($components);
        $lines = ['Compatibility review: '.implode(' + ', $products->pluck('name')->all()).'.'];
        foreach ($result['findings'] as $finding) {
            $lines[] = ucfirst($finding['status']).': '.$finding['message'];
        }
        $lines[] = 'This covers only the identified parts and available reviewed evidence; missing components or configuration details prevent a complete-build guarantee.';

        return ['message' => implode("\n", $lines), 'context' => ['products' => $facts, 'compatibility' => $result]];
    }
}
