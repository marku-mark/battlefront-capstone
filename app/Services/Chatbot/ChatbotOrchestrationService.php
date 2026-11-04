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
use Illuminate\Support\Facades\Log;
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
     * @param  array<string, list<int>>  $componentReferences
     * @return array{category: ChatbotQueryCategory, message: string, source: 'gemini'|'fallback', presentation: array<string, mixed>}
     */
    public function respond(string $message, ?User $customer = null, ?Closure $remember = null, ?int $productId = null, ?string $language = null, array $componentReferences = []): array
    {
        $resolvedContext = [];
        $started = hrtime(true);
        $capture = function (ChatbotQueryCategory $category, array $context) use (&$resolvedContext, $remember): void {
            $resolvedContext = $context;
            $remember?->__invoke($category, $context);
        };
        try {
            $result = $this->respondInLanguage($message, $customer, $capture, $productId, $language, $componentReferences);
        } finally {
            app(ChatbotProviderBudget::class)->recordTurn((int) ((hrtime(true) - $started) / 1_000_000), $result['source'] ?? 'failed');
            Log::info('Chatbot turn completed.', [
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'source' => $result['source'] ?? 'failed',
                'usage' => app(ChatbotProviderBudget::class)->usage(),
            ]);
            if (config('ai_integration.enabled')) {
                app(ChatbotProviderBudget::class)->releaseTurn();
            }
        }
        if ($result['source'] === 'fallback') {
            $result['message'] = app(ChatbotLocalizedResponse::class)->text($result['message'], $language ?? $this->language->select($message), $result['protected'] ?? []);
        }
        unset($result['protected']);
        $result['presentation'] = app(ChatbotPresentation::class)->build($result, $resolvedContext !== [] ? $resolvedContext : ($result['response_context'] ?? []), $message, $customer, $language ?? $this->language->select($message));
        unset($result['response_context']);

        return $result;
    }

    /** @param array<string, list<int>> $componentReferences
     * @return array{category: ChatbotQueryCategory, message: string, source: 'gemini'|'fallback', protected?: list<mixed>, presentation?: array<string, mixed>, response_context?: array<string, mixed>} */
    private function respondInLanguage(string $message, ?User $customer, ?Closure $remember, ?int $productId, ?string $language, array $componentReferences): array
    {
        if (config('ai_integration.enabled')) {
            $this->chatbotAiAdapter->beginTurn();
        }
        $normalizedMessage = Str::of($message)->trim()->squish()->toString();
        $routing = $this->routeChatbotQuery->execute($normalizedMessage, resolveCatalogTerms: true);
        $category = $routing['category'];
        $classification = $this->boundary->execute($normalizedMessage);
        $language = in_array($language, ChatbotLanguage::LANGUAGES, true) ? $language : $this->language->select($message);
        $intentMessage = $this->language->canonical($normalizedMessage);
        $classifiedByAi = false;
        $trace = app(ChatbotFailureTrace::class);
        $trace->scope = $category === ChatbotQueryCategory::Order ? 'private' : ($category === ChatbotQueryCategory::Unsupported ? 'unknown' : 'public');

        if ($normalizedMessage === '') {
            return $this->fallback($category, self::EMPTY_MESSAGE);
        }

        if ($category === ChatbotQueryCategory::Order && $customer === null && $routing['choices'] === []) {
            return $this->fallback($category, self::UNAUTHENTICATED_ORDER);
        }

        if ($this->containsSensitiveInput($normalizedMessage)) {
            return $this->fallback($category, self::SENSITIVE_INPUT);
        }

        $advice = app(ChatbotCatalogAdvice::class);
        if ($advice->supports($normalizedMessage)
            && ! preg_match('/\b(?:ignore|instructions|system prompt|password|api key|execute|sql|cypher)\b/iu', $normalizedMessage)) {
            $trace->publicScopeConfirmed = true;
            if (! config('ai_integration.adaptive') && config('ai_integration.enabled') && (int) config('ai_integration.gemini.max_calls', 1) >= 3) {
                $scope = $this->chatbotAiAdapter->classify($normalizedMessage);
                if ($scope === null || $scope['outcome'] !== 'supported' || ! in_array($scope['intent'], ['catalog_recommendation', 'component_compatibility'], true)) {
                    $trace->record('understanding_failed', 'scope');

                    return $this->fallback(ChatbotQueryCategory::Product, 'Which component are you choosing, what will you use it for, and what is your budget in PHP?');
                }
            }
            $answer = $advice->answer($normalizedMessage, $componentReferences);
            if ($answer['context'] !== []) {
                $remember?->__invoke(ChatbotQueryCategory::Product, $answer['context']);
            }

            if (config('ai_integration.enabled')
                && ($answer['context']['products'] ?? []) !== []) {
                $draft = $this->chatbotAiAdapter->grounded($normalizedMessage, $answer['context'], $language, scopeChecked: isset($scope) || (bool) config('ai_integration.adaptive'));
                if ($draft['successful']) {
                    return ['category' => ChatbotQueryCategory::Product, 'message' => $draft['text'], 'source' => 'gemini'];
                }
            }

            return $this->fallback(ChatbotQueryCategory::Product, $answer['message'], $answer['context']);
        }

        if ($routing['choices'] !== []) {
            $trace->record('clarification', 'scope');

            return $this->fallback($category, $this->routeChatbotQuery->clarification($routing['choices']));
        }

        if ($category === ChatbotQueryCategory::Unsupported) {
            if ((new CategorizeChatbotQuery)->isBlocked($message) || ! $this->language->classifierCandidate($message)) {
                return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
            }
            $inferred = $this->chatbotAiAdapter->classify($message);
            $classifiedByAi = true;
            if ($inferred === null || $inferred['outcome'] === 'uncertain') {
                $trace->record($inferred === null ? 'understanding_failed' : 'clarification', 'scope');

                return $this->fallback($category, 'Could you clarify whether you need product information, an order update, branch details, or payment, delivery, cancellation, return or refund guidance?');
            }
            if ($inferred['outcome'] === 'unsupported') {
                $trace->scope = 'unsupported';

                return $this->fallback($category, self::UNSUPPORTED_INQUIRY);
            }
            $intent = ChatbotIntentAgent::INTENTS[$inferred['intent']];
            if (config('ai_integration.advice_enabled') && in_array($inferred['intent'], ['catalog_recommendation', 'component_compatibility'], true)) {
                $answer = $advice->answer($intent['query'].' '.$normalizedMessage, $componentReferences);
                if ($answer['context'] !== []) {
                    $remember?->__invoke(ChatbotQueryCategory::Product, $answer['context']);
                }
                if (config('ai_integration.enabled')
                    && ($answer['context']['products'] ?? []) !== []) {
                    $draft = $this->chatbotAiAdapter->grounded($normalizedMessage, $answer['context'], $language, scopeChecked: true);
                    if ($draft['successful']) {
                        return ['category' => ChatbotQueryCategory::Product, 'message' => $draft['text'], 'source' => 'gemini'];
                    }
                }

                return $this->fallback(ChatbotQueryCategory::Product, $answer['message'], $answer['context']);
            }
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

        if (! config('ai_integration.enabled') && $category === ChatbotQueryCategory::Product && preg_match('/\b(?:what products (?:do you have|are available)|what do you (?:sell|have)|browse (?:the )?catalog)\b/iu', $intentMessage) === 1) {
            return $this->fallback($category, 'Browse the Products catalog to see available products and filter by category or brand. Please provide a product name or model if you would like its current price or Sagay availability.');
        }

        if (! config('ai_integration.adaptive') && config('ai_integration.enabled') && (int) config('ai_integration.gemini.max_calls', 1) >= 3
            && $category === ChatbotQueryCategory::Product && ! $classifiedByAi && ! $this->resolveProductContext->isShortSearch($normalizedMessage)
            && ! $this->resolveProductContext->isCatalogSearch($normalizedMessage)) {
            $scope = $this->chatbotAiAdapter->classify($normalizedMessage);
            if ($scope === null || $scope['outcome'] !== 'supported' || $scope['category'] !== 'product') {
                return $this->fallback($category, self::FAQ_INFORMATION_UNAVAILABLE);
            }
            $classifiedByAi = true;
        }

        $context = $productId !== null && $category === ChatbotQueryCategory::Product
            ? $this->resolveProductContext->forProduct($productId)
            : ($classification['rule_topic'] !== null && $category === ChatbotQueryCategory::Faq
                ? $this->ruleGuidance->execute($classification['rule_topic'])
                : $this->resolveContext($category, $category === ChatbotQueryCategory::Order ? $intentMessage : $normalizedMessage, $customer, $language));

        if ($category === ChatbotQueryCategory::Product && ($context['resolution'] ?? null) === 'needs_details') {
            $trace->record('clarification', 'retrieval');
            $clarification = str_contains(Str::lower($normalizedMessage), 'facebook')
                ? 'Please provide the product name, model, or identifying text from the Facebook post so I can check its current price and Sagay availability.'
                : 'Please provide a product name or model so I can check its current price and Sagay availability.';

            return $this->fallback($category, $clarification);
        }

        if ($category === ChatbotQueryCategory::Faq && count($context['knowledge'] ?? []) > 1) {
            return $this->fallback($category, $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE, $context);
        }

        if ($this->hasNoContext($category, $context)) {
            $trace->record('no_match', 'retrieval');

            return $this->fallback(
                $category,
                $this->missingContextMessage($category, $customer),
            );
        }

        unset($context['resolution']);
        $remember?->__invoke($category, $context);

        if (isset($context['search_query'])) {
            $cards = array_map(fn (array $product): array => [
                'id' => $product['id'], 'name' => $product['name'],
                'price' => $product['discount_price'] ?? $product['price'],
                'stock' => str_replace('_', ' ', $product['inventory']['status']),
                'notice' => $product['demo_notice'] ?? null,
            ], $context['products']);
            $intro = 'Matching Battlefront products:';
            $followUp = 'Narrow your search by brand, model, or capacity. Stock information covers Sagay only.';
            $text = $intro;
            foreach ($cards as $card) {
                $text .= "\n\n".$card['name']."\nPHP ".number_format((float) $card['price'], 2).' · '.$card['stock'];
                if ($card['notice'] !== null) {
                    $text .= "\n".$card['notice'];
                }
            }

            return ['category' => $category, 'message' => $text."\n\n".$followUp, 'source' => 'fallback',
                'presentation' => ['intro' => $intro, 'products' => $cards, 'follow_up' => $followUp, 'query' => $context['search_query']]];
        }

        if ((! config('ai_integration.enabled') && count($context['products'] ?? []) > 1) || count($context['branches'] ?? []) > 1
            || ($context['orders'][0]['support']['needs_clarification'] ?? false)) {
            $trace->record('clarification', 'retrieval');

            return $this->fallback($category, $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE, $context);
        }

        if ($category === ChatbotQueryCategory::Faq || ($classifiedByAi && (int) config('ai_integration.gemini.max_calls', 1) === 1)
            || (config('ai_integration.enabled') && $category !== ChatbotQueryCategory::Product)) {
            return $this->fallback(
                $category,
                $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE,
                $context,
            );
        }

        if (($category === ChatbotQueryCategory::Product
            && count($context['products'] ?? []) === 1
            && (preg_match('/\b(?:price|stock|availability|specs|specifications|details)\b/iu', $this->language->canonical($normalizedMessage)) === 1
                || preg_match('/(?:PHP\s*|\x{20B1}\s*)[\d,]+(?:\.\d{1,2})?\s*ba\b/iu', $this->language->canonical($normalizedMessage)) === 1)
            && preg_match('/\b(?:compare|recommend|suggest|compatible|compatibility|versus|vs)\b/iu', $this->language->canonical($normalizedMessage)) !== 1)
            && (config('ai_integration.adaptive') || $this->isClosedProductFactQuestion($normalizedMessage))) {
            return $this->fallback($category, $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? self::FAQ_INFORMATION_UNAVAILABLE, $context);
        }

        $result = config('ai_integration.enabled')
            ? $this->chatbotAiAdapter->grounded($normalizedMessage, $context, $language, scopeChecked: $classifiedByAi || (bool) config('ai_integration.adaptive'))
            : ($language === 'en'
            ? $this->chatbotAiAdapter->generate($normalizedMessage, $context)
            : $this->chatbotAiAdapter->generate($normalizedMessage, $context, $language));

        if ($result['successful']) {
            return [
                'category' => $category,
                'message' => $result['text'],
                'source' => 'gemini',
            ];
        }

        if ($trace->event === null) {
            $trace->record($result['failure'] ?? 'provider_unavailable', ($result['failure'] ?? null) === 'validation_failed' ? 'validation' : 'generation');
        }

        return $this->fallback(
            $category,
            $this->renderChatbotFallback->execute($category, $intentMessage, $context) ?? ($result['failure'] === 'timeout'
                ? self::PROVIDER_TIMEOUT
                : self::PROVIDER_UNAVAILABLE),
            $context,
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

    private function isClosedProductFactQuestion(string $message): bool
    {
        $query = $this->language->canonical($message);

        return preg_match('/\A(?:is|are|do|does|have|has|can|available|stock)\b/u', $query) === 1
            || preg_match('/(?:PHP\s*|\x{20B1}\s*)[\d,]+(?:\.\d{1,2})?\s*ba\b/iu', $query) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(
        ChatbotQueryCategory $category,
        string $message,
        ?User $customer,
        string $language = 'en',
    ): array {
        return match ($category) {
            ChatbotQueryCategory::Product => $this->resolveProductContext->execute($message),
            ChatbotQueryCategory::Order => $this->resolveOrderContext->execute($customer, $message),
            ChatbotQueryCategory::Store => $this->resolveStoreContext->execute($message),
            ChatbotQueryCategory::Faq => $this->resolveFaqContext->execute($message, $language),
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
     * @param  array<string, mixed>  $context
     * @return array{category: ChatbotQueryCategory, message: string, source: 'fallback', protected: list<mixed>, response_context: array<string, mixed>}
     */
    private function fallback(ChatbotQueryCategory $category, string $message, array $context = []): array
    {
        return [
            'category' => $category,
            'message' => $message,
            'source' => 'fallback',
            'response_context' => $context,
            'protected' => [
                ...array_column($context['products'] ?? [], 'name'),
                ...array_column($context['products'] ?? [], 'description'),
                ...array_column($context['branches'] ?? [], 'address'),
                ...array_column($context['branches'] ?? [], 'city'),
                ...array_column($context['knowledge'] ?? [], 'response_template'),
            ],
        ];
    }
}
