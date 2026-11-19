<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotFailureTrace;
use App\Services\Chatbot\ChatbotGroundedResponse;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;

test('output-limit signals remain bounded diagnostics and never approve an unreviewed reply', function () {
    config(['ai_integration.gemini.max_calls' => 2, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    ChatbotEvidenceAgent::fake([new StructuredTextResponse([
        'answer' => 'Atlas SSD costs PHP 2,000.', 'evidence_ids' => ['product:42'],
    ], '', new Usage(completionTokens: 512), new Meta)])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'reject', 'reason' => 'incomplete_answer']])->preventStrayPrompts();
    $reply = app(ChatbotAiAdapter::class)->grounded('What is the price?', ['products' => [['id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00']]], scopeChecked: true);
    expect($reply['successful'])->toBeFalse();
    expect(app(ChatbotFailureTrace::class)->providerResponses[0])->toMatchArray(['role' => 'response', 'output_limit' => 512, 'limit_reached' => true]);
    expect(app(ChatbotFailureTrace::class)->providerResponses)->toHaveCount(2);
    Http::assertNothingSent();
});

test('full catalog review can use remaining deadline headroom while retaining its overall deadline', function () {
    config(['ai_integration.full_catalog_pipeline' => true, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    ChatbotEvidenceAgent::fake([['answer' => 'Atlas SSD costs PHP 2,000.', 'evidence_ids' => ['product:42']]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();
    $reply = app(ChatbotAiAdapter::class)->grounded('What is the price?', ['products' => [['id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00']]], scopeChecked: true);
    expect($reply['successful'])->toBeTrue();
    ChatbotValidationAgent::assertPrompted(fn ($prompt): bool => $prompt->timeout > 5 && $prompt->timeout <= 10);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBeLessThanOrEqual(3);
});

test('draft rejection diagnostics contain bounded codes and clear after a valid draft', function () {
    $renderer = app(ChatbotGroundedResponse::class);
    $evidence = ['product:1' => 'Atlas RAM — PHP 1,000.00.'];
    expect($renderer->draft($evidence, ['answer' => 'Atlas RAM costs PHP 999.', 'evidence_ids' => ['product:1']]))->toBeNull();
    expect(app(ChatbotFailureTrace::class)->draftReason)->toBe('unsupported_number');
    expect($renderer->draft($evidence, ['answer' => 'Atlas RAM costs PHP 1,000.', 'evidence_ids' => ['product:1']]))->not->toBeNull();
    expect(app(ChatbotFailureTrace::class)->draftReason)->toBeNull();
});

test('review diagnostics allow only bounded reasons and preserve legacy approvals', function (array $review, bool $approved, ?string $reason) {
    config(['ai_integration.gemini.max_calls' => 2, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    Log::spy();
    ChatbotEvidenceAgent::fake([['answer' => 'Atlas SSD costs PHP 2,000.', 'evidence_ids' => ['product:42']]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([$review])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->grounded('What is the price?', ['products' => [[
        'id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00',
    ]]], scopeChecked: true);

    expect($result['successful'])->toBe($approved);
    expect(array_column(app(ChatbotFailureTrace::class)->stages, 'status'))->toBe(['completed', $approved ? 'completed' : 'failed']);
    expect(app(ChatbotFailureTrace::class)->reviewReason)->toBe($reason);
    if ($reason !== null) {
        expect($result['text'])->toBeNull();
        Log::shouldHaveReceived('notice')->with('Chatbot review rejected.', ['reason' => $reason])->once();
    }
    ChatbotValidationAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with([
    'new approval' => [['verdict' => 'approve', 'reason' => 'none'], true, null],
    'legacy approval' => [['verdict' => 'approve'], true, null],
    'fact rejection' => [['verdict' => 'reject', 'reason' => 'unsupported_fact'], false, 'unsupported_fact'],
    'language rejection' => [['verdict' => 'reject', 'reason' => 'wrong_language'], false, 'wrong_language'],
    'constraint rejection' => [['verdict' => 'reject', 'reason' => 'ignored_constraint'], false, 'ignored_constraint'],
    'incomplete rejection' => [['verdict' => 'reject', 'reason' => 'incomplete_answer'], false, 'incomplete_answer'],
    'unsafe rejection' => [['verdict' => 'reject', 'reason' => 'unsafe_content'], false, 'unsafe_content'],
    'unknown reason' => [['verdict' => 'reject', 'reason' => 'private customer text'], false, 'invalid_review'],
    'contradictory approval' => [['verdict' => 'approve', 'reason' => 'wrong_language'], false, 'invalid_review'],
    'extra fields' => [['verdict' => 'approve', 'reason' => 'none', 'text' => 'untrusted'], false, 'invalid_review'],
    'legacy rejection' => [['verdict' => 'reject'], false, 'invalid_review'],
]);

test('review reason does not survive a new conversation turn', function () {
    $trace = app(ChatbotFailureTrace::class);
    $trace->reviewReason = 'wrong_language';

    $trace->reset();

    expect($trace->reviewReason)->toBeNull();
});

test('understanding reports a single timed stage outcome for malformed output', function (array $overrides, string $reason) {
    Log::spy();
    ChatbotUnderstandingAgent::fake([[
        'outcome' => 'supported', 'intent' => 'discovery', 'language' => 'en', 'category_key' => 'memory',
        'search_query' => 'RAM', 'product_mentions' => [], 'attributes' => [], 'maximum_php' => null,
        'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'previous',
        'position' => 6, 'clarification' => 'none', ...$overrides,
    ]])->preventStrayPrompts();

    expect(app(ChatbotAiAdapter::class)->understand('Show me the RAM again', []))->toBeNull();
    expect(array_column(app(ChatbotFailureTrace::class)->stages, 'status'))->toBe(['failed']);
    Log::shouldHaveReceived('info')->with('Chatbot stage finished.', Mockery::on(fn (array $data): bool => $data['reason'] === $reason && is_int($data['provider_wait_ms']) && is_int($data['php_ms'])
    ))->once();
})->with([
    'position outside the schema range' => [['position' => 7], 'position_out_of_range'],
    'unknown field' => [['unexpected' => 'untrusted content'], 'unknown_field'],
    'branch absent from the request' => [['branch' => 'Sagay'], 'ungrounded_branch'],
]);

test('the provider schema exposes the PHP position and list bounds', function () {
    $schema = (new ChatbotUnderstandingAgent)->schema(new JsonSchemaTypeFactory);

    expect($schema['position']->toArray())->toMatchArray(['minimum' => 0, 'maximum' => 6])
        ->and($schema['product_mentions']->toArray())->toMatchArray(['maxItems' => 6])
        ->and($schema['attributes']->toArray())->toMatchArray(['maxItems' => 4]);
});
