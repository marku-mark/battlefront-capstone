<?php

use App\Models\CatalogProjection;
use App\Models\ComponentSpecificationRevision;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\Chatbot\ChatbotCatalogAdvice;
use App\Services\Chatbot\GraphCatalogCandidates;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set([
        'ai_integration.enabled' => true,
        'ai_integration.advice_enabled' => true,
        'ai_integration.neo4j.enabled' => true,
        'ai_integration.neo4j.url' => 'https://graph.example.test',
        'ai_integration.neo4j.username' => 'test-user',
        'ai_integration.neo4j.password' => 'test-only',
        'ai_integration.neo4j.database' => 'neo4j',
    ]);
});

function projectedChatbotPart(string $name, string $type = 'motherboard', ?int $categoryId = null): Product
{
    $attributes = ['name' => $name, 'price' => 5000, 'discount_price' => null];
    if ($categoryId !== null) {
        $attributes['category_id'] = $categoryId;
    }
    $product = Product::factory()->available()->create($attributes);
    $profile = ProductComponentProfile::factory()->create(['product_id' => $product->id, 'component_type' => $type, 'review_status' => 'approved', 'current_revision' => 1]);
    ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => 1, 'review_status' => 'approved', 'specifications' => ['socket' => 'AM5']]);
    CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 2, 'neo4j_revision' => 2, 'content_hash' => app(CatalogDocumentBuilder::class)->build($product)['hash']]);

    return $product;
}

test('graph alternatives require current same type evidence and exclude the seed', function () {
    $seed = projectedChatbotPart('Atlas Board A');
    $candidate = projectedChatbotPart('Atlas Board B');
    $wrongType = projectedChatbotPart('Atlas Processor C', 'processor');
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => [[$seed->id, 2], [$candidate->id, 2], [$wrongType->id, 2]]]])]);

    expect(app(GraphCatalogCandidates::class)->ids($seed->id))->toBe([$candidate->id]);
    Http::assertSent(fn (Request $request): bool => $request['accessMode'] === 'Read'
        && $request['parameters']['ids'] === [$seed->id]
        && $request['parameters']['seedRevision'] === 2
        && str_contains($request['statement'], 'seed.revision'));
});

test('broad recommendations consult current graph evidence beyond lexical matches', function () {
    $seed = projectedChatbotPart('Atlas Motherboard A');
    $candidate = projectedChatbotPart('Helios Board B', categoryId: $seed->category_id);
    $candidate->update(['price' => 4000]);
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => [[$candidate->id, 2]]]])]);

    $answer = app(ChatbotCatalogAdvice::class)->answer('Recommend motherboard under PHP 6000');

    expect(array_column($answer['context']['products'], 'id'))->toContain($candidate->id, $seed->id);
    Http::assertSent(fn (Request $request): bool => $request['parameters']['ids'] === [$seed->id] && $request['accessMode'] === 'Read');
});

test('graph candidates reject stale content revisions and unavailable catalog rows', function () {
    $seed = projectedChatbotPart('Atlas Board A');
    $changed = projectedChatbotPart('Atlas Board B');
    $stale = projectedChatbotPart('Atlas Board C');
    $inactive = projectedChatbotPart('Atlas Board D');
    $draft = projectedChatbotPart('Atlas Board E');
    $changed->update(['description' => 'Changed approved catalog copy']);
    $inactive->update(['is_active' => false]);
    $draft->componentProfile->update(['review_status' => 'draft']);
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => [[$changed->id, 2], [$stale->id, 1], [$inactive->id, 2], [$draft->id, 2]]]])]);

    expect(app(GraphCatalogCandidates::class)->ids($seed->id))->toBe([]);
    Http::assertSentCount(1);
});

test('stale graph seed stops retrieval before a request and feature flags prevent provider access', function () {
    $seed = projectedChatbotPart('Atlas Board A');
    CatalogProjection::query()->where('product_id', $seed->id)->update(['neo4j_revision' => 1]);
    expect(app(GraphCatalogCandidates::class)->ids($seed->id))->toBe([]);
    config()->set('ai_integration.neo4j.enabled', false);
    expect(app(GraphCatalogCandidates::class)->ids($seed->id))->toBe([]);
    Http::assertNothingSent();
});

test('similar product advice uses graph candidates without a compatibility claim', function () {
    $seed = projectedChatbotPart('Atlas Board A');
    $candidate = projectedChatbotPart('Helios Board B');
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => [[$candidate->id, 2]]]])]);

    $response = app(ChatbotCatalogAdvice::class)->answer('Alternatives to Atlas Board A');

    expect($response['message'])->toContain('Helios Board B', 'compatibility require verified specifications')->not->toContain('Atlas Board A', 'Compatibility review:');
    Http::assertSentCount(1);
});

test('graph outage falls back to available same category alternatives and opens the circuit', function () {
    $seed = projectedChatbotPart('Atlas Board A');
    projectedChatbotPart('Helios Board B', categoryId: $seed->category_id);
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response([], 503)]);

    $advice = app(ChatbotCatalogAdvice::class);
    expect($advice->answer('Similar to Atlas Board A')['message'])->toContain('Helios Board B');
    expect($advice->answer('Similar to Atlas Board A')['message'])->toContain('Helios Board B');
    Http::assertSentCount(1);
});

test('web chatbot alternative requests reach the graph retrieval path', function () {
    projectedChatbotPart('Atlas Board A');
    $candidate = projectedChatbotPart('Helios Board B');
    Http::fake(['https://graph.example.test/db/neo4j/query/v2' => Http::response(['data' => ['values' => [[$candidate->id, 2]]]])]);

    $this->postJson('/chatbot', ['message' => 'Similar to Atlas Board A'])->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJson(fn ($json) => $json->where('message', fn (string $message): bool => str_contains($message, 'Helios Board B'))->etc());
    Http::assertSentCount(1);
});
