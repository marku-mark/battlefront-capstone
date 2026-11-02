<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use Illuminate\Support\Facades\Http;

test('multiple catalog matches receive a reviewed conversational comparison', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.gemini.max_calls' => 2, 'ai_integration.gemini.tokens_per_minute' => 20000, 'ai_integration.gemini.response_timeout_seconds' => 28, 'ai_integration.gemini.validation_timeout_seconds' => 18]);
    $category = Category::factory()->create(['name' => 'Storage']);
    $first = Product::factory()->for($category)->create(['name' => 'Atlas SSD', 'price' => 2000, 'discount_price' => null]);
    $second = Product::factory()->for($category)->create(['name' => 'Orion SSD', 'price' => 3000, 'discount_price' => null]);
    Inventory::factory()->for($first)->create(['quantity' => 10]);
    Inventory::factory()->for($second)->create(['quantity' => 10]);
    $answer = 'Atlas SSD is PHP 2,000 and Orion SSD is PHP 3,000. Atlas SSD has the lower listed price. What capacity do you need?';
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$first->id, 'product:'.$second->id], 'answer' => $answer]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Compare Atlas SSD versus Orion SSD prices'])
        ->assertOk()->assertJsonPath('message', $answer)->assertJsonPath('source', 'gemini');

    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertPrompted(fn ($prompt) => $prompt->timeout === 28);
    ChatbotValidationAgent::assertPrompted(fn ($prompt) => $prompt->timeout === 18);
    Http::assertNothingSent();
});

test('natural answers cannot bypass numeric anchors or the required reviewer', function (string $answer, string $verdict, int $calls, int $reviewCalls) {
    config(['ai_integration.gemini.max_calls' => $calls]);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:42'], 'answer' => $answer]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => $verdict]])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->grounded('price', ['products' => [[
        'id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00', 'inventory' => ['status' => 'in_stock'],
    ]]]);

    expect($result['successful'])->toBeFalse();
    expect($result['text'])->toBeNull();
    ChatbotValidationAgent::assertPromptedTimes($reviewCalls);
    Http::assertNothingSent();
})->with([
    'invented price' => ['Atlas SSD costs PHP 1,999.', 'approve', 2, 0],
    'unsupported performance' => ['Atlas SSD is the fastest drive.', 'reject', 2, 1],
    'review budget unavailable' => ['Atlas SSD costs PHP 2,000.', 'approve', 1, 0],
    'compatibility claim' => ['Atlas SSD is compatible with your motherboard.', 'approve', 2, 0],
    'untrusted markup' => ['<script>alert(1)</script>', 'approve', 2, 0],
]);

test('language focus and explicit catalog constraints reach the model without personal text', function () {
    config(['ai_integration.gemini.max_calls' => 2]);
    $answer = 'Ang Atlas SSD ay PHP 2,000. Anong kapasidad ang kailangan mo?';
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:42'], 'answer' => $answer]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->grounded('Recommend SSD under PHP 5000, not Intel. My name is Maria Santos.', ['products' => [[
        'id' => 42, 'name' => 'Atlas SSD', 'price' => '2000.00', 'inventory' => ['status' => 'in_stock'],
    ]]], 'tl');

    expect($result['successful'])->toBeTrue();
    ChatbotEvidenceAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"language":"tl"')
        && str_contains($prompt->prompt, '5000') && str_contains($prompt->prompt, 'intel')
        && ! str_contains($prompt->prompt, 'Maria') && ! str_contains($prompt->prompt, 'Santos'));
    Http::assertNothingSent();
});

test('recommendations use budget eligible alternatives and explain only supplied catalog attributes', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true, 'ai_integration.gemini.max_calls' => 2, 'ai_integration.gemini.tokens_per_minute' => 20000]);
    $category = Category::factory()->create(['name' => 'Graphics Cards']);
    $product = Product::factory()->for($category)->create(['name' => 'Atlas GPU', 'brand' => 'AMD', 'price' => 12000, 'discount_price' => null, 'description' => 'Dual fan cooling.']);
    $excluded = Product::factory()->for($category)->create(['name' => 'Intel GPU', 'brand' => 'Intel', 'price' => 9000, 'discount_price' => null]);
    $expensive = Product::factory()->for($category)->create(['name' => 'Orion GPU', 'price' => 25000, 'discount_price' => null]);
    foreach ([$product, $excluded, $expensive] as $item) {
        Inventory::factory()->for($item)->create(['quantity' => 10]);
    }
    $answer = 'Atlas GPU is PHP 12,000 and its catalog description lists dual fan cooling. I cannot infer gaming performance from that alone. Which games and monitor resolution will you use?';
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$product->id], 'answer' => $answer]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Recommend gaming GPU under PHP 15000, not Intel'])
        ->assertOk()->assertJsonPath('message', $answer)->assertJsonPath('source', 'gemini');

    ChatbotEvidenceAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Dual fan cooling')
        && ! str_contains($prompt->prompt, 'Intel GPU') && ! str_contains($prompt->prompt, 'Orion GPU'));
    Http::assertNothingSent();
});

test('unclear selection requests use scope generation and review within the three role budget', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    $product = Product::factory()->available()->create(['name' => 'Atlas GPU', 'price' => 12000, 'discount_price' => null]);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'product', 'intent' => 'catalog_recommendation', 'language' => 'en']])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:'.$product->id], 'answer' => 'Atlas GPU is PHP 12,000. What will you use it for?']])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Help me choose GPU under PHP 15000'])
        ->assertOk()->assertJsonPath('source', 'gemini')->assertJsonPath('message', 'Atlas GPU is PHP 12,000. What will you use it for?');

    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('server checks reject swapped product prices before model review', function () {
    config(['ai_integration.gemini.max_calls' => 2, 'ai_integration.gemini.tokens_per_minute' => 20000]);
    ChatbotEvidenceAgent::fake([['evidence_ids' => ['product:1', 'product:2'], 'answer' => 'Atlas SSD is PHP 3,000 and Orion SSD is PHP 2,000.']])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'reject']])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->grounded('compare prices', ['products' => [
        ['id' => 1, 'name' => 'Atlas SSD', 'price' => '2000.00'],
        ['id' => 2, 'name' => 'Orion SSD', 'price' => '3000.00'],
    ]]);

    expect($result['successful'])->toBeFalse();
    expect($result['text'])->toBeNull();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});
