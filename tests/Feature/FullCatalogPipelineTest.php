<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Jobs\SyncCatalogProjection;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotProviderBudget;
use App\Services\Chatbot\GraphCatalogCandidates;
use App\Services\Chatbot\ModelLedChatbot;
use App\Services\Chatbot\SemanticCatalogCandidates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

test('full catalog mode overrides simple catalog shortcut but protects private support', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => false, 'ai_integration.full_catalog_pipeline' => true]);
    expect(app(ModelLedChatbot::class)->eligible('Do you have processors?'))->toBeTrue()
        ->and(app(ModelLedChatbot::class)->eligible('My order BF12345 status'))->toBeFalse();
});

test('full catalog mode bounds the deadline even with a longer operator setting', function () {
    config(['ai_integration.full_catalog_pipeline' => true, 'ai_integration.gemini.deadline_seconds' => 60]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    expect($budget->remainingSeconds())->toBeLessThanOrEqual(25);
});

test('retrieval diagnostics distinguish disabled providers and outage cooldowns', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => false, 'ai_integration.neo4j.enabled' => false]);
    expect(app(SemanticCatalogCandidates::class)->ids('SSD'))->toBe([]);
    expect(app(GraphCatalogCandidates::class)->forSeeds([]))->toBe([]);
    $prefix = 'chatbot:stage:'.now()->format('Ymd').':';
    expect(Cache::get($prefix.'qdrant:disabled'))->toBe(1)
        ->and(Cache::get($prefix.'neo4j:disabled'))->toBe(1);
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.neo4j.enabled' => true]);
    Cache::put('chatbot:retrieval:open', true, 60);
    Cache::put('chatbot:graph:open', true, 60);
    expect(app(SemanticCatalogCandidates::class)->ids('SSD'))->toBe([]);
    expect(app(GraphCatalogCandidates::class)->forSeeds([]))->toBe([]);
    expect(Cache::get($prefix.'qdrant:circuit_open'))->toBe(1)
        ->and(Cache::get($prefix.'neo4j:circuit_open'))->toBe(1);
});

test('stage diagnostics reject arbitrary labels', function () {
    $budget = app(ChatbotProviderBudget::class);
    $budget->recordStage('scope', 'failed', 23);
    $budget->recordStage('scope', 'customer supplied text', 99);
    $prefix = 'chatbot:stage:'.now()->format('Ymd').':scope:';
    expect(Cache::get($prefix.'failed'))->toBe(1)
        ->and(Cache::get($prefix.'duration_ms'))->toBe(23)
        ->and(Cache::has($prefix.'customer supplied text'))->toBeFalse();
});

test('projection dispatch deduplicates pending product work', function () {
    Queue::fake();
    SyncCatalogProjection::dispatch(987);
    SyncCatalogProjection::dispatch(987);
    Queue::assertPushed(SyncCatalogProjection::class, 1);
});

test('catalog turns review current facts and expand candidates only for discovery', function (string $verdict, string $source, bool $exact) {
    config(['ai_integration.enabled' => true, 'ai_integration.full_catalog_pipeline' => true, 'ai_integration.model_led' => false,
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 100000]);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD']);
    ChatbotUnderstandingAgent::fake([['outcome' => 'supported', 'intent' => $exact ? 'facts' : 'discovery', 'language' => 'en', 'category_key' => 'storage', 'search_query' => $exact ? 'Atlas SSD' : 'SSD',
        'product_mentions' => $exact ? ['Atlas SSD'] : [], 'attributes' => ['stock'], 'maximum_php' => null, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new', 'position' => 0, 'clarification' => 'none']])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => 'Atlas SSD is available.', 'evidence_ids' => ['product:'.$product->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => $verdict]])->preventStrayPrompts();
    $this->mock(SemanticCatalogCandidates::class)->shouldReceive('ids')->times($exact ? 0 : 1)->andReturn([]);
    $this->mock(GraphCatalogCandidates::class)->shouldReceive('forSeeds')->times($exact ? 0 : 1)->andReturn([]);
    $this->postJson('/chatbot', ['message' => $exact ? 'Is Atlas SSD available?' : 'Show me SSDs'])->assertOk()->assertJsonPath('source', $source);
    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
})->with([['approve', 'gemini'], ['reject', 'fallback']])->with(['exact selection' => true, 'catalog discovery' => false]);

test('expired turns cannot reserve calls or start retrieval', function () {
    config(['ai_integration.full_catalog_pipeline' => true]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    (new ReflectionProperty($budget, 'started'))->setValue($budget, microtime(true) - 26);
    expect($budget->remainingSeconds())->toBe(0)
        ->and($budget->retrievalTimeout())->toBe(0)
        ->and($budget->reserve('public query'))->toBeFalse()
        ->and($budget->reserveGrounded('public evidence'))->toBeFalse()
        ->and($budget->usage()['calls'])->toBe(0);
});
