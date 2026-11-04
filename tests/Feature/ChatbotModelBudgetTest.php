<?php

use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Cache;

it('bounds retrieval and generation by the same turn deadline', function () {
    config(['ai_integration.gemini.deadline_seconds' => 2]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    expect($budget->retrievalTimeout())->toBeLessThanOrEqual(2);
    config(['ai_integration.gemini.deadline_seconds' => 0]);
    expect($budget->retrievalTimeout())->toBe(0)
        ->and($budget->reserveGrounded('public products'))->toBeFalse();
});

it('rejects embeddings when the interactive deadline has expired', function () {
    config(['ai_integration.gemini.deadline_seconds' => 0]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();

    expect($budget->remainingSeconds())->toBe(0)
        ->and($budget->reserve('response'))->toBeFalse()
        ->and($budget->reserveEmbedding('public catalog'))->toBeFalse();
});

it('keeps indexing and conversational model budgets independent', function () {
    config(['ai_integration.gemini.requests_per_minute' => 1, 'ai_integration.embedding.requests_per_minute' => 1]);
    expect((new ChatbotProviderBudget)->reserve('response'))->toBeTrue();
    expect((new ChatbotProviderBudget)->reserve('validation'))->toBeFalse();
    expect((new ChatbotProviderBudget)->reserveEmbedding('public catalog'))->toBeTrue();
    expect((new ChatbotProviderBudget)->reserveEmbedding('another product'))->toBeFalse();
});

it('preserves earlier shared usage during model budget migration', function () {
    config(['ai_integration.gemini.requests_per_day' => 500, 'ai_integration.embedding.requests_per_day' => 1000]);
    $prefix = 'chatbot:quota:'.hash('sha256', (string) config('ai_integration.gemini.project_budget_key'));
    $day = now()->setTimezone('America/Los_Angeles')->format('Ymd');
    Cache::put($prefix.':'.$day.':requests', 100, 172800);
    expect((new ChatbotProviderBudget)->reserve('response'))->toBeTrue();
    expect((new ChatbotProviderBudget)->reserveEmbedding('public catalog'))->toBeTrue();
    foreach (['gemini', 'embedding'] as $area) {
        $modelPrefix = $prefix.':model:'.hash('sha256', (string) config('ai_integration.'.$area.'.model'));
        expect(Cache::get($modelPrefix.':'.$day.':requests'))->toBe(101);
    }
    expect(Cache::get($prefix.':'.$day.':requests'))->toBe(100);
});

it('enforces daily model limits and admits work after the Pacific reset', function () {
    $this->travelTo(now()->setTimezone('America/Los_Angeles')->startOfDay()->addHours(12));
    config(['ai_integration.embedding.requests_per_day' => 1]);
    expect((new ChatbotProviderBudget)->reserveEmbedding('public catalog'))->toBeTrue();
    $budget = new ChatbotProviderBudget;
    expect($budget->reserveEmbedding('second product'))->toBeFalse();
    expect($budget->retryAfter())->toBeGreaterThan(60);
    expect((new ChatbotProviderBudget)->reserve('response'))->toBeTrue();
    $this->travelTo(now()->setTimezone('America/Los_Angeles')->addDay()->startOfDay()->addSecond());
    expect((new ChatbotProviderBudget)->reserveEmbedding('second product'))->toBeTrue();
});

it('enforces embedding token limits without generation output overhead', function () {
    config(['ai_integration.embedding.tokens_per_minute' => 520]);
    expect((new ChatbotProviderBudget)->reserveEmbedding('product'))->toBeTrue();
    expect((new ChatbotProviderBudget)->reserveEmbedding('product'))->toBeFalse();
    expect((new ChatbotProviderBudget)->reserve('response'))->toBeTrue();
});
