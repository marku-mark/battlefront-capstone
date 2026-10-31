<?php

namespace App\Actions\Chatbot;

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Enums\ChatbotQueryCategory;
use App\Services\Chatbot\ChatbotLanguage;

class RouteChatbotQuery
{
    public function __construct(
        private CategorizeChatbotQuery $categorizer,
        private MatchChatbotKnowledge $knowledge,
        private ClassifyChatbotInquiry $boundary,
        private ResolveProductContext $products,
        private ChatbotLanguage $language = new ChatbotLanguage,
    ) {}

    /** @return array{category: ChatbotQueryCategory, choices: array<string, string>} */
    public function execute(string $message, bool $resolveCatalogTerms = false): array
    {
        $classification = $this->boundary->execute($message);
        $category = $classification['category'];

        if ($this->categorizer->isBlocked($message) || $this->boundary->containsSensitiveInput($message)) {
            return ['category' => $category, 'choices' => []];
        }

        $choices = [];
        $splitRules = count(array_filter(array_keys($classification['choices']), fn (string $key): bool => str_starts_with($key, 'faq:'))) > 1;
        $clauses = $this->language->clauses($message);

        foreach ($clauses as $clause) {
            $clauseCategory = $this->categoryWithKnowledge($clause, $resolveCatalogTerms);

            if ($clauseCategory !== ChatbotQueryCategory::Unsupported) {
                $ruleTopic = $this->boundary->execute($clause)['rule_topic'];
                $key = $splitRules && $ruleTopic !== null ? 'faq:'.$ruleTopic : $clauseCategory->value;
                $choices[$key] = isset($choices[$key])
                    ? $choices[$key].' and '.trim($clause)
                    : trim($clause);
            }
        }

        if (count($choices) > 1) {
            return ['category' => $category, 'choices' => $choices];
        }

        return ['category' => $this->categoryWithKnowledge($message, $resolveCatalogTerms), 'choices' => []];
    }

    private function categoryWithKnowledge(string $message, bool $resolveCatalogTerms): ChatbotQueryCategory
    {
        $classification = $this->boundary->execute($message);
        $category = $classification['category'];

        if ($resolveCatalogTerms && $category === ChatbotQueryCategory::Unsupported
            && ! $this->language->isAcknowledgement($message) && $this->products->matchesCatalogTerms($message)) {
            return ChatbotQueryCategory::Product;
        }

        if ($classification['eligible_for_faq'] && $this->knowledge->execute($message) !== []) {
            $category = ChatbotQueryCategory::Faq;
        }

        return $category;
    }

    /** @param array<string, string> $choices */
    public function clarification(array $choices): string
    {
        return 'Which would you like to ask about first: '.implode(' or ', array_map(
            fn (string $category): string => match ($category) {
                'product' => 'product information',
                'order' => 'your order',
                'store' => 'store information',
                'faq:payment_methods', 'faq:payment_proof' => 'payment guidance',
                'faq:fulfillment', 'faq:delivery_quote' => 'delivery guidance',
                'faq:cancellation' => 'cancellation guidance',
                'faq:returns' => 'return guidance',
                'faq:refunds' => 'refund guidance',
                default => 'payment or delivery guidance',
            }, array_keys($choices),
        )).'? Please repeat that part of your question or name its topic.';
    }
}
