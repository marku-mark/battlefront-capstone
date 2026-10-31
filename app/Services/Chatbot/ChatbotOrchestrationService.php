<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\CategorizeChatbotQuery;
use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Branch;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;

class ChatbotOrchestrationService
{
    private const EMPTY_MESSAGE = 'Please enter a question about Battlefront products, orders, stores, payment methods, pickup, or delivery.';

    private const UNSUPPORTED_INQUIRY = 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.';

    private const NO_PRODUCT_MATCH = "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.";

    private const UNAUTHENTICATED_ORDER = 'Please sign in with a customer account to check order status.';

    private const ORDER_NOT_FOUND = "I couldn't find a matching order in your account.";

    private const STORE_INFORMATION_UNAVAILABLE = 'Store information is currently unavailable. Please try again later.';

    private const FAQ_INFORMATION_UNAVAILABLE = 'Approved information for that question is currently unavailable.';

    private const PROVIDER_TIMEOUT = 'The chatbot took too long to respond. Please try again.';

    private const PROVIDER_UNAVAILABLE = 'The chatbot is temporarily unavailable. Please try again later.';

    private const SENSITIVE_INPUT = 'Please remove sensitive information from your question and try again.';

    public function __construct(
        private RouteChatbotQuery $routeChatbotQuery,
        private ResolveProductContext $resolveProductContext,
        private ResolveOrderContext $resolveOrderContext,
        private ResolveStoreContext $resolveStoreContext,
        private ResolveFaqContext $resolveFaqContext,
        private ChatbotAiAdapter $chatbotAiAdapter,
        private RenderChatbotFallback $renderChatbotFallback,
        private ClassifyChatbotInquiry $boundary,
        private ResolveChatbotRuleGuidance $ruleGuidance,
        private ChatbotLanguage $language = new ChatbotLanguage,
    ) {}

    /**
     * Coordinate deterministic routing, authoritative context, and response wording.
     *
     * @return array{category: ChatbotQueryCategory, message: string, source: 'gemini'|'fallback'}
     */
    public function respond(string $message, ?User $customer = null, ?Closure $remember = null, ?int $productId = null, ?string $language = null): array
    {
        $normalizedMessage = Str::of($message)->trim()->squish()->toString();
        $routing = $this->routeChatbotQuery->execute($normalizedMessage, resolveCatalogTerms: true);
        $category = $routing['category'];
        $classification = $this->boundary->execute($normalizedMessage);
        $language = in_array($language, ChatbotLanguage::LANGUAGES, true) ? $language : $this->language->select($message);
        $intentMessage = $this->language->canonical($normalizedMessage);
        $classifiedByAi = false;

        if ($normalizedMessage === '') {
            return $this->fallback($category, self::EMPTY_MESSAGE);
        }

        if ($category === ChatbotQueryCategory::Order && $customer === null && $routing['choices'] === []) {
            return $this->fallback($category, self::UNAUTHENTICATED_ORDER);
        }

        if ($this->containsSensitiveInput($normalizedMessage)) {
            return $this->fallback($category, self::SENSITIVE_INPUT);
        }

        if ($routing['choices'] !== []) {
            return $this->fallback($category, $this->routeChatbotQuery->clarification($routing['choices']));
        }

        if ($category === ChatbotQueryCategory::Unsupported) {
            if ((new CategorizeChatbotQuery)->isBlocked($message) || ! $this->language->classifierCandidate($message)) {
                return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
            }
            $inferred = $this->chatbotAiAdapter->classify($message);
            $classifiedByAi = true;
            if ($inferred === null || $inferred['outcome'] === 'uncertain') {
                return $this->fallback($category, 'Could you clarify whether you need product information, an order update, branch details, or payment, delivery, cancellation, return or refund guidance?');
            }
            if ($inferred['outcome'] === 'unsupported') {
                return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
            }
            $intent = ChatbotIntentAgent::INTENTS[$inferred['intent']];
            $category = ChatbotQueryCategory::from($intent['category']);
            $classification['rule_topic'] = $intent['rule'];
            $intentMessage = $intent['query'].' '.$intentMessage;
            if ($this->language->explicit($message) === null && $this->language->detect($message) === null && $inferred['language'] !== 'und') {
                $language = $inferred['language'];
            }
        }

        if ($category === ChatbotQueryCategory::Order && $customer === null) {
            return $this->fallback($category, self::UNAUTHENTICATED_ORDER);
        }

        preg_match_all('/\bbf[\s\W_]*0*(\d+)\b/iu', $normalizedMessage, $references);
        if ($category === ChatbotQueryCategory::Order && count(array_unique($references[1])) > 1) {
            return $this->fallback($category, 'Which order do you mean? Please provide one BF order reference.');
        }

        if ($category === ChatbotQueryCategory::Order && preg_match('/\bbf[\s\W_]*\d+\b/iu', $normalizedMessage) !== 1) {
            return $this->fallback($category, 'Which order do you mean? Please provide its BF order reference from your order history.');
        }

        $scopeMessage = $this->language->normalized($intentMessage);
        if ($category === ChatbotQueryCategory::Product
            && $this->mentionsOtherBranch($scopeMessage)
            && preg_match('/\b(?:stock|stocks|available|availability|availble|avalable|stok)\b/u', $scopeMessage) === 1) {
            return $this->fallback($category, 'Live inventory information covers the Sagay branch only. I cannot confirm stock at the other branches. Please ask about Sagay availability or contact the branch.');
        }

        if ($category === ChatbotQueryCategory::Product && preg_match('/\b(?:what products (?:do you have|are available)|what do you (?:sell|have)|browse (?:the )?catalog)\b/iu', $intentMessage) === 1) {
            return $this->fallback($category, 'Browse the Products catalog to see available products and filter by category or brand. Please provide a product name or model if you would like its current price or Sagay availability.');
        }

        $context = $productId !== null && $category === ChatbotQueryCategory::Product
            ? $this->resolveProductContext->forProduct($productId)
            : ($classification['rule_topic'] !== null && $category === ChatbotQueryCategory::Faq
                ? $this->ruleGuidance->execute($classification['rule_topic'])
                : $this->resolveContext($category, $category === ChatbotQueryCategory::Order ? $intentMessage : $normalizedMessage, $customer));

        if ($category === ChatbotQueryCategory::Product && ($context['resolution'] ?? null) === 'needs_details') {
            $clarification = str_contains(Str::lower($normalizedMessage), 'facebook')
                ? 'Please provide the product name, model, or identifying text from the Facebook post so I can check its current price and Sagay availability.'
                : 'Please provide a product name or model so I can check its current price and Sagay availability.';

            return $this->fallback($category, $clarification);
        }

        if ($category === ChatbotQueryCategory::Faq && count($context['knowledge'] ?? []) > 1) {
            return $this->fallback($category, $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE);
        }

        if ($this->hasNoContext($category, $context)) {
            return $this->fallback(
                $category,
                $this->missingContextMessage($category, $customer),
            );
        }

        unset($context['resolution']);
        $remember?->__invoke($category, $context);

        if (count($context['products'] ?? []) > 1 || count($context['branches'] ?? []) > 1
            || ($context['orders'][0]['support']['needs_clarification'] ?? false)) {
            return $this->fallback($category, $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE);
        }

        if ($category === ChatbotQueryCategory::Faq || $classifiedByAi) {
            return $this->fallback(
                $category,
                $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE,
            );
        }

        $result = $language === 'en'
            ? $this->chatbotAiAdapter->generate($normalizedMessage, $context)
            : $this->chatbotAiAdapter->generate($normalizedMessage, $context, $language);

        if ($result['successful']) {
            return [
                'category' => $category,
                'message' => $result['text'],
                'source' => 'gemini',
            ];
        }

        return $this->fallback(
            $category,
            $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? ($result['failure'] === 'timeout'
                ? self::PROVIDER_TIMEOUT
                : self::PROVIDER_UNAVAILABLE),
        );
    }

    private function mentionsOtherBranch(string $message): bool
    {
        $cities = Branch::query()->pluck('city')->push('Bacolod City', 'San Carlos City', 'Escalante City', 'Guihulngan City');

        return $cities->contains(function (string $city) use ($message): bool {
            $city = Str::lower(Str::before($city, ' City'));

            return $city !== Str::lower(Str::before((string) config('battlefront.operational_branch_city', 'Sagay City'), ' City'))
                && str_contains(' '.$message.' ', ' '.$city.' ');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(
        ChatbotQueryCategory $category,
        string $message,
        ?User $customer,
    ): array {
        return match ($category) {
            ChatbotQueryCategory::Product => $this->resolveProductContext->execute($message),
            ChatbotQueryCategory::Order => $this->resolveOrderContext->execute($customer, $message),
            ChatbotQueryCategory::Store => $this->resolveStoreContext->execute($message),
            ChatbotQueryCategory::Faq => $this->resolveFaqContext->execute($message),
            ChatbotQueryCategory::Unsupported => [],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function hasNoContext(ChatbotQueryCategory $category, array $context): bool
    {
        return match ($category) {
            ChatbotQueryCategory::Product => $context['products'] === [],
            ChatbotQueryCategory::Order => $context['orders'] === [],
            ChatbotQueryCategory::Store => $context['branches'] === [],
            ChatbotQueryCategory::Faq => ($context['knowledge'] ?? []) === [] && trim($context['guidance'] ?? '') === '',
            ChatbotQueryCategory::Unsupported => true,
        };
    }

    private function missingContextMessage(
        ChatbotQueryCategory $category,
        ?User $customer,
    ): string {
        return match ($category) {
            ChatbotQueryCategory::Product => self::NO_PRODUCT_MATCH,
            ChatbotQueryCategory::Order => $customer === null
                ? self::UNAUTHENTICATED_ORDER
                : self::ORDER_NOT_FOUND,
            ChatbotQueryCategory::Store => self::STORE_INFORMATION_UNAVAILABLE,
            ChatbotQueryCategory::Faq => self::FAQ_INFORMATION_UNAVAILABLE,
            ChatbotQueryCategory::Unsupported => self::UNSUPPORTED_INQUIRY,
        };
    }

    public function containsSensitiveInput(string $message): bool
    {
        return $this->boundary->containsSensitiveInput($message);
    }

    /**
     * @return array{category: ChatbotQueryCategory, message: string, source: 'fallback'}
     */
    private function fallback(ChatbotQueryCategory $category, string $message): array
    {
        return [
            'category' => $category,
            'message' => $message,
            'source' => 'fallback',
        ];
    }
}
