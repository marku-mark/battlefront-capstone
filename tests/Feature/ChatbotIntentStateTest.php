<?php

use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotCompoundRequest;
use App\Services\Chatbot\ChatbotGroundedResponse;
use App\Services\Chatbot\ChatbotIntentState;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use App\Services\Chatbot\Retrieval\RetrievalFailure;
use App\Services\Chatbot\SemanticCatalogCandidates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function catalogIntent(array $overrides = []): array
{
    return array_replace(['outcome' => 'supported', 'intent' => 'discovery', 'language' => 'en', 'category_key' => 'memory',
        'search_query' => 'RAM', 'product_mentions' => [], 'attributes' => ['stock'], 'maximum_php' => null,
        'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new', 'position' => 0,
        'clarification' => 'none'], $overrides);
}

test('explicit constraint removals do not inherit filters from the previous catalog search', function () {
    $category = Category::factory()->create(['name' => 'RAM']);
    $a = Product::factory()->available()->for($category)->create(['name' => 'Atlas Memory', 'brand' => 'Atlas', 'price' => 5000, 'discount_price' => null]);
    $b = Product::factory()->available()->for($category)->create(['name' => 'Beacon Memory', 'brand' => 'Beacon', 'price' => 6000, 'discount_price' => null]);
    $context = app(ChatbotCatalogSelection::class)->select('Show more RAM', catalogIntent([
        'operation' => 'refine', 'constraint_updates' => ['brand' => 'clear', 'excluded_brands' => 'clear', 'budget' => 'clear'],
    ]), ['model_constraints' => ['category_key' => 'memory', 'brand' => 'Atlas', 'excluded_brands' => ['Beacon'],
        'price' => ['maximum' => '1000.00', 'maximum_inclusive' => false]]]);

    expect(array_column($context['products'], 'id'))->toContain($a->id, $b->id);
    expect($context['selection_constraints'])->toMatchArray(['brand' => '', 'excluded_brands' => [], 'operation' => 'refine']);
    expect($context['selection_constraints']['price']['maximum'])->toBeNull();
    Http::assertNothingSent();
});

test('a new topic cannot inherit prior catalog constraints', function () {
    $result = app(ChatbotIntentState::class)->apply(catalogIntent(['operation' => 'change_topic', 'reference' => 'previous']), ['brand' => 'Atlas']);
    expect($result['reference'])->toBe('new');
    expect(app(ChatbotIntentState::class)->inherits($result, 'brand'))->toBeFalse();
});

test('retaining a budget preserves strict bounds and sorting', function () {
    $result = app(ChatbotIntentState::class)->apply(catalogIntent(['operation' => 'refine', 'constraint_updates' => ['budget' => 'retain']]), [
        'price' => ['minimum' => '1000.00', 'maximum' => '3000.00', 'minimum_inclusive' => false, 'maximum_inclusive' => true, 'sort' => 'desc'],
    ]);
    expect($result)->toMatchArray(['minimum_php' => '1000.00', 'maximum_php' => '3000.00', 'minimum_inclusive' => false, 'maximum_inclusive' => true, 'sort' => 'price_desc']);
});

test('understanding rejects unknown constraint operations and fields', function (array $updates) {
    config(['ai_integration.gemini.max_calls' => 3]);
    ChatbotUnderstandingAgent::fake([catalogIntent(['operation' => 'refine', 'constraint_updates' => $updates])])->preventStrayPrompts();
    expect(app(ChatbotAiAdapter::class)->understand('Show more RAM', []))->toBeNull();
    Http::assertNothingSent();
})->with([
    'unknown action' => [['brand' => 'delete']],
    'private field' => [['customer_id' => 'set']],
]);

test('exact product selection does not perform unused remote retrieval in full mode', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.full_catalog_pipeline' => true, 'ai_integration.qdrant.enabled' => true, 'ai_integration.neo4j.enabled' => true]);
    $product = Product::factory()->available()->create(['name' => 'Atlas Memory']);
    $context = app(ChatbotCatalogSelection::class)->select('Atlas Memory price', catalogIntent([
        'intent' => 'facts', 'product_mentions' => ['Atlas Memory'], 'search_query' => 'Atlas Memory',
    ]));
    expect(array_column($context['products'], 'id'))->toBe([$product->id]);
    Http::assertNothingSent();
});

test('catalog retrieval circuits distinguish local failures from provider outages and recover after cooldown', function (string $reason, bool $opens) {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $this->mock(GeminiEmbeddingClient::class, fn ($mock) => $mock->shouldReceive('embed')->andReturn([0.1]));
    $this->mock(QdrantClient::class, fn ($mock) => $mock->shouldReceive('search')->andThrow(new RetrievalFailure($reason)));
    expect(app(SemanticCatalogCandidates::class)->ids('RAM'))->toBe([]);
    expect(Cache::has('chatbot:retrieval:open'))->toBe($opens);
    $this->travel(61)->seconds();
    expect(Cache::has('chatbot:retrieval:open'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'deadline' => ['deadline_exceeded', false],
    'configuration' => ['invalid_configuration', false],
    'provider outage' => ['provider_http', true],
    'network outage' => ['transport', true],
]);

test('comparison drafts cannot omit evidence for a requested product', function () {
    $evidence = ['product:1' => 'Atlas RAM — PHP 1,000.00.', 'product:2' => 'Beacon RAM — PHP 2,000.00.'];
    $renderer = app(ChatbotGroundedResponse::class);
    expect($renderer->draft($evidence, ['answer' => 'Atlas RAM costs PHP 1,000.00.', 'evidence_ids' => ['product:1']],
        ['required_product_ids' => [1, 2]]))->toBeNull();
});

test('stored compound intents do not replay operations from the previous turn', function () {
    $part = app(ChatbotCompoundRequest::class)->bounded([catalogIntent([
        'operation' => 'new_search', 'constraint_updates' => ['brand' => 'clear'],
    ])])[0];
    expect($part)->not->toHaveKeys(['operation', 'constraint_updates']);
});

test('replacing an explicit brand removes the old brand from the retrieval query', function () {
    $result = app(ChatbotIntentState::class)->apply(catalogIntent(['operation' => 'refine', 'brand' => 'Beacon',
        'search_query' => 'Atlas RAM', 'constraint_updates' => ['brand' => 'set']]), ['brand' => 'Atlas']);
    expect($result['search_query'])->toBe('RAM');
    expect($result['brand'])->toBe('Beacon');
});

test('no-result recovery proposes only a filter change with current eligible matches', function () {
    $category = Category::factory()->create(['name' => 'RAM']);
    Product::factory()->available()->for($category)->create(['name' => 'Atlas RAM', 'brand' => 'Atlas', 'price' => 5000, 'discount_price' => null]);
    $context = app(ChatbotCatalogSelection::class)->select('RAM', catalogIntent(['brand' => 'Beacon', 'maximum_php' => 6000]));
    expect($context['products'])->toBe([]);
    expect($context['recovery_options'])->toBe(['brand']);
    expect($context['selection_constraints']['brand'])->toBe('Beacon');
    expect($context['selection_constraints']['price']['maximum'])->toBe('6000.00');
    $conflict = app(ChatbotCatalogSelection::class)->select('RAM', catalogIntent(['brand' => 'Beacon', 'maximum_php' => 1000]));
    expect($conflict['recovery_options'])->toBe([]);
    Http::assertNothingSent();
});

test('filter refinements can restart discovery after an empty selection without selecting nonexistent previous products', function () {
    $category = Category::factory()->create(['name' => 'RAM']);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Atlas RAM', 'brand' => 'Atlas', 'price' => 1500, 'discount_price' => null]);
    $context = app(ChatbotCatalogSelection::class)->select('Any brand', catalogIntent([
        'operation' => 'refine', 'reference' => 'previous', 'price_scope' => 'previous',
        'constraint_updates' => ['brand' => 'clear', 'excluded_brands' => 'clear', 'budget' => 'retain'],
    ]), ['references' => ['product' => []], 'model_constraints' => ['category_key' => 'memory', 'brand' => 'Beacon',
        'price' => ['maximum' => '3000.00', 'maximum_inclusive' => false]]]);
    expect(array_column($context['products'], 'id'))->toBe([$product->id]);
    expect($context)->not->toHaveKey('clarification');
    expect($context['selection_constraints']['price']['maximum'])->toBe('3000.00');
});

test('removing the only search constraint asks for a product type', function () {
    $context = app(ChatbotCatalogSelection::class)->select('Any brand', catalogIntent([
        'operation' => 'refine', 'reference' => 'previous', 'category_key' => 'other', 'search_query' => '',
    ]), ['model_constraints' => ['category_key' => 'other', 'brand' => 'HP', 'search_query' => 'HP']]);

    expect($context['products'])->toBe([]);
    expect($context['clarification'])->toBe('Which product type would you like to browse?');
});

test('cloud readiness identifies missing or mistyped filter indexes without changing vectors', function () {
    config(['ai_integration.qdrant.enabled' => true, 'ai_integration.qdrant.url' => 'https://vector.test', 'ai_integration.qdrant.key' => 'testing-only']);
    Http::fake(['https://vector.test/*' => Http::response(['result' => [
        'config' => ['params' => ['vectors' => ['size' => 768, 'distance' => 'Cosine']]],
        'payload_schema' => ['category_id' => ['data_type' => 'keyword'], 'brand' => ['data_type' => 'keyword'], 'component_type' => ['data_type' => 'keyword']],
    ]])]);
    $readiness = app(QdrantClient::class)->indexReadiness();
    expect($readiness)->toMatchArray(['ready' => false, 'dimensions_match' => true, 'distance_match' => true,
        'missing_indexes' => ['product_family', 'product_role'], 'mismatched_indexes' => ['category_id']]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});
