<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotGroundedResponse;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;

function understood(array $overrides = []): array
{
    return array_replace(['outcome' => 'supported', 'intent' => 'discovery', 'language' => 'tl', 'category_key' => 'computer', 'search_query' => 'PC',
        'product_mentions' => [], 'attributes' => ['stock'], 'maximum_php' => null, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '',
        'reference' => 'new', 'position' => 0, 'clarification' => 'none'], $overrides);
}

test('model understanding switches from computers to processors across Tagalog turns', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.adaptive' => true,
        'ai_integration.qdrant.enabled' => false, 'scout.driver' => 'null', 'ai_integration.gemini.tokens_per_minute' => 60000, 'ai_integration.gemini.max_calls' => 3]);
    Http::preventStrayRequests();
    $pc = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Atlas Desktop PC']);
    $cpu = Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'Atlas CPU']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'PC Case']))->create(['name' => 'CPU CASE']);
    ChatbotUnderstandingAgent::fake([understood(), understood(['category_key' => 'processor', 'search_query' => 'CPU'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => 'May Atlas Desktop PC kami sa Sagay.', 'evidence_ids' => ['product:'.$pc->id]],
        ['answer' => 'May Atlas CPU kami sa Sagay.', 'evidence_ids' => ['product:'.$cpu->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve'], ['verdict' => 'approve']])->preventStrayPrompts();
    $first = $this->postJson('/chatbot', ['message' => 'meron po ba kayo pc?'])->assertOk()->assertJsonPath('language', 'tl')->assertJsonPath('source', 'gemini');
    $second = $this->postJson('/chatbot', ['message' => 'meron po rin ba kayo cpu?', 'context_token' => $first->json('context_token')])->assertOk()->assertJsonPath('language', 'tl')->assertJsonPath('source', 'gemini');
    expect($first->json('message'))->toContain('Atlas Desktop PC')->not->toContain('CPU CASE');
    expect($second->json('message'))->toContain('Atlas CPU')->not->toContain('CPU CASE');
    ChatbotUnderstandingAgent::assertNeverPrompted();
    ChatbotEvidenceAgent::assertPromptedTimes(2);
    ChatbotValidationAgent::assertPromptedTimes(2);
    Http::assertNothingSent();
});

test('CPU discovery does not ask whether CPU means a processor or system unit', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake([understood(['outcome' => 'clarify', 'clarification' => 'cpu_meaning'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'Do you have CPU?'])->assertOk();
    expect($response->json('message'))->not->toContain('processor or a complete desktop system unit');
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('invalid understanding output cannot drive catalog retrieval', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake([understood(['category_key' => 'orders; DROP TABLE products'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'Show PCs'])->assertOk();
    expect($response->json('source'))->toBe('fallback');
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('the interpreter receives original language and negation without private history', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake([understood(['outcome' => 'clarify', 'clarification' => 'missing_details', 'language' => 'ceb'])])->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'Naa moy PC pero dili Intel?'])->assertOk()->assertJsonPath('language', 'ceb');
    ChatbotUnderstandingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Naa moy PC pero dili Intel?')
        && ! str_contains($prompt->prompt, 'customer_id'));
});

test('model output cannot introduce a product name absent from the message and public context', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.qdrant.enabled' => false]);
    Product::factory()->available()->create(['name' => 'Secret Model 9000']);
    ChatbotUnderstandingAgent::fake([understood(['intent' => 'facts', 'category_key' => 'other', 'search_query' => 'Secret Model 9000',
        'product_mentions' => ['Secret Model 9000']])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'How much is that item?'])->assertOk();
    expect($response->json('message'))->not->toContain('Secret Model 9000');
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('a missing ordinal reference cannot resolve an unrelated product', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake([understood(['intent' => 'facts', 'reference' => 'previous', 'position' => 2])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'How much is the second one?'])->assertOk();
    expect($response->json('source'))->toBe('fallback');
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('model led path preserves private order authorization', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'Where is my order BF000001?'])->assertOk()
        ->assertJsonPath('source', 'fallback');
    ChatbotUnderstandingAgent::assertNeverPrompted();
});

test('review rejection returns current catalog facts without a repair call', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.qdrant.enabled' => false, 'scout.driver' => 'null',
        'ai_integration.gemini.tokens_per_minute' => 30000, 'ai_integration.gemini.max_calls' => 3]);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'Atlas CPU', 'price' => 2200, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([understood(['intent' => 'facts', 'category_key' => 'processor', 'search_query' => 'CPU', 'attributes' => ['price']])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => 'May Atlas CPU kami sa Sagay.', 'evidence_ids' => ['product:'.$product->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'reject']])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'CPU price?'])->assertOk()->assertJsonPath('source', 'fallback');
    expect($response->json('message'))->toContain('Atlas CPU');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(2);
    ChatbotUnderstandingAgent::assertNeverPrompted();
    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
});

test('missing provider capacity does not start a free form draft', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.requests_per_minute' => 1]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'Atlas CPU']);
    ChatbotUnderstandingAgent::fake([understood(['category_key' => 'processor', 'search_query' => 'CPU'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'Show processors'])->assertOk()->assertJsonPath('source', 'fallback');
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
});

test('model led answers retain the selected regional language', function (string $message, string $language, string $answer) {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.max_calls' => 3,
        'ai_integration.gemini.tokens_per_minute' => 30000, 'scout.driver' => 'null']);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'Atlas CPU']);
    ChatbotUnderstandingAgent::fake([understood(['language' => $language, 'category_key' => 'processor', 'search_query' => 'CPU'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => $answer, 'evidence_ids' => ['product:'.$product->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => $message])->assertOk()->assertJsonPath('language', $language)->assertJsonPath('source', 'gemini');
    expect($response->json('message'))->toStartWith($answer);
    ChatbotValidationAgent::assertPromptedTimes(1);
})->with([
    ['Naa moy processor?', 'ceb', 'Aduna kamiy Atlas CPU sa Sagay.'],
    ['May ara kamo processor?', 'hil', 'May ara kami Atlas CPU sa Sagay.'],
    ['Do you have processors?', 'en', 'Atlas CPU is available at Sagay.'],
]);

test('barebones evidence cannot support a complete ready to use PC claim', function () {
    $renderer = app(ChatbotGroundedResponse::class);
    $evidence = $renderer->conversationalEvidence(['products' => [[
        'id' => 12,
        'name' => 'Atlas Barebones PC',
        'price' => 6500,
        'inventory' => ['status' => 'in_stock'],
    ]]]);

    expect($evidence['product:12'])->toContain('confirm missing components');
    expect($renderer->draft($evidence, ['answer' => 'Atlas Barebones PC is a complete ready-to-use PC.', 'evidence_ids' => ['product:12']]))->toBeNull();
});

test('model led price extrema use the full matching catalog and preserve presentation order', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.qdrant.enabled' => false,
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000, 'scout.driver' => 'null']);
    Http::preventStrayRequests();
    $category = Category::factory()->create(['name' => 'Processor']);
    Product::factory()->available()->for($category)->count(8)->sequence(fn ($sequence) => ['name' => 'Atlas CPU '.$sequence->index])->create(['price' => '5000.00', 'discount_price' => null]);
    $cheap = Product::factory()->available()->for($category)->create(['name' => 'Discounted Processor', 'price' => '20000.00', 'discount_price' => '1000.00']);
    $expensive = Product::factory()->available()->for($category)->create(['name' => 'Premium Processor', 'price' => '10000.00', 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([understood(['category_key' => 'processor', 'search_query' => 'Processor', 'sort' => 'price_asc']), understood(['category_key' => 'processor', 'search_query' => 'Processor', 'sort' => 'price_desc'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([
        ['answer' => 'Discounted Processor is the cheapest matching processor.', 'evidence_ids' => ['product:'.$cheap->id]],
        ['answer' => 'Premium Processor is the most expensive matching processor.', 'evidence_ids' => ['product:'.$expensive->id]],
    ])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve'], ['verdict' => 'approve']])->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'cheapest processor'])->assertOk()->assertJsonPath('source', 'gemini')->assertJsonPath('presentation.actions.0.id', $cheap->id);
    $this->postJson('/chatbot', ['message' => 'most expensive processor'])->assertOk()->assertJsonPath('source', 'gemini')->assertJsonPath('presentation.actions.0.id', $expensive->id);
    Http::assertNothingSent();
});

test('unsupported price currency clarifies without generation or review', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    ChatbotUnderstandingAgent::fake([understood(['category_key' => 'processor', 'search_query' => 'Processor'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();
    $this->postJson('/chatbot', ['message' => 'processor under USD 500'])->assertOk()->assertJsonPath('source', 'fallback')
        ->assertJson(fn ($json) => $json->where('message', fn (string $text): bool => str_contains($text, 'PHP'))->etc());
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
});
