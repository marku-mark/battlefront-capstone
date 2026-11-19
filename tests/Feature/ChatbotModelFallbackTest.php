<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true,
        'ai_integration.gemini.fallback_enabled' => true, 'ai_integration.gemini.enforce_project_limits' => false,
        'ai_integration.gemini.max_calls' => 3]);
});

test('limited text models switch to the next Gemini model and remain skipped during cooldown', function () {
    $seen = [];
    $intent = ['outcome' => 'supported', 'intent' => 'discovery', 'language' => 'en', 'category_key' => 'memory',
        'search_query' => 'RAM', 'product_mentions' => [], 'attributes' => ['stock'], 'maximum_php' => null,
        'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new', 'position' => 0, 'clarification' => 'none'];
    ChatbotUnderstandingAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen, $intent): array {
        $seen[] = $model;
        if ($model === 'gemini-3.5-flash-lite') {
            throw RateLimitedException::forProvider('gemini');
        }

        return $intent;
    })->preventStrayPrompts();
    $adapter = app(ChatbotAiAdapter::class);

    expect($adapter->understand('Show me RAM', [])['intent'])->toBe('discovery');
    expect($seen)->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite']);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(2);
    $adapter->beginTurn();
    $adapter->understand('Show me SSD', []);

    expect($seen)->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3.1-flash-lite']);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    $this->travel(61)->seconds();
    $adapter->beginTurn();
    $adapter->understand('Show me RAM', []);
    expect(array_slice($seen, -2))->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite']);
    Http::assertNothingSent();
});

test('all limited models stop at the shared three attempt budget', function () {
    $seen = [];
    ChatbotUnderstandingAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen): never {
        $seen[] = $model;
        throw RateLimitedException::forProvider('gemini');
    })->preventStrayPrompts();

    expect(app(ChatbotAiAdapter::class)->understand('Show me RAM', []))->toBeNull();
    expect($seen)->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-2.5-flash-lite']);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(3);
    Http::assertNothingSent();
});

test('non limit errors never switch models', function () {
    $seen = [];
    ChatbotUnderstandingAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen): never {
        $seen[] = $model;
        throw new RuntimeException('private provider failure');
    })->preventStrayPrompts();

    expect(app(ChatbotAiAdapter::class)->understand('Show me RAM', []))->toBeNull();
    expect($seen)->toBe(['gemini-3.5-flash-lite']);
    Http::assertNothingSent();
});

test('provider overload switches models without retrying invalid output', function () {
    $seen = [];
    ChatbotUnderstandingAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen): array {
        $seen[] = $model;
        if ($model === 'gemini-3.5-flash-lite') {
            throw new ProviderOverloadedException('Temporary overload');
        }

        return [];
    })->preventStrayPrompts();

    expect(app(ChatbotAiAdapter::class)->understand('Show me RAM', []))->toBeNull();
    expect($seen)->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite']);
    Http::assertNothingSent();
});

test('understanding uses its configured timeout while preserving the shared deadline', function () {
    config(['ai_integration.gemini.scope_timeout_seconds' => 6]);
    ChatbotUnderstandingAgent::fake([[]])->preventStrayPrompts();

    app(ChatbotAiAdapter::class)->understand('Show me RAM', []);

    ChatbotUnderstandingAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 6);
    Http::assertNothingSent();
});

test('model retries cannot consume capacity reserved for mandatory review', function () {
    $budget = app(ChatbotProviderBudget::class);
    $budget->reset();
    expect($budget->reserve('understanding'))->toBeTrue();
    expect($budget->reserveGrounded('generation and review'))->toBeTrue();

    expect($budget->reserveRetry('generation retry'))->toBeFalse();
    expect($budget->reserve('mandatory review'))->toBeTrue();
    expect($budget->usage()['calls'])->toBe(3);
    $budget->releaseTurn();
});

test('guessed project quotas do not block text requests when disabled', function () {
    config(['ai_integration.gemini.requests_per_minute' => 0, 'ai_integration.gemini.requests_per_day' => 0, 'ai_integration.gemini.tokens_per_minute' => 0]);
    $budget = app(ChatbotProviderBudget::class);

    expect($budget->reserve('public query'))->toBeTrue();
    $budget->releaseTurn();
});

test('legacy answer generation also switches Gemini models on provider limits', function () {
    config(['ai_integration.enabled' => false]);
    $seen = [];
    ChatbotResponseAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$seen): string {
        $seen[] = $model;
        if ($model === 'gemini-3.5-flash-lite') {
            throw RateLimitedException::forProvider('gemini');
        }

        return 'Verified public wording.';
    })->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->generate('Public product information', ['products' => []]);

    expect($result['successful'])->toBeTrue();
    expect($seen)->toBe(['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite']);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(2);
    Http::assertNothingSent();
});

test('understanding skips failover when the remaining calls must fund drafting and review', function () {
    Product::factory()->available()->create(['name' => 'Atlas SSD', 'price' => 2000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake(fn () => throw RateLimitedException::forProvider('gemini'))->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => 'What is the price of Atlas SSD?'])
        ->assertOk()->assertJsonPath('source', 'fallback');

    expect($response->json('message'))->toContain('Atlas SSD', '2,000.00')
        ->and(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});
