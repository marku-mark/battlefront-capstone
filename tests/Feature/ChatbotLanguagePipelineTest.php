<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

test('model led multilingual interpretation preserves exclusions budget facts and reply language', function (string $question, string $language, string $answer) {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.localization_enabled' => true,
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000, 'scout.driver' => 'null']);
    $category = Category::factory()->create(['name' => 'Graphics Card']);
    $wanted = Product::factory()->available()->for($category)->create(['name' => 'Atlas GPU', 'brand' => 'AMD', 'price' => '4000.00', 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Intel GPU', 'brand' => 'Intel', 'price' => '3000.00', 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Expensive GPU', 'brand' => 'AMD', 'price' => '6000.00', 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([[
        'outcome' => 'supported', 'intent' => 'discovery', 'language' => $language, 'category_key' => 'graphics', 'search_query' => 'GPU',
        'product_mentions' => [], 'attributes' => ['stock'], 'maximum_php' => 5000, 'maximum_inclusive' => false,
        'excluded_brands' => ['Intel'], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new', 'position' => 0, 'clarification' => 'none',
    ]])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => $answer, 'evidence_ids' => ['product:'.$wanted->id]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => $question])->assertOk()->assertJsonPath('language', $language)->assertJsonPath('source', 'gemini');

    expect($response->json('message'))->toBe($answer);
    expect(array_column($response->json('presentation.actions'), 'id'))->toContain($wanted->id);
    ChatbotUnderstandingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, $question)
        && str_contains($prompt->agent->instructions(), 'Examples below')
        && ! str_contains($prompt->prompt, 'customer_id'));
    ChatbotEvidenceAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Atlas GPU')
        && ! str_contains($prompt->prompt, 'Expensive GPU') && ! str_contains($prompt->prompt, 'Intel GPU'));
    ChatbotValidationAgent::assertPromptedTimes(1);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(3);
    Http::assertNothingSent();
})->with([
    ['Any GPU in stock below PHP 5k, excluding Intel?', 'en', 'Atlas GPU — PHP 4,000.00.'],
    ['Meron bang GPU below PHP 5k pero huwag Intel?', 'tl', 'May Atlas GPU — PHP 4,000.00.'],
    ['Naa pa bay GPU ubos sa PHP 5k pero dili Intel?', 'ceb', 'Naa ang Atlas GPU — PHP 4,000.00.'],
    ['May ara GPU nga indi Intel kag mas manubo sa PHP 5k?', 'hil', 'May ara Atlas GPU — PHP 4,000.00.'],
]);

test('invalid interpreter output logs only safe failure metadata and skips generation', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.max_calls' => 3,
        'ai_integration.gemini.tokens_per_minute' => 60000]);
    Log::spy();
    ChatbotUnderstandingAgent::fake([['outcome' => 'not-an-allowed-outcome']])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Meron po kayo motherboard?'])->assertOk()->assertJsonPath('source', 'fallback');

    Log::shouldHaveReceived('warning')->once()->with('Chatbot provider attempt failed.', Mockery::on(fn (array $context): bool => $context['failure'] === 'understanding_invalid_output'
        && array_keys($context) === ['provider', 'model', 'duration_ms', 'timeout_seconds', 'failure', 'http_status', 'exception_class']));
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('interpreter failure never starts generation or repeats the provider request', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.max_calls' => 3,
        'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake(fn () => throw new AiException('synthetic provider failure'))->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Naa pa moy motherboard?'])->assertOk()->assertJsonPath('source', 'fallback')->assertJsonPath('language', 'ceb')
        ->assertJsonPath('message', 'The chatbot is temporarily unavailable. Please try again later.');

    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('interpreter examples count toward the input budget before a provider request', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.gemini.max_input_bytes' => 2000]);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();

    $this->postJson('/chatbot', ['message' => 'Motherboard available?'])->assertOk()->assertJsonPath('source', 'fallback');

    ChatbotUnderstandingAgent::assertNeverPrompted();
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(0);
    Http::assertNothingSent();
});

test('evaluation reports which checks failed instead of only a combined result', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('language-cases.json', json_encode([[
        'id' => 'synthetic-failure', 'language' => 'tl', 'messages' => ['Where is my order?'],
        'expected_category' => 'product', 'expected_attributes' => ['price'], 'contains' => ['not-present'], 'not_contains' => ['Please sign in'],
    ]]));

    $exitCode = Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('language-cases.json')]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1);
    expect($report['results'][0]['checks'])->toBe([
        'language' => false, 'routing' => false, 'attributes' => false, 'clarification' => null, 'required_text' => false, 'forbidden_text' => false,
    ]);
    Http::assertNothingSent();
});

test('the separate language corpus runs offline with explicit human review gaps', function () {
    config(['ai_integration.localization_enabled' => true, 'scout.driver' => 'null']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Motherboard']))
        ->create(['name' => 'BIOSTAR H610M Motherboard', 'price' => '3600.00', 'discount_price' => null]);
    $empty = Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD']);
    $empty->inventory->update(['quantity' => 0]);
    Product::factory()->available()->create(['name' => 'Unrelated Webcam']);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();

    $exitCode = Artisan::call('chatbot:evaluate', ['--dataset' => resource_path('data/chatbot-language-evaluation.json')]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values(array_filter($report['results'], fn (array $case): bool => ! $case['passed'])))->toBe([]);
    expect($exitCode)->toBe(0);
    foreach ($report['summary'] as $language => $metrics) {
        expect($metrics['cases'])->toBe(4);
        expect($metrics['pass_rate'])->toBe(1);
        expect($metrics['average_language_score'])->toBeNull();
        expect($metrics['release_gate_passed'])->toBeFalse();
    }
    foreach ($report['results'] as $case) {
        foreach ($case['turns'] as $turn) {
            expect($turn['usage']['calls'])->toBe(0);
        }
    }
    ChatbotUnderstandingAgent::assertNeverPrompted();
    ChatbotEvidenceAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});
