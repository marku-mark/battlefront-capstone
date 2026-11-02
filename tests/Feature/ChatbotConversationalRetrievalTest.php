<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Models\CatalogProjection;
use App\Models\Category;
use App\Models\ComponentSpecificationRevision;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

test('broad catalog requests return bounded available products without embeddings', function () {
    config(['ai_integration.enabled' => true]);
    Product::factory()->available()->count(8)->create();
    $unavailable = Product::factory()->create(['name' => 'Unavailable device']);
    $inactive = Product::factory()->available()->inactive()->create();
    $context = app(ResolveProductContext::class)->execute('What products do you have?');
    expect($context['resolution'])->toBe('matched');
    expect($context['products'])->toHaveCount(6);
    expect(array_column($context['products'], 'id'))->not->toContain($unavailable->id, $inactive->id);
    Http::assertNothingSent();
});

test('catalog evidence includes only scalar specifications from the currently approved revision', function (string $profileStatus, string $revisionStatus) {
    config(['ai_integration.enabled' => true]);
    $product = Product::factory()->available()->create();
    $profile = ProductComponentProfile::factory()->for($product)->create(['review_status' => $profileStatus, 'current_revision' => 2]);
    ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => 1,
        'review_status' => 'approved', 'specifications' => ['socket' => 'STALE']]);
    ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => 2,
        'review_status' => $revisionStatus, 'specifications' => ['socket' => 'AM5', 'memory_slots' => 4, 'supports_ecc' => false, 'supported_cpus' => ['unbounded data']]]);

    $context = app(ResolveProductContext::class)->forProduct($product->id)['products'][0];

    $approved = $profileStatus === 'approved' && $revisionStatus === 'approved';
    expect($context['specification_review_status'])->toBe($approved ? 'approved' : 'unreviewed');
    expect($context['approved_specifications'])->toBe($approved ? ['socket' => 'AM5', 'memory_slots' => 4, 'supports_ecc' => false] : []);
    expect($context)->not->toHaveKeys(['reviewed_by_id', 'reviewed_at', 'evidence']);
    Http::assertNothingSent();
})->with([['approved', 'approved'], ['draft', 'approved'], ['approved', 'draft']]);

test('broad discovery merges semantic candidates with successful lexical results', function (string $query, ?string $semanticMessage) {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true,
        'ai_integration.qdrant.url' => 'https://vectors.example.test', 'ai_integration.qdrant.collection' => 'catalog',
        'ai_integration.qdrant.key' => 'fake', 'ai_integration.embedding.key' => 'fake']);
    $lexical = Product::factory()->available()->create(['name' => 'Gaming Monitor A']);
    $semantic = Product::factory()->available()->create(['name' => 'Studio Display B']);
    $hash = app(CatalogDocumentBuilder::class)->build($semantic)['hash'];
    CatalogProjection::query()->create(['product_id' => $semantic->id, 'revision' => 1, 'qdrant_revision' => 1, 'content_hash' => $hash]);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1)]]),
        'https://vectors.example.test/collections/catalog/points/query' => Http::response(['result' => ['points' => [['id' => $semantic->id, 'score' => .95, 'payload' => ['revision' => 1]]]]]),
    ]);
    $context = app(ResolveProductContext::class)->execute($query, $semanticMessage);
    expect(array_column($context['products'], 'id'))->toBe([$lexical->id, $semantic->id]);
    Http::assertSentCount(2);
})->with([['gaming monitor', null], ['monitor', 'recommend a gaming monitor']]);

test('an explicitly named product does not trigger semantic broadening', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $product = Product::factory()->available()->create(['name' => 'Atlas Gaming Monitor']);
    $context = app(ResolveProductContext::class)->execute('price of Atlas Gaming Monitor for gaming');
    expect(array_column($context['products'], 'id'))->toBe([$product->id]);
    Http::assertNothingSent();
});

test('budget follow ups recover the previous category using current catalog facts', function (string $followUp) {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true]);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $first = Product::factory()->available()->for($category)->create(['name' => 'Atlas Premium GPU', 'price' => 9000]);
    $cheaper = Product::factory()->available()->for($category)->create(['name' => 'Atlas Compact GPU', 'price' => 4000]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Monitors']))->create(['name' => 'Unrelated display', 'price' => 1000]);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$first->id]]])->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('price of Atlas Premium GPU', null, null, 'budget-session');
    $answer = $chat->respond($followUp, null, $initial['context_token'], 'budget-session');
    expect($answer['message'])->toContain($cheaper->name, '4,000.00')->not->toContain($first->name, 'Unrelated display');
    Http::assertNothingSent();
})->with(['something cheaper', 'under PHP 5000', 'what about below 5000?']);

test('category refinements cannot cross session boundaries', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true]);
    $first = Product::factory()->available()->create(['name' => 'Atlas Premium GPU']);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$first->id]]])->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('price of Atlas Premium GPU', null, null, 'owner-session');
    $answer = $chat->respond('something cheaper', null, $initial['context_token'], 'different-session');
    expect($answer['message'])->not->toContain($first->name);
    Http::assertNothingSent();
});

test('web budget follow ups survive multiple catalog results without forced selection', function () {
    $this->withCredentials();
    $this->withSession(['chat_test' => true]);
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true]);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $first = Product::factory()->available()->for($category)->create(['name' => 'Atlas Premium GPU', 'price' => 9000]);
    $second = Product::factory()->available()->for($category)->create(['name' => 'Atlas Basic GPU', 'price' => 4000]);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$first->id, 'product:'.$second->id]]])->preventStrayPrompts();

    $initial = $this->postJson('/chatbot', ['message' => 'price of Atlas Premium GPU and Atlas Basic GPU'])->assertOk();
    expect($initial->json('message'))->toContain($first->name, $second->name);
    expect(app(ChatbotConversationToken::class)->decode($initial->json('context_token'), session()->getId().':guest'))->toHaveKey('references');
    $this->withCookie(config('session.cookie'), session()->getId());
    $answer = $this->postJson('/chatbot', ['message' => 'under PHP 5000', 'context_token' => $initial->json('context_token')])
        ->assertOk()->assertJsonPath('source', 'fallback');
    expect($answer->json('message'))->toContain($second->name)->not->toContain($first->name);
    Http::assertNothingSent();
});

test('budget refinements do not recover products after their category is hidden', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true]);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $first = Product::factory()->available()->for($category)->create(['name' => 'Atlas Premium GPU']);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$first->id]]])->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('price of Atlas Premium GPU', null, null, 'hidden-category');
    $category->update(['is_active' => false]);

    $answer = $chat->respond('something cheaper', null, $initial['context_token'], 'hidden-category');

    expect($answer['message'])->toContain('Which component category')->not->toContain($first->name);
    Http::assertNothingSent();
});
