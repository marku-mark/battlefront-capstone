<?php

use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Branch;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotOrchestrationService;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(fn () => Http::preventStrayRequests());

test('uncertain merchant wording uses one classifier call and deterministic live facts', function () {
    Branch::factory()->create(['city' => 'Sagay City', 'operating_hours' => '9 AM to 5 PM']);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'store', 'intent' => 'store_hours', 'language' => 'en']])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('I am thinking of dropping by your shop in Sagay. When could someone help me?');

    expect($result['source'])->toBe('fallback');
    expect($result['message'])->toContain('9 AM to 5 PM');
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('classifier output must be allowlisted complete and internally consistent', function (array $output) {
    ChatbotIntentAgent::fake([$output])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Can your shop help with this?');

    expect($result['message'])->toContain('Could you clarify');
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotResponseAgent::assertNeverPrompted();
})->with([
    'unknown category' => [['outcome' => 'supported', 'category' => 'admin', 'intent' => 'store_hours', 'language' => 'en']],
    'wrong intent category' => [['outcome' => 'supported', 'category' => 'order', 'intent' => 'store_hours', 'language' => 'hil']],
    'invented intent' => [['outcome' => 'supported', 'category' => 'faq', 'intent' => 'approve_refund', 'language' => 'tl']],
    'unknown language' => [['outcome' => 'supported', 'category' => 'faq', 'intent' => 'returns', 'language' => 'fr']],
    'missing field' => [['outcome' => 'supported', 'category' => 'faq', 'intent' => 'returns']],
    'extra facts' => [['outcome' => 'supported', 'category' => 'faq', 'intent' => 'returns', 'language' => 'ceb', 'answer' => 'You qualify']],
    'uncertain' => [['outcome' => 'uncertain', 'category' => 'unsupported', 'intent' => 'none', 'language' => 'und']],
]);

test('classifier refusal cannot be turned into a business answer', function () {
    ChatbotIntentAgent::fake([['outcome' => 'unsupported', 'category' => 'unsupported', 'intent' => 'none', 'language' => 'tl']])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Can your shop help with this?');

    expect($result['message'])->toContain('I can only help');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('classifier failures clarify without retries or response generation', function (string $failure) {
    ChatbotIntentAgent::fake(function () use ($failure) {
        throw match ($failure) {
            'timeout' => ProviderConnectionException::forProvider('gemini', previous: new ConnectTimeoutException('private', new Request('POST', 'https://example.test'))),
            'rate_limit' => new RateLimitedException('gemini'),
            default => new AiException('private provider failure'),
        };
    })->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Can your shop help with this?');

    expect($result['message'])->toContain('Could you clarify')->not->toContain('private');
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with(['timeout', 'rate_limit', 'unavailable']);

test('classifier keeps identifiers and private provider text out of prompts and logs', function () {
    Log::spy();
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'order', 'intent' => 'order_status', 'language' => 'en']])->preventStrayPrompts();

    app(ChatbotAiAdapter::class)->classify('My purchase BF-000045 needs checking');

    ChatbotIntentAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, 'BF-000045')
        && ! str_contains($prompt->prompt, 'testing-only') && $prompt->timeout === 20 && $prompt->provider()->name() === 'gemini');
    $safeContext = Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), 'purchase') && ! str_contains(json_encode($context), 'BF-000045'));
    Log::shouldHaveReceived('info')->with('Chatbot provider attempt completed.', $safeContext)->once();
    Log::shouldHaveReceived('info')->with('Chatbot stage finished.', $safeContext)->once();
});

test('inferred personal orders still require authentication and identification', function () {
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'order', 'intent' => 'order_status', 'language' => 'en']])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('My purchase seems stuck');

    expect($result['message'])->toContain('sign in');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('classification may identify guidance but cannot invent policy facts', function () {
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'faq', 'intent' => 'returns', 'language' => 'hil']])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Battlefront, this purchase does not work for me');

    expect($result['source'])->toBe('fallback');
    expect($result['message'])->toContain('does not guarantee acceptance', 'not a confirmed policy');
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotResponseAgent::assertNeverPrompted();
});

test('inferred product intent finds a literal model in unfamiliar wording without another call', function () {
    Product::factory()->available()->create(['name' => 'Aurelius X-42', 'price' => '123.45', 'discount_price' => null]);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'product', 'intent' => 'product_price', 'language' => 'en']])->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond('Your shop has an Aurelius X-42; what is the damage on one of those?');

    expect($result['message'])->toContain('Aurelius X-42', 'PHP 123.45');
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotResponseAgent::assertNeverPrompted();
});
