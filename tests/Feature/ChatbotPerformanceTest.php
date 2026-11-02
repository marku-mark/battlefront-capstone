<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\ComponentSpecificationRevision;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\Chatbot\ChatbotProviderBudget;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

test('releases an unused review reservation while retaining dispatched usage', function () {
    config(['ai_integration.gemini.requests_per_minute' => 2, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    expect($budget->reserveGrounded('public evidence'))->toBeTrue();
    $budget->recordUsage(10, 5);
    $budget->releaseTurn();
    $next = new ChatbotProviderBudget;
    expect($next->reserve('next'))->toBeTrue();
    $next->releaseTurn();
});

test('loads only the current approved specification revision', function () {
    $profile = ProductComponentProfile::factory()->create(['review_status' => 'approved', 'current_revision' => 2]);
    foreach ([1, 2, 3] as $revision) {
        ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => $revision, 'review_status' => 'approved']);
    }
    $profile->load('currentApprovedRevision');
    expect($profile->currentApprovedRevision?->revision)->toBe(2)
        ->and($profile->relationLoaded('revisions'))->toBeFalse();
});

test('diagnostics report local backlog without provider calls', function () {
    Http::preventStrayRequests();
    $this->artisan('chatbot:diagnostics')->assertSuccessful();
    Http::assertNothingSent();
});

test('adaptive exact product facts do not invoke Gemini', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.adaptive' => true]);
    $product = Product::factory()->available()->create(['name' => 'Atlas SSD', 'price' => 2000, 'discount_price' => null]);
    ChatbotIntentAgent::fake()->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'What is the price of Atlas SSD?'])->assertOk()->assertJsonPath('source', 'fallback');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
});

test('vector searches apply allowlisted filters before candidate limits', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.example', 'ai_integration.qdrant.key' => 'fake']);
    Http::preventStrayRequests();
    Http::fake(['vector.example/*' => Http::response(['result' => ['points' => []]])]);
    (new QdrantClient)->search([1.0], filters: ['category_id' => 3, 'untrusted' => 'ignored']);
    Http::assertSent(fn ($request) => $request['filter'] === ['must' => [['key' => 'category_id', 'match' => ['value' => 3]]]]);
});

test('grounded admission requires capacity for both generation and review', function () {
    config(['ai_integration.gemini.requests_per_minute' => 1]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    expect($budget->reserveGrounded('public evidence'))->toBeFalse()
        ->and($budget->usage()['calls'])->toBe(0);
});

test('grounded admission prepays review without a third quota reservation', function () {
    config(['ai_integration.gemini.requests_per_minute' => 2, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    $budget = new ChatbotProviderBudget;
    $budget->reset();
    expect($budget->reserveGrounded('public evidence'))->toBeTrue()
        ->and($budget->reserve('review'))->toBeTrue()
        ->and($budget->usage()['calls'])->toBe(2)
        ->and($budget->reserve('extra'))->toBeFalse();
    $budget->releaseTurn();
});

test('reuses embeddings across identical public searches', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.embedding.key' => 'fake']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]])]);
    $client = new GeminiEmbeddingClient;
    expect($client->embed('public SSD'))->toBe($client->embed('public SSD'));
    Http::assertSentCount(1);
});

test('graph alternatives filter component type and rank before limiting', function () {
    config(['ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'https://graph.example', 'ai_integration.neo4j.username' => 'test', 'ai_integration.neo4j.password' => 'fake']);
    Http::preventStrayRequests();
    Http::fake(['graph.example/*' => Http::response(['data' => ['values' => [[2, 1]]]])]);
    expect((new Neo4jClient)->candidates([1], 1))->toBe([['product_id' => 2, 'revision' => 1]]);
    Http::assertSent(fn ($request) => str_contains($request['statement'], 'p.component_type = seed.component_type')
        && str_contains($request['statement'], 'ORDER BY relevance DESC, p.mysql_id LIMIT 20'));
});
