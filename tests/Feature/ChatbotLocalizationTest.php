<?php

use App\Ai\Agents\ChatbotIntentAgent;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotLocalizedResponse;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;

test('private support refusals match the customer language without sending provider requests', function (string $message, string $expected) {
    config(['ai_integration.localization_enabled' => true]);
    $result = app(ChatbotConversation::class)->respond($message, null, null, 'web:localized');
    expect($result['message'])->toContain($expected);
    expect($result['source'])->toBe('fallback');
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    ['Where is my order? Reply in English.', 'Please sign in'],
    ['Asa na ang akong order?', 'Palihog sign in'],
    ['Nasaan ang aking order?', 'Mag-sign in'],
    ['Diin na ang akon order?', 'Palihog mag-sign in'],
]);

test('localized facts preserve exact monetary values and references', function (string $language) {
    config(['ai_integration.localization_enabled' => true]);
    $text = app(ChatbotLocalizedResponse::class)->text('BF000041: Order status: Pending. Atlas SSD. Listed price: PHP 2,500.00. Currently out of stock at Sagay.', $language);
    expect($text)->toContain('BF000041', 'Pending', 'Atlas SSD', 'PHP 2,500.00')->not->toContain('Currently out of stock');
})->with(['ceb', 'tl', 'hil']);

test('web responses expose the selected language without changing the response source contract', function () {
    config(['ai_integration.localization_enabled' => true]);
    $this->postJson('/chatbot', ['message' => 'Diin na ang akon order?'])
        ->assertOk()->assertJsonPath('language', 'hil')->assertJsonPath('source', 'fallback')
        ->assertJsonPath('message', 'Palihog mag-sign in gamit ang customer account agod makita ang status sang order.');
});

test('mixed language detection follows the dominant regional phrasing and preserves ties', function () {
    $language = app(ChatbotLanguage::class);
    expect($language->select('Diin ang akon order kag meron stock?', 'en'))->toBe('hil');
    expect($language->select('kag meron', 'ceb'))->toBe('ceb');
});

test('custom translations are bound to the exact current approved answer', function () {
    config(['ai_integration.localization_enabled' => true, 'ai_integration.localized_answers.'.hash('sha256', 'Approved answer').'.hil' => 'Aprobado nga sabat']);
    expect(app(ChatbotLocalizedResponse::class)->text('Approved answer', 'hil'))->toBe('Aprobado nga sabat');
    expect(app(ChatbotLocalizedResponse::class)->text('Changed approved answer', 'hil'))->not->toBe('Aprobado nga sabat');
});

test('provider circuit opens after repeated failures and recovers after the cooldown', function () {
    config(['ai_integration.gemini.project_budget_key' => 'circuit-test']);
    $budget = app(ChatbotProviderBudget::class);
    $budget->recordFailure();
    $budget->recordFailure();
    $budget->recordFailure();
    expect($budget->reserve('catalog'))->toBeFalse();
    $this->travel(61)->seconds();
    $budget->reset();
    expect($budget->reserve('catalog'))->toBeTrue();
});

test('concurrent turns share capacity and release it for subsequent requests', function () {
    config(['ai_integration.gemini.max_concurrent_turns' => 1, 'ai_integration.gemini.project_budget_key' => 'concurrency-test']);
    $first = new ChatbotProviderBudget;
    $second = new ChatbotProviderBudget;
    expect($first->reserve('catalog'))->toBeTrue();
    expect($second->reserve('catalog'))->toBeFalse();
    $first->releaseTurn();
    expect($second->reserve('catalog'))->toBeTrue();
    $second->releaseTurn();
});

test('regional catalog intent survives natural phrasing and punctuation', function (string $message, string $expected) {
    expect(app(ChatbotLanguage::class)->canonical($message))->toContain($expected);
})->with(function () {
    $phrases = [
        'en' => ['recommend GPU' => 'recommend', 'GPU under 15000' => 'under', 'cheaper GPU' => 'cheaper', 'GPU not Intel' => 'not Intel', 'GPU for editing' => 'for'],
        'ceb' => ['rekomendahi GPU' => 'recommend', 'GPU dili molapas sa 15000' => 'under', 'mas barato GPU' => 'cheaper', 'GPU ayaw ug Intel' => 'not Intel', 'GPU alang sa editing' => 'for'],
        'tl' => ['irekomenda GPU' => 'recommend', 'GPU hindi lalampas sa 15000' => 'under', 'mas mura GPU' => 'cheaper', 'GPU huwag ng Intel' => 'not Intel', 'GPU para sa editing' => 'for'],
        'hil' => ['ma suggest GPU' => 'recommend', 'GPU indi maglapaw sa 15000' => 'under', 'mas barato GPU' => 'cheaper', 'GPU indi sang Intel' => 'not Intel', 'GPU para sa editing' => 'for'],
    ];
    foreach ($phrases as $language => $cases) {
        foreach ($cases as $message => $expected) {
            foreach (['', 'Please ', 'Battlefront ', '  ', 'Hello, '] as $prefix) {
                foreach (['?', '!'] as $suffix) {
                    yield $language.' '.json_encode([$prefix, $message, $suffix]) => [$prefix.$message.$suffix, $expected];
                }
            }
        }
    }
});
