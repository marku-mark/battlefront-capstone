<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use App\Models\User;

class ModelLedChatbot
{
    private const CATEGORIES = ['computer' => 'Laptops & Desktops', 'processor' => 'Processor', 'motherboard' => 'Motherboard', 'memory' => 'RAM', 'graphics' => 'Graphics Card', 'storage' => 'Storage', 'power_supply' => 'Power Supply', 'case' => 'PC Case', 'cooling' => 'Cooling Components', 'monitor' => 'Monitor'];

    public function __construct(private ChatbotAiAdapter $ai, private ResolveProductContext $products, private ChatbotLanguage $language) {}

    public function eligible(string $message): bool
    {
        $boundary = app(ClassifyChatbotInquiry::class);

        return config('ai_integration.enabled') && config('ai_integration.model_led')
            && ! $boundary->containsSensitiveInput($message)
            && $boundary->execute($message)['category'] !== ChatbotQueryCategory::Order
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function respond(string $message, array $state, ?User $customer): array
    {
        $budget = app(ChatbotProviderBudget::class);
        $budget->reset();
        $started = hrtime(true);
        $language = $this->language->explicit($message) ?? $state['language_preference'] ?? $this->language->select($message, $state['language'] ?? null);
        $context = [];
        $category = ChatbotQueryCategory::Product;
        $source = 'fallback';
        $text = 'Please provide a product name or model, or clarify your Battlefront question.';
        try {
            $previous = Product::query()->customerEligible()->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->get(['id', 'name'])->keyBy('id');
            $publicProducts = [];
            foreach (array_slice($state['references']['product'] ?? [], 0, 6) as $id) {
                if ($previous->has($id)) {
                    $publicProducts[] = ['name' => $previous[$id]->name];
                }
            }
            $public = ['language' => $language, 'language_preference' => $state['language_preference'] ?? null, 'products' => $publicProducts, 'constraints' => $state['model_constraints'] ?? []];
            $intent = $this->ai->understand($message, $public);
            if ($intent !== null) {
                $language = $this->language->explicit($message) ?? $state['language_preference'] ?? ($intent['language'] === 'und' ? $language : $intent['language']);
                if ($intent['outcome'] === 'unsupported') {
                    $text = 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.';
                } elseif ($intent['outcome'] === 'clarify' || $intent['clarification'] !== 'none') {
                    $text = $intent['clarification'] === 'cpu_meaning'
                        ? 'By CPU, do you mean a processor or a complete desktop system unit?'
                        : 'Please provide a product name or model, or clarify your Battlefront question.';
                } elseif ($intent['intent'] === 'private') {
                    $category = ChatbotQueryCategory::Order;
                    $text = $customer === null ? 'Please sign in with your customer account to check your order status.' : 'Which order do you mean? Please provide its BF order reference from your order history.';
                } elseif ($intent['intent'] === 'greeting') {
                    $text = 'Please enter a question about Battlefront products, orders, stores, payment methods, pickup, or delivery.';
                } else {
                    if ($intent['intent'] === 'store') {
                        $category = ChatbotQueryCategory::Store;
                        $context = app(ResolveStoreContext::class)->execute($intent['search_query']);
                    } elseif ($intent['intent'] === 'support') {
                        $category = ChatbotQueryCategory::Faq;
                        $rule = app(ClassifyChatbotInquiry::class)->execute($intent['search_query'])['rule_topic'];
                        $context = $rule !== null ? app(ResolveChatbotRuleGuidance::class)->execute($rule) : app(ResolveFaqContext::class)->execute($intent['search_query'], $language);
                    } else {
                        $context = $this->catalog($message, $intent, $state);
                    }
                    $fallback = $context === [] ? null : app(RenderChatbotFallback::class)->execute($category, $intent['search_query'], $context);
                    $text = $fallback ?? match ($category) {
                        ChatbotQueryCategory::Store => 'Store information is currently unavailable. Please try again later.',
                        ChatbotQueryCategory::Faq => 'Approved information for that question is currently unavailable.',
                        default => "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.",
                    };
                    if ($context !== [] && (($context['products'] ?? []) !== [] || ($context['branches'] ?? []) !== [] || ($context['knowledge'] ?? []) !== [] || isset($context['guidance']) || isset($context['compatibility']))) {
                        $context['interpreted_intent'] = ['focus' => $intent['intent'], 'requested_attributes' => $intent['attributes'], 'maximum_php' => $intent['maximum_php'], 'excluded_terms' => $intent['excluded_brands']];
                        $draft = $this->ai->grounded($message, $context, $language, scopeChecked: true);
                        if ($draft['successful']) {
                            $text = $draft['text'];
                            $source = 'gemini';
                        }
                    }
                }
                if ($intent['reference'] === 'new') {
                    unset($state['references']['product'], $state['model_constraints']);
                }
                if ($intent['outcome'] === 'supported') {
                    $state['model_constraints'] = array_intersect_key($intent, array_flip(['category_key', 'maximum_php', 'excluded_brands', 'brand', 'capacity', 'use_case']));
                }
            }
            $state['language'] = $language;
            if ($this->language->explicit($message) !== null) {
                $state['language_preference'] = $language;
            }
            $state['active'] = $category->value;
            if (($context['products'] ?? []) !== []) {
                $state['references']['product'] = array_slice(array_column($context['products'], 'id'), 0, 6);
            }
            if ($source === 'fallback') {
                $text = app(ChatbotLocalizedResponse::class)->text($text, $language, array_column($context['products'] ?? [], 'name'));
            }
            $result = ['category' => $category, 'message' => $text, 'source' => $source];

            return [...$result, 'state' => $state, 'presentation' => app(ChatbotPresentation::class)->build($result, $context, $message, $customer, $language)];
        } finally {
            $budget->recordTurn((int) ((hrtime(true) - $started) / 1_000_000), $source);
            $budget->releaseTurn();
        }
    }

    /** @param array<string, mixed> $intent
     * @param array<string, mixed> $state
     * @return array<string, mixed> */
    private function catalog(string $message, array $intent, array $state): array
    {
        $mentions = $intent['product_mentions'];
        $allowed = $message.' '.implode(' ', Product::query()->customerEligible()->whereIn('id', array_slice($state['references']['product'] ?? [], 0, 6))->pluck('name')->all());
        $mentions = array_values(array_filter($mentions, fn (string $name): bool => mb_stripos($allowed, $name) !== false));
        if ($intent['intent'] === 'facts' && $intent['reference'] === 'new' && $mentions === [] && $intent['category_key'] === 'other') {
            return ['products' => []];
        }
        if ($intent['reference'] === 'previous') {
            $ids = array_slice($state['references']['product'] ?? [], 0, 6);
            if ($intent['position'] > 0) {
                $ids = isset($ids[$intent['position'] - 1]) ? [$ids[$intent['position'] - 1]] : [];
            }
            if ($ids === []) {
                return [];
            }
            $context = ['products' => []];
            foreach ($ids as $id) {
                $context['products'] = [...$context['products'], ...$this->products->forProduct($id)['products']];
            }
        } elseif ($mentions !== []) {
            $ids = Product::query()->customerEligible()->where(function ($query) use ($mentions): void {
                $query->whereIn('name', $mentions)->orWhereIn('product_code', $mentions);
            })->limit(6)->pluck('id');
            $context = ['products' => []];
            foreach ($ids as $id) {
                $context['products'] = [...$context['products'], ...$this->products->forProduct($id)['products']];
            }
        } else {
            $query = self::CATEGORIES[$intent['category_key']] ?? ($intent['search_query'] !== '' ? $intent['search_query'] : $message);
            $query = trim($query.' '.$intent['brand'].' '.$intent['capacity']);
            if (isset(self::CATEGORIES[$intent['category_key']]) && ($intent['maximum_php'] !== null || $intent['excluded_brands'] !== [])) {
                $candidates = Product::query()->customerAvailable()
                    ->whereHas('category', fn ($category) => $category->where('name', self::CATEGORIES[$intent['category_key']]))
                    ->when($intent['maximum_php'] !== null, fn ($products) => $products->whereRaw('COALESCE(discount_price, price) <= ?', [$intent['maximum_php']]));
                foreach ($intent['excluded_brands'] as $brand) {
                    if ($brand !== '') {
                        $candidates->where(function ($products) use ($brand): void {
                            $products->whereRaw('LOWER(COALESCE(brand, \'\')) NOT LIKE ?', ['%'.mb_strtolower($brand).'%'])
                                ->whereRaw('LOWER(name) NOT LIKE ?', ['%'.mb_strtolower($brand).'%']);
                        });
                    }
                }
                $context = ['products' => []];
                foreach ($candidates->orderBy('price')->orderBy('id')->limit(6)->pluck('id') as $id) {
                    $context['products'] = [...$context['products'], ...$this->products->forProduct($id)['products']];
                }
            } else {
                $context = $this->products->execute($query, semanticMessage: $intent['search_query'].' '.$intent['use_case']);
            }
        }
        if ($intent['intent'] === 'compatibility') {
            $names = array_column($context['products'], 'name');

            return app(ChatbotCatalogAdvice::class)->answer('compatible '.implode(' and ', $names))['context'];
        }
        $products = array_filter($context['products'], function (array $product) use ($intent): bool {
            if (isset(self::CATEGORIES[$intent['category_key']]) && strcasecmp($product['category'], self::CATEGORIES[$intent['category_key']]) !== 0) {
                return false;
            }
            if ($intent['maximum_php'] !== null && (float) ($product['discount_price'] ?? $product['price']) > (float) $intent['maximum_php']) {
                return false;
            }
            foreach ($intent['excluded_brands'] as $brand) {
                if ($brand !== '' && mb_stripos(($product['brand'] ?? '').' '.$product['name'], $brand) !== false) {
                    return false;
                }
            }
            if (in_array($intent['intent'], ['discovery', 'recommendation'], true)) {
                return ! $product['is_demo'] && in_array($product['inventory']['status'], ['in_stock', 'low_stock'], true);
            }

            return true;
        });

        return ['products' => array_slice(array_values($products), 0, 6)];
    }
}
