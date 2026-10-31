<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotQueryCategory;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

class ChatbotConversation
{
    public function __construct(
        private ChatbotOrchestrationService $chatbot,
        private ChatbotConversationToken $tokens,
        private RouteChatbotQuery $router,
        private CategorizeChatbotQuery $categorizer,
        private ResolveProductContext $products,
        private RenderChatbotFallback $fallbackRenderer,
        private ChatbotLanguage $language = new ChatbotLanguage,
    ) {}

    /** @return array{message: string, source: string, context_token: string|null} */
    public function respond(string $message, ?User $customer, ?string $token, string $contextScope): array
    {
        $subject = $contextScope.':'.($customer?->getKey() ?? 'guest');
        $state = $this->tokens->decode($token, $subject);
        $message = Str::squish($message);

        if (! $this->chatbot->containsSensitiveInput($message)) {
            $state['language'] = $this->language->select($message, $state['language'] ?? null);
            if ($this->language->explicit($message) !== null && trim($this->language->withoutLanguageRequest($message), ' .?!') === '') {
                return $this->fallback('I will use your selected language when I can express the current facts reliably. Approved guidance and fallback messages remain in English.', $state, $subject);
            }
        }

        if ($this->chatbot->containsSensitiveInput($message) || $this->categorizer->isBlocked($message)) {
            $result = $this->chatbot->respond($message, $customer);

            return ['message' => $result['message'], 'source' => $result['source'], 'context_token' => null];
        }

        $acknowledged = $this->language->isAcknowledgement($message);
        if ($acknowledged && ($state['pending'] ?? []) !== []) {
            return $this->fallback($this->router->clarification($state['pending']), $state, $subject);
        }

        $selected = $this->selectedTopic($message);
        if ($selected !== null && str_starts_with($selected, 'faq:') && ! isset($state['pending'][$selected]) && isset($state['pending']['faq'])) {
            $selected = 'faq';
        }
        if ($selected !== null && isset($state['pending'][$selected])) {
            $message = $state['pending'][$selected];
        }
        unset($state['pending']);

        $routing = $this->router->execute($message, resolveCatalogTerms: true);
        if ($routing['choices'] !== []) {
            $state['pending'] = $routing['choices'];

            return $this->fallback($this->router->clarification($routing['choices']), $state, $subject);
        }

        $productId = null;
        $category = $routing['category'];
        if (isset($state['product_clarification']) && $category === ChatbotQueryCategory::Product
            && $this->products->matchesCatalogTerms($message)
            && $this->products->clarificationChoices($state['references']['product'] ?? [], $message) === []) {
            unset($state['product_clarification']);
        }
        if (($state['active'] ?? null) === 'product' && isset($state['product_clarification'])
            && in_array($category, [ChatbotQueryCategory::Product, ChatbotQueryCategory::Unsupported], true)
            && ! $this->categorizer->requiresLiveFacts($message)) {
            $references = $state['references']['product'] ?? [];
            $choices = $this->products->clarificationChoices($references, $acknowledged ? null : $message);
            if (! $acknowledged && count($choices) === 1) {
                $productId = $choices[0]['id'];
                $message = $state['product_clarification'].' of '.$choices[0]['name'];
                $category = ChatbotQueryCategory::Product;
            } elseif ($acknowledged || $choices !== [] || $category === ChatbotQueryCategory::Unsupported) {
                $choices = $choices !== [] ? $choices : $this->products->clarificationChoices($references);
                $state['references']['product'] = array_column($choices, 'id');
                if ($choices === []) {
                    unset($state['product_clarification']);

                    return $this->fallback('Those products are no longer available in the current catalog. Please enter another product name or model.', $state, $subject);
                }

                return $this->fallback($this->fallbackRenderer->productClarification(array_column($choices, 'name')), $state, $subject);
            }
        }

        if ($acknowledged) {
            return $this->fallback('Please tell me which product, branch, order or support question you would like help with.', $state, $subject);
        }

        $matchingMessage = $this->language->canonical($message);
        $isFollowUp = preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b|^(?:how much|and the)\b|^(?:price|available)\s*[?!.]*$/iu', $matchingMessage) === 1;

        if ($isFollowUp && $this->hasExplicitEntity($message, $category)) {
            $isFollowUp = false;
        }

        if (($state['active'] ?? null) === 'store'
            && in_array($category, [ChatbotQueryCategory::Unsupported, ChatbotQueryCategory::Store], true)
            && ! $this->categorizer->requiresLiveFacts($message)
            && preg_match('/^(?:what|how) about\b/iu', $matchingMessage) === 1) {
            $namedCity = Branch::query()->published()->pluck('city')->first(
                fn (string $city): bool => str_contains(Str::lower($message), Str::lower(Str::before($city, ' City'))),
            );
            if ($namedCity !== null) {
                $message = ($state['store_intent'] ?? 'location').' of '.$namedCity.' branch';
                $category = ChatbotQueryCategory::Store;
                $isFollowUp = false;
            }
        }

        if ($isFollowUp) {
            $target = $category === ChatbotQueryCategory::Unsupported
                ? ($state['active'] ?? null)
                : $category->value;
            if (preg_match('/\b(?:earlier|previous) (?:product|order|branch|store)\b/iu', $matchingMessage) === 1) {
                $target = preg_match('/\border\b/iu', $matchingMessage) === 1 ? 'order'
                    : (preg_match('/\bproduct\b/iu', $matchingMessage) === 1 ? 'product' : 'store');
            } elseif ($target !== ($state['active'] ?? null)) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            $references = $state['references'][$target ?? ''] ?? [];
            if (count($references) !== 1) {
                return $this->fallback('Which product, branch, or order do you mean? Please include its name or order reference.', $state, $subject);
            }

            if ($target === 'product') {
                $productId = $references[0];
                $message = 'Product inquiry: '.$message;
            } elseif ($target === 'store') {
                $message = rtrim($message, '.?!').' at '.$references[0].' branch';
            } elseif ($target === 'order') {
                $message = rtrim($message, '.?!').' order '.$references[0];
            }
        } elseif ($category === ChatbotQueryCategory::Order
            && preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) !== 1
            && ($state['active'] ?? null) === 'order'
            && count($state['references']['order'] ?? []) === 1) {
            $message = rtrim($message, '.?!').' '.$state['references']['order'][0];
        }

        $resolved = false;
        $result = $this->chatbot->respond($message, $customer, function (ChatbotQueryCategory $category, array $context) use (&$state, &$resolved, $message): void {
            $resolved = true;
            $state['active'] = $category->value;
            if ($category === ChatbotQueryCategory::Product) {
                $state['references']['product'] = Product::query()->customerEligible()
                    ->whereIn('name', array_column($context['products'], 'name'))
                    ->limit(6)->get(['id', 'name'])
                    ->sortBy(fn (Product $product): int => (int) array_search($product->name, array_column($context['products'], 'name'), true))
                    ->pluck('id')->all();
                unset($state['product_clarification']);
                if (count($context['products']) > 1) {
                    $text = $this->language->canonical($message);
                    $price = preg_match('/\b(?:price|prices|pricing|cost|how much)\b/iu', $text) === 1;
                    $stock = preg_match('/\b(?:stock|available|availability)\b/iu', $text) === 1;
                    $state['product_clarification'] = match (true) {
                        $price && $stock => 'price and stock availability',
                        $price => 'price',
                        $stock => 'stock availability',
                        preg_match('/\b(?:specs|specifications|details|description)\b/iu', $text) === 1 => 'product details',
                        default => 'product information',
                    };
                }
            } elseif ($category === ChatbotQueryCategory::Store) {
                $state['references']['store'] = array_slice(array_column($context['branches'], 'city'), 0, 5);
                $state['store_intent'] = match (true) {
                    preg_match('/\b(?:hours|open|close|time)\b/iu', $this->language->canonical($message)) === 1 => 'opening hours',
                    preg_match('/\b(?:contact|phone|email|telephone)\b/iu', $this->language->canonical($message)) === 1 => 'contact information',
                    default => 'location',
                };
            } elseif ($category === ChatbotQueryCategory::Order) {
                $state['references']['order'] = array_column($context['orders'], 'reference');
            }
        }, $productId, $state['language']);

        if (! $resolved) {
            $state['active'] = $result['category']->value;
            unset($state['references'][$state['active']]);
        }

        if ($result['source'] === 'gemini' && $state['active'] === 'product'
            && preg_match('/\b(?:stock|available|availability)\b/iu', $this->language->canonical($message)) === 1) {
            $result['message'] .= ' Live inventory information covers the Sagay branch only.';
        }

        return [
            'message' => $result['message'],
            'source' => $result['source'],
            'context_token' => $this->tokens->encode($state, $subject),
        ];
    }

    private function selectedTopic(string $message): ?string
    {
        return match (Str::of($this->language->canonical($message))->lower()->trim()->rtrim('.?!')->toString()) {
            'product', 'products', 'price', 'pricing', 'product pricing', 'product information' => 'product',
            'order', 'my order', 'order status' => 'order',
            'store', 'branch', 'store information' => 'store',
            'payment', 'payment guidance', 'payment methods' => 'faq:payment_methods',
            'proof', 'payment proof', 'payment verification' => 'faq:payment_proof',
            'delivery fee', 'delivery guidance', 'delivery cost' => 'faq:delivery_quote',
            'return', 'returns', 'return guidance' => 'faq:returns',
            'refund', 'refunds', 'refund guidance' => 'faq:refunds',
            'cancel', 'cancellation', 'cancellation guidance' => 'faq:cancellation',
            'faq', 'delivery', 'payment or delivery guidance', 'delivery information' => 'faq',
            default => null,
        };
    }

    private function hasExplicitEntity(string $message, ChatbotQueryCategory $category): bool
    {
        if ($category === ChatbotQueryCategory::Order) {
            return preg_match('/\bbf[\s\W_]*\d+\b/iu', $message) === 1;
        }

        $normalized = Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        if ($category === ChatbotQueryCategory::Store) {
            return Branch::query()->pluck('city')->contains(function (string $city) use ($normalized): bool {
                $city = Str::lower(Str::before($city, ' City'));

                return str_contains(" {$normalized} ", " {$city} ");
            });
        }

        if ($category === ChatbotQueryCategory::Product) {
            return $this->products->namesProduct($message) || $this->products->hasSearchTerms($message);
        }

        return $category === ChatbotQueryCategory::Faq
            && preg_match('/\b(?:it|its|that|this|there|earlier|previous)\b/iu', $this->language->canonical($message)) !== 1;
    }

    /** @param array<string, mixed> $state
     * @return array{message: string, source: string, context_token: string}
     */
    private function fallback(string $message, array $state, string $subject): array
    {
        return ['message' => $message, 'source' => 'fallback', 'context_token' => $this->tokens->encode($state, $subject)];
    }
}
