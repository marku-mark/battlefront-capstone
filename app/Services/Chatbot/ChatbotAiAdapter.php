<?php

namespace App\Services\Chatbot;

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class ChatbotAiAdapter
{
    /** @param array<string, mixed> $publicState
     * @return array<string, mixed>|null */
    public function understand(string $message, array $publicState): ?array
    {
        if (config('ai_integration.offline') || app(ChatbotOrchestrationService::class)->containsSensitiveInput($message)) {
            return null;
        }
        $prompt = $this->preparePrompt(mb_substr($message, 0, 1000), $publicState);
        $agent = new ChatbotUnderstandingAgent;
        $budget = app(ChatbotProviderBudget::class);
        if (! $budget->reserve($prompt."\n".$agent->instructions())) {
            app(ChatbotFailureTrace::class)->record('budget_exhausted', 'scope');

            return null;
        }
        $started = hrtime(true);
        try {
            $response = $agent->prompt($prompt, provider: $this->roleProvider('scope'), model: (string) config('ai_integration.gemini.model'), timeout: min(4, $budget->remainingSeconds()));
            $this->logUsage('scope', $response);
            $budget->recordStage('scope', 'completed', (int) ((hrtime(true) - $started) / 1_000_000));
            if (! $response instanceof StructuredAgentResponse) {
                $budget->recordStage('scope', 'failed');
                app(ChatbotFailureTrace::class)->record('understanding_invalid_output', 'scope');
                $this->logAttempt($started, 4, 'understanding_invalid_response');

                return null;
            }
            $data = $response->structured;
            $defaults = ['branch' => '', 'availability' => 'any', 'sort' => 'relevance', 'minimum_php' => null, 'minimum_inclusive' => true, 'maximum_inclusive' => true, 'price_scope' => 'catalog', 'product_role' => 'main'];
            $data = [...$defaults, ...$data];
            $validator = Validator::make($data, [
                'outcome' => ['required', Rule::in(['supported', 'clarify', 'unsupported'])],
                'intent' => ['required', Rule::in(ChatbotUnderstandingAgent::INTENTS)],
                'language' => ['required', Rule::in(['en', 'tl', 'ceb', 'hil', 'und'])],
                'category_key' => ['required', Rule::in(ChatbotUnderstandingAgent::CATEGORIES)],
                'search_query' => ['present', 'string', 'max:250'],
                'product_mentions' => ['present', 'array', 'max:6'], 'product_mentions.*' => ['string', 'max:200'],
                'attributes' => ['present', 'array', 'max:4'], 'attributes.*' => [Rule::in(['price', 'stock', 'specifications', 'comparison'])],
                'sort' => ['required', Rule::in(['relevance', 'price_asc', 'price_desc'])],
                'minimum_php' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
                'minimum_inclusive' => ['required', 'boolean'], 'maximum_inclusive' => ['required', 'boolean'],
                'price_scope' => ['required', Rule::in(['catalog', 'named', 'previous'])],
                'product_role' => ['required', Rule::in(['main', 'accessory', 'any'])],
                'maximum_php' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
                'excluded_brands' => ['present', 'array', 'max:6'], 'excluded_brands.*' => ['string', 'max:80'],
                'brand' => ['present', 'string', 'max:80'], 'capacity' => ['present', 'string', 'max:40'], 'use_case' => ['present', 'string', 'max:100'],
                'branch' => ['present', 'string', 'max:100'],
                'availability' => ['required', Rule::in(['any', 'in_stock'])],
                'reference' => ['required', Rule::in(['new', 'previous'])], 'position' => ['required', 'integer', 'min:0', 'max:6'],
                'clarification' => ['required', Rule::in(['none', 'cpu_meaning', 'missing_reference', 'mixed_topics', 'missing_details'])],
            ]);

            if ($validator->fails() || count($data) !== 23) {
                $budget->recordStage('scope', 'failed');
                app(ChatbotFailureTrace::class)->record('understanding_invalid_output', 'scope');
                $this->logAttempt($started, 4, 'understanding_invalid_output');

                return null;
            }

            if ($data['branch'] !== '' && mb_stripos($message, $data['branch']) === false
                && mb_strtolower($data['branch']) !== mb_strtolower($publicState['constraints']['branch'] ?? '')) {
                app(ChatbotFailureTrace::class)->record('understanding_invalid_output', 'scope');

                return null;
            }

            return $validator->validated();
        } catch (Throwable $exception) {
            $budget->recordStage('scope', 'failed', (int) ((hrtime(true) - $started) / 1_000_000));
            $details = $this->failureDetails($exception);
            app(ChatbotFailureTrace::class)->record($details['failure'], 'scope');
            $budget->recordFailure();
            $this->logAttempt($started, 4, $details['failure'], $details['status'], $exception::class);

            return null;
        }
    }

    public function beginTurn(): void
    {
        app(ChatbotProviderBudget::class)->reset();
    }

    /** @param array<string, mixed> $context
     * @return array{successful: bool, text: ?string, failure: ?string}
     */
    public function grounded(string $message, array $context, string $language = 'en', bool $scopeChecked = false): array
    {
        if (config('ai_integration.offline')) {
            return ['successful' => false, 'text' => null, 'failure' => 'offline'];
        }
        $renderer = app(ChatbotGroundedResponse::class);
        $evidence = $renderer->conversationalEvidence($context);
        $failure = ['successful' => false, 'text' => null, 'failure' => 'validation_failed'];
        if ($evidence === []) {
            app(ChatbotFailureTrace::class)->record('no_match', 'retrieval');

            return $failure;
        }
        if (! $scopeChecked && (int) config('ai_integration.gemini.max_calls', 1) >= 3) {
            $scope = $this->classify($message);
            if ($scope === null || $scope['outcome'] !== 'supported' || $scope['category'] !== 'product') {
                if (($scope['outcome'] ?? null) === 'unsupported') {
                    app(ChatbotFailureTrace::class)->scope = 'unsupported';
                }
                app(ChatbotFailureTrace::class)->record('understanding_failed', 'scope');

                return $failure;
            }
        }
        $intent = $this->publicProductIntent($message, $context, $language);
        if (isset($context['interpreted_intent'])) {
            $intent = [...$intent, ...$context['interpreted_intent'], 'language' => $language];
        }
        $message = (config('ai_integration.model_led') || config('ai_integration.full_catalog_pipeline')) ? mb_substr($message, 0, 1000) : app(PublicChatbotQuery::class)->minimize($message);
        $message = $message !== '' ? $message : 'product information';
        $prompt = $this->preparePrompt($message, ['evidence' => $evidence, 'intent' => $intent]);
        $budget = app(ChatbotProviderBudget::class);
        if ((config('ai_integration.adaptive') || (config('ai_integration.model_led') || config('ai_integration.full_catalog_pipeline'))) ? ! $budget->reserveGrounded($prompt) : ! $budget->reserve($prompt)) {
            app(ChatbotFailureTrace::class)->record('budget_exhausted', 'generation');

            return ['successful' => false, 'text' => null, 'failure' => 'budget_exhausted'];
        }
        $started = hrtime(true);
        $reviewAllowance = (config('ai_integration.adaptive') || (config('ai_integration.model_led') || config('ai_integration.full_catalog_pipeline'))) ? (config('ai_integration.full_catalog_pipeline') ? 5 : (int) config('ai_integration.gemini.validation_timeout_seconds', 5)) : 0;
        $responseTimeout = max(1, min(60, (config('ai_integration.full_catalog_pipeline') ? 8 : (int) config('ai_integration.gemini.response_timeout_seconds', 9)), $budget->remainingSeconds() - $reviewAllowance));
        $currentStage = 'generation';
        $stageStarted = hrtime(true);
        try {
            $response = (new ChatbotEvidenceAgent)->prompt($prompt,
                provider: $this->roleProvider('response'),
                model: (string) config('ai_integration.gemini.model'), timeout: $responseTimeout);
            $this->logUsage('response', $response);
            $budget->recordStage('generation', 'completed', (int) ((hrtime(true) - $stageStarted) / 1_000_000));
            Log::info('Chatbot stage completed.', ['role' => 'response', 'duration_ms' => (int) ((hrtime(true) - $stageStarted) / 1_000_000)]);
            $natural = $response instanceof StructuredAgentResponse && array_key_exists('answer', $response->structured);
            $text = $response instanceof StructuredAgentResponse
                ? ($natural ? $renderer->draft($evidence, $response->structured, $intent) : $renderer->render($renderer->evidence($context), $response->structured)) : null;
            if ($text === null) {
                $budget->recordStage('generation', 'failed');
                app(ChatbotFailureTrace::class)->record('validation_failed', 'generation');

                return $failure;
            }
            if ($natural || config('ai_integration.full_catalog_pipeline') || config('ai_integration.gemini.validation_enabled')) {
                $review = $this->preparePrompt($message, ['evidence' => $evidence, 'intent' => $intent, 'answer' => $text]);
                if ($budget->remainingSeconds() === 0 || ! $budget->reserve($review)) {
                    $budget->recordStage('validation', 'failed');
                    app(ChatbotFailureTrace::class)->record('budget_exhausted', 'validation');

                    return $failure;
                }
                $stageStarted = hrtime(true);
                $currentStage = 'validation';
                $verdict = (new ChatbotValidationAgent)->prompt($review,
                    provider: $this->roleProvider('validation'),
                    model: (string) config('ai_integration.gemini.model'), timeout: max(1, min(60, (config('ai_integration.full_catalog_pipeline') ? 5 : (int) config('ai_integration.gemini.validation_timeout_seconds', 20)), $budget->remainingSeconds())));
                $this->logUsage('validation', $verdict);
                $budget->recordStage('validation', 'completed', (int) ((hrtime(true) - $stageStarted) / 1_000_000));
                Log::info('Chatbot stage completed.', ['role' => 'validation', 'duration_ms' => (int) ((hrtime(true) - $stageStarted) / 1_000_000)]);
                if (! $verdict instanceof StructuredAgentResponse || $verdict->structured !== ['verdict' => 'approve']) {
                    $budget->recordStage('validation', 'failed');
                    app(ChatbotFailureTrace::class)->record('validation_failed', 'validation');

                    return $failure;
                }
            }
            $this->logAttempt($started, $responseTimeout);
            $budget->recordSuccess();

            return ['successful' => true, 'text' => $text, 'failure' => null];
        } catch (Throwable $exception) {
            $details = $this->failureDetails($exception);
            $budget->recordFailure();
            $this->logAttempt($started, $responseTimeout, $details['failure'], $details['status'], $exception::class);
            app(ChatbotFailureTrace::class)->record($details['failure'], $currentStage);
            $budget->recordStage($currentStage, 'failed', (int) ((hrtime(true) - $stageStarted) / 1_000_000));

            return ['successful' => false, 'text' => null, 'failure' => $details['failure']];
        }
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    private function publicProductIntent(string $message, array $context, string $language): array
    {
        $query = app(ChatbotLanguage::class)->canonical($message);
        $focus = match (true) {
            preg_match('/\b(?:compare|comparison|difference|versus|vs|cheaper|cheapest)\b/iu', $query) === 1 => 'comparison',
            preg_match('/\b(?:specifications|specs|details|description)\b/iu', $query) === 1 => 'details',
            preg_match('/\b(?:price|prices|cost|how much)\b/iu', $query) === 1 => 'price',
            preg_match('/\b(?:stock|available|availability)\b/iu', $query) === 1 => 'availability',
            preg_match('/\b(?:recommend|suggest|affordable|budget)\b/iu', $query) === 1 => 'recommendation',
            default => 'browse',
        };
        $maximum = null;
        if (preg_match('/\b(?:under|below|less than|budget(?: of)?)\s*(?:PHP\s*|\x{20B1}\s*)?([\d,]+(?:\.\d{1,2})?)(?![\p{L}\p{N}])/iu', $query, $budget) === 1) {
            $maximum = (float) str_replace(',', '', $budget[1]);
        }
        preg_match_all('/\b(?:not|without|exclude|except)\s+(intel|amd|nvidia|ddr4|ddr5|gaming|used)\b/iu', $query, $excluded);
        $prices = [];
        foreach (array_slice($context['products'] ?? [], 0, 6) as $product) {
            $price = $product['discount_price'] ?? $product['price'] ?? null;
            if (isset($product['id']) && is_numeric($price) && ! ($product['is_demo'] ?? false)) {
                $prices[(string) $product['id']] = (int) round((float) $price * 100);
            }
        }
        asort($prices);
        $comparison = count($prices) >= 2 ? [
            'lowest_price_product_id' => array_key_first($prices),
            'highest_price_product_id' => array_key_last($prices),
            'difference_php' => (max($prices) - min($prices)) / 100,
            'scope' => 'Only the supplied current catalog candidates; not a performance ranking.',
        ] : null;

        return [
            'focus' => $context['conversation_focus'] ?? $focus,
            'requested_attributes' => app(ChatbotLanguage::class)->interpret($message)['attributes'],
            'language' => in_array($language, ChatbotLanguage::LANGUAGES, true) ? $language : 'en',
            'maximum_php' => $maximum,
            'excluded_terms' => array_values(array_unique(array_map('strtolower', $excluded[1]))),
            'product_ids' => array_slice(array_column($context['products'] ?? [], 'id'), 0, 6),
            'price_comparison' => $comparison,
        ];
    }

    private function roleProvider(string $role): string
    {
        $name = 'chatbot_'.$role;
        config()->set('ai.providers.'.$name, [
            'driver' => 'gemini',
            'key' => config('ai_integration.gemini.'.$role.'_key') ?: config('ai.providers.gemini.key'),
        ]);

        return $name;
    }

    private function logUsage(string $role, AgentResponse $response): void
    {
        app(ChatbotProviderBudget::class)->recordUsage((int) $response->usage->promptTokens, (int) $response->usage->completionTokens);
        Log::info('Chatbot provider usage.', [
            'role' => $role,
            'model' => (string) config('ai_integration.gemini.model'),
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
        ]);
    }

    /** @return array{outcome: string, category: string, intent: string, language: string}|null */
    public function classify(string $message): ?array
    {
        if (config('ai_integration.offline')) {
            return null;
        }
        $agent = new ChatbotIntentAgent;
        $started = hrtime(true);
        try {
            $message = preg_replace('/\bbf[\s\W_]*\d+\b/iu', '[order reference]', $message) ?? $message;
            if (config('ai_integration.enabled')) {
                $message = app(PublicChatbotQuery::class)->minimize($message);
                if ($message === '') {
                    return null;
                }
            }
            $allowed = ChatbotIntentAgent::INTENTS;
            if (config('ai_integration.enabled')) {
                $allowed = [];
                foreach (ChatbotIntentAgent::INTENTS as $intent => $definition) {
                    $allowed[$definition['category']][] = $intent;
                }
            }
            $prompt = $this->preparePrompt($message, ['allowed_intents' => $allowed]);
            if (config('ai_integration.enabled') && ! app(ChatbotProviderBudget::class)->reserve($prompt)) {
                return null;
            }
            $response = config('ai_integration.enabled')
                ? $agent->prompt($prompt, provider: $this->roleProvider('scope'), model: (string) config('ai_integration.gemini.model'), timeout: min($agent->timeout(), config('ai_integration.adaptive') ? 4 : $agent->timeout(), app(ChatbotProviderBudget::class)->remainingSeconds()))
                : $agent->prompt($prompt);
            if (config('ai_integration.enabled')) {
                $this->logUsage('scope', $response);
            }
            if (! $response instanceof StructuredAgentResponse) {
                $this->logAttempt($started, $agent->timeout(), 'invalid_classification');

                return null;
            }
            $data = $response->structured;
            if (! config('ai_integration.advice_enabled') && in_array($data['intent'] ?? null, ['catalog_recommendation', 'component_compatibility'], true)) {
                return null;
            }
            $validator = Validator::make(['classification' => $data], [
                'classification' => ['required', 'array:outcome,category,intent,language'],
                'classification.outcome' => ['required', Rule::in(['supported', 'uncertain', 'unsupported'])],
                'classification.category' => ['required', Rule::in(['product', 'store', 'order', 'faq', 'unsupported'])],
                'classification.intent' => ['required', Rule::in([...array_keys(ChatbotIntentAgent::INTENTS), 'none'])],
                'classification.language' => ['required', Rule::in([...ChatbotLanguage::LANGUAGES, 'und'])],
            ]);
            if ($validator->fails()
                || ! is_string($data['outcome']) || ! is_string($data['category'])
                || ! is_string($data['intent']) || ! is_string($data['language'])
                || ($data['outcome'] === 'supported'
                    ? (ChatbotIntentAgent::INTENTS[$data['intent']]['category'] ?? null) !== $data['category']
                    : ($data['category'] !== 'unsupported' || $data['intent'] !== 'none'))) {
                $this->logAttempt($started, $agent->timeout(), 'invalid_classification');

                return null;
            }
            $this->logAttempt($started, $agent->timeout());

            return ['outcome' => $data['outcome'], 'category' => $data['category'], 'intent' => $data['intent'], 'language' => $data['language']];
        } catch (Throwable $exception) {
            $details = $this->failureDetails($exception);
            $this->logAttempt($started, $agent->timeout(), $details['failure'], $details['status'], $exception::class);

            return null;
        }
    }

    /**
     * Generate chatbot wording from application-prepared input.
     *
     * @param  array<string|int, mixed>  $context
     * @return array{successful: true, text: string, failure: null}|array{successful: false, text: null, failure: string}
     */
    public function generate(string $prompt, array $context, string $language = 'en'): array
    {
        if (config('ai_integration.offline')) {
            return ['successful' => false, 'text' => null, 'failure' => 'offline'];
        }
        $preparedPrompt = $this->preparePrompt($prompt, $context);
        $agent = new ChatbotResponseAgent($language);
        $remaining = app(ChatbotProviderBudget::class)->remainingSeconds();
        if ($remaining === 0) {
            return ['successful' => false, 'text' => null, 'failure' => 'timeout'];
        }
        $started = hrtime(true);

        try {
            $text = trim($agent->prompt($preparedPrompt, timeout: min($agent->timeout(), $remaining))->text);
        } catch (Throwable $exception) {
            $details = $this->failureDetails($exception);
            app(ChatbotFailureTrace::class)->record($details['failure'], 'generation');
            $this->logAttempt($started, $agent->timeout(), $details['failure'], $details['status'], $exception::class);

            return [
                'successful' => false,
                'text' => null,
                'failure' => $details['failure'],
            ];
        }

        if ($text === '') {
            app(ChatbotFailureTrace::class)->record('empty_response', 'generation');
            $this->logAttempt($started, $agent->timeout(), 'empty_response');

            return [
                'successful' => false,
                'text' => null,
                'failure' => 'empty_response',
            ];
        }

        $this->logAttempt($started, $agent->timeout());

        return [
            'successful' => true,
            'text' => $text,
            'failure' => null,
        ];
    }

    /** @return array{failure: string, status: int|null} */
    private function failureDetails(Throwable $exception): array
    {
        $status = null;
        $timeout = false;
        $connection = false;

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException) {
                $status = $cause->response->status();
            }
            $timeout = $timeout || $cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException;
            $connection = $connection || $cause instanceof ProviderConnectionException || $cause instanceof ConnectionException || $cause instanceof NetworkException;
        }

        return [
            'failure' => match (true) {
                $timeout, in_array($status, [408, 504], true) => 'timeout',
                $exception instanceof RateLimitedException, $status === 429 => 'rate_limited',
                $exception instanceof InsufficientCreditsException, $status === 402 => 'quota_exhausted',
                in_array($status, [401, 403], true) => 'authentication_failed',
                $connection => 'connection_failed',
                $exception instanceof ProviderOverloadedException => 'provider_overloaded',
                default => 'provider_unavailable',
            },
            'status' => $status,
        ];
    }

    private function logAttempt(int $started, int $timeout, ?string $failure = null, ?int $status = null, ?string $exceptionClass = null): void
    {
        $context = [
            'provider' => 'gemini',
            'model' => config('ai_integration.enabled') ? (string) config('ai_integration.gemini.model') : 'gemini-3.5-flash-lite',
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'timeout_seconds' => $timeout,
            'failure' => $failure,
            'http_status' => $status,
            'exception_class' => $exceptionClass,
        ];

        if ($failure === null) {
            Log::info('Chatbot provider attempt completed.', $context);
        } else {
            Log::warning('Chatbot provider attempt failed.', $context);
        }
    }

    /**
     * @param  array<string|int, mixed>  $context
     */
    private function preparePrompt(string $prompt, array $context): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new InvalidArgumentException('The prepared chatbot prompt must not be blank.');
        }

        $this->ensureScalarContext($context);

        try {
            $encodedPrompt = json_encode(
                $prompt,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $encodedContext = json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The prepared chatbot input must be valid UTF-8 JSON data.',
                previous: $exception,
            );
        }

        return <<<PROMPT
            Customer message (JSON string):
            {$encodedPrompt}

            Authoritative context (JSON):
            {$encodedContext}
            PROMPT;
    }

    /**
     * @param  array<string|int, mixed>  $context
     */
    private function ensureScalarContext(array $context): void
    {
        foreach ($context as $value) {
            if (is_array($value)) {
                $this->ensureScalarContext($value);

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(
                    'The prepared chatbot context may contain only arrays, scalar values, and null.',
                );
            }
        }
    }
}
