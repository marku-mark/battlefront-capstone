<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotQueryCategory;
use App\Services\Chatbot\ChatbotLanguage;

class CategorizeChatbotQuery
{
    public function __construct(private ChatbotLanguage $language = new ChatbotLanguage) {}

    /** @var list<string> */
    private const OPEN_DOMAIN_PHRASES = [
        'who invented',
        'who wrote',
        'what is the capital',
        'tell me a joke',
        'weather',
        'latest news',
        'sports score',
        'solve my homework',
        'write an essay',
        'translate this',
        'recipe',
    ];

    /** @var list<string> */
    private const RECOMMENDATION_PHRASES = [
        'recommend',
        'recommended',
        'recommendation',
        'recommendations',
        'suggest',
        'suggestion',
        'suggestions',
        'should i buy',
        'help me choose',
        'best laptop',
        'best computer',
        'best pc',
        'best product',
        'best mouse',
        'best keyboard',
        'which laptop is best',
        'which product is best',
        'what should i get',
        'which should i get',
        'what is best for',
        'which is best for',
        'for my budget',
        'within my budget',
        'good for gaming',
        'best for gaming',
    ];

    /** @var list<string> */
    private const ORDER_PHRASES = [
        'my order',
        'order status',
        'order tracking',
        'track order',
        'track my order',
        'tracking number',
        'order number',
        'pickup order',
        'pick up order',
        'delivery order',
        'pending',
        'processing',
        'completed',
        'cancelled',
        'canceled',
        'my oder',
        'oder status',
        'track oder',
        'proccessing',
        'procesing',
        'compeleted',
    ];

    /** @var list<string> */
    private const FAQ_PHRASES = [
        'payment',
        'payment method',
        'payment methods',
        'payment option',
        'payment options',
        'ways to pay',
        'how can i pay',
        'how do i pay',
        'pay cash',
        'cash payment',
        'pay by card',
        'card at store',
        'gcash',
        'maya',
        'pickup',
        'pick up',
        'delivery',
        'pickup or delivery',
        'pick up or delivery',
        'do you deliver',
        'fulfillment',
        'paymnt method',
        'paymnt methods',
        'delivry',
    ];

    /** @var list<string> */
    private const PRODUCT_PHRASES = [
        'monitor',
        'monitors',
        'battery',
        'batteries',
        'price',
        'prices',
        'pricing',
        'cost',
        'stock',
        'stocks',
        'stocked',
        'available',
        'availability',
        'product',
        'products',
        'item',
        'items',
        'laptop',
        'laptops',
        'computer',
        'computers',
        'pc',
        'mouse',
        'keyboard',
        'piso wifi',
        'specification',
        'specifications',
        'specs',
        'prcie',
        'stok',
        'availble',
        'avalable',
        'laptp',
        'keybord',
    ];

    /** @var list<string> */
    private const STORE_OPERATIONAL_PHRASES = [
        'location',
        'locations',
        'located',
        'address',
        'contact',
        'phone',
        'telephone',
        'email',
        'hours',
        'store hours',
        'business hours',
        'opening hours',
        'closing hours',
        'open',
        'close',
        'closing',
        'locaton',
        'adress',
        'contct',
        'store ours',
    ];

    /** @var list<string> */
    private const STORE_REFERENCE_PHRASES = [
        'store',
        'branch',
        'branches',
        'sagay store',
        'brach',
    ];

    /**
     * Classify a chatbot message without retrieving data or calling providers.
     */
    public function execute(string $message): ChatbotQueryCategory
    {
        if ($this->language->blocked($message)) {
            return ChatbotQueryCategory::Unsupported;
        }
        $normalizedMessage = $this->normalize($message);

        if ($this->isBlocked($normalizedMessage)) {
            return ChatbotQueryCategory::Unsupported;
        }

        if (preg_match('/\bbf\s*\d+\b/u', $normalizedMessage) === 1) {
            return ChatbotQueryCategory::Order;
        }

        if ($this->language->isPcBuilderHelp($message)) {
            return ChatbotQueryCategory::Faq;
        }

        if (preg_match('/\Ahow (?:do|can) i cancel (?:my|an) order\z/u', $normalizedMessage) === 1) {
            return ChatbotQueryCategory::Faq;
        }

        if (preg_match('/\b(?:my (?:payment|proof)|did (?:you receive|i pay)|have i paid)\b/u', $normalizedMessage) === 1) {
            return ChatbotQueryCategory::Order;
        }

        if (preg_match('/\bmy (?:refund|return|cancellation|parcel)\b/u', $normalizedMessage) === 1
            || (preg_match('/\b(?:refund|return|cancellation) status\b/u', $normalizedMessage) === 1 && preg_match('/\bhow (?:do|can|to)\b/u', $normalizedMessage) !== 1)) {
            return ChatbotQueryCategory::Order;
        }

        if (preg_match('/\b(?:delivery fee|shipping fee|shipping cost|delivery cost|delivery estimate|delivery time|cancel|cancellation|return|returns|refund|refunds)\b/u', $normalizedMessage) === 1) {
            return ChatbotQueryCategory::Faq;
        }

        if (preg_match('/\b(?:paymongo|qr ph|qrph|bank transfer|online banking|how (?:do|can|should) i pay|how to pay|payment (?:methods?|options?)|ways to pay|what does .+ mean)\b/u', $normalizedMessage) === 1) {
            return ChatbotQueryCategory::Faq;
        }

        /** @var list<array{category: ChatbotQueryCategory, phrases: list<string>}> $rules */
        $rules = [
            ['category' => ChatbotQueryCategory::Order, 'phrases' => self::ORDER_PHRASES],
            ['category' => ChatbotQueryCategory::Faq, 'phrases' => self::FAQ_PHRASES],
            ['category' => ChatbotQueryCategory::Store, 'phrases' => self::STORE_OPERATIONAL_PHRASES],
            ['category' => ChatbotQueryCategory::Product, 'phrases' => self::PRODUCT_PHRASES],
            ['category' => ChatbotQueryCategory::Store, 'phrases' => self::STORE_REFERENCE_PHRASES],
        ];

        foreach ($rules as $rule) {
            if ($this->containsAny($normalizedMessage, $rule['phrases'])) {
                return $rule['category'];
            }
        }

        return ChatbotQueryCategory::Unsupported;
    }

    public function isBlocked(string $message): bool
    {
        if ($this->language->blocked($message)) {
            return true;
        }
        $message = $this->normalize($message);

        return $message === ''
            || $this->language->blocked($message)
            || $this->containsAny($message, self::OPEN_DOMAIN_PHRASES)
            || $this->containsAny($message, self::RECOMMENDATION_PHRASES);
    }

    public function requiresLiveFacts(string $message): bool
    {
        $message = $this->normalize($message);

        return $this->containsAny($message, self::STORE_OPERATIONAL_PHRASES)
            || $this->containsAny($message, ['price', 'prices', 'pricing', 'cost', 'stock', 'stocks', 'available', 'availability', 'specs', 'specifications']);
    }

    public function requiresStoreFacts(string $message): bool
    {
        return $this->containsAny($this->normalize($message), self::STORE_OPERATIONAL_PHRASES);
    }

    private function normalize(string $message): string
    {
        return $this->language->normalized($message);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function containsAny(string $message, array $phrases): bool
    {
        $messageWithBoundaries = " {$message} ";

        foreach ($phrases as $phrase) {
            if (str_contains($messageWithBoundaries, " {$phrase} ")) {
                return true;
            }
        }

        return false;
    }
}
