<?php

use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Enums\ChatbotCategory;
use App\Jobs\SyncFaqProjection;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\SemanticFaqCandidates;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function enableSemanticFaqRetrieval(): void
{
    config(['ai_integration.enabled' => true, 'ai_integration.faq.enabled' => true, 'ai_integration.faq.collection' => 'faqs', 'ai_integration.qdrant.enabled' => true,
        'ai_integration.qdrant.url' => 'https://vector.example.test', 'ai_integration.qdrant.key' => 'fake', 'ai_integration.embedding.key' => 'fake']);
}

function fakeSemanticFaqRows(array $rows): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);
    Http::preventStrayRequests();
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 1.0)]]),
        'https://vector.example.test/collections/faqs/points/query' => Http::response(['result' => ['points' => $rows]]),
    ]);
}

test('semantic FAQs require a current source hash and active eligible MySQL record', function (bool $stale, bool $active) {
    enableSemanticFaqRetrieval();
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => 'Use catalog search.', 'is_active' => $active]);
    $hash = app(FaqDocumentBuilder::class)->hash($record);
    fakeSemanticFaqRows([['id' => $record->id * 10, 'score' => 0.95, 'payload' => ['faq_id' => $record->id, 'language' => 'en', 'source_hash' => $stale ? str_repeat('a', 64) : $hash]]]);

    expect(app(SemanticFaqCandidates::class)->ids('catalog navigation'))->toBe(! $stale && $active ? [$record->id] : []);
    Http::assertSentCount(2);
})->with([[false, true], [true, true], [false, false]]);

test('semantic FAQ ties retain clarification instead of selecting an arbitrary answer', function () {
    enableSemanticFaqRetrieval();
    $rows = [];
    foreach (['How do I search for products?', "How can I view a product's details?"] as $index => $question) {
        $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => $question, 'response_template' => 'Approved guidance.']);
        $rows[] = ['id' => $record->id * 10, 'score' => 0.95 - $index * 0.01, 'payload' => ['faq_id' => $record->id, 'language' => 'en', 'source_hash' => app(FaqDocumentBuilder::class)->hash($record)]];
    }
    fakeSemanticFaqRows($rows);
    expect(app(MatchChatbotKnowledge::class)->bestMatches('catalog navigation', allowSemantic: true))->toHaveCount(2);
    Http::assertSentCount(2);
});

test('FAQ previews and sensitive or private inquiries never use semantic providers', function () {
    enableSemanticFaqRetrieval();
    app(MatchChatbotKnowledge::class)->bestMatches('catalog navigation');
    expect(app(SemanticFaqCandidates::class)->ids('My password is private'))->toBe([]);
    expect(app(SemanticFaqCandidates::class)->ids('Where is my order BF000001?'))->toBe([]);
    Http::assertNothingSent();
});

test('FAQ indexing exports reviewed versions only and retracts absent language documents', function () {
    enableSemanticFaqRetrieval();
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => 'Use search.', 'translations' => ['hil' => ['question_pattern' => 'Paano mangita?', 'response_template' => 'Gamita ang search.', 'reviewed' => false]]]);
    fakeSemanticFaqRows([]);
    Http::fake([
        'https://vector.example.test/collections/faqs/points?wait=true' => Http::response(['result' => ['status' => 'completed']]),
        'https://vector.example.test/collections/faqs/points/delete?wait=true' => Http::response(['result' => ['status' => 'completed']]),
    ]);
    config(['ai_integration.sync_enabled' => true]);
    app()->call([new SyncFaqProjection($record->id), 'handle']);

    Http::assertSentCount(5);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'points?wait=true') && $request['points'][0]['payload']['language'] === 'en');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'points?wait=true') && $request['points'][0]['payload']['language'] === 'hil');
});
