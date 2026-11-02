<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;

test('clear catalog questions use three roles with the selected language', function (string $language) {
    config(['ai_integration.enabled' => true, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'product', 'intent' => 'product_price', 'language' => $language]])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => 'Atlas SSD PHP 2,000.', 'evidence_ids' => ['product:42']]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $result = app(ChatbotAiAdapter::class)->grounded('Atlas SSD price', ['products' => [['id' => 42, 'name' => 'Atlas SSD', 'price' => 2000]]], $language);

    expect($result['successful'])->toBeTrue();
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, $language));
})->with(['en', 'ceb', 'tl', 'hil']);

test('explicit language preferences survive subsequent English messages and can be changed', function () {
    $conversation = app(ChatbotConversation::class);
    $tokens = app(ChatbotConversationToken::class);
    $first = $conversation->respond('Reply in Hiligaynon', null, null, 'web:language-test');
    $second = $conversation->respond('What are your opening hours?', null, $first['context_token'], 'web:language-test');
    expect($tokens->decode($second['context_token'], 'web:language-test:guest')['language'])->toBe('hil');
    $third = $conversation->respond('Reply in Cebuano', null, $second['context_token'], 'web:language-test');
    expect($tokens->decode($third['context_token'], 'web:language-test:guest')['language'])->toBe('ceb');
});

test('compatibility explanations retain authoritative uncertainty through three stages', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.advice_enabled' => true, 'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 30000]);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'product', 'intent' => 'component_compatibility', 'language' => 'hil']])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake([['answer' => '[unknown] Indi pa makumpirma ang compatibility kay kulang ang reviewed nga ebidensya.', 'evidence_ids' => ['compatibility:summary']]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();
    $context = ['compatibility' => ['status' => 'unknown', 'complete' => false, 'findings' => [['status' => 'unknown', 'rule' => 'cpu.bios', 'message' => 'BIOS evidence is missing.']]]];

    $response = app(ChatbotAiAdapter::class)->grounded('compatible CPU motherboard', $context, 'hil');

    expect($response['successful'])->toBeTrue();
    ChatbotIntentAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertPromptedTimes(1);
    ChatbotValidationAgent::assertPromptedTimes(1);
});

test('model approval cannot erase an unknown compatibility finding', function () {
    config(['ai_integration.gemini.max_calls' => 2]);
    ChatbotEvidenceAgent::fake([['answer' => 'These parts will work.', 'evidence_ids' => ['compatibility:summary']]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();

    $response = app(ChatbotAiAdapter::class)->grounded('compatible CPU motherboard', ['compatibility' => ['status' => 'unknown', 'complete' => false]]);

    expect($response['successful'])->toBeFalse();
    ChatbotValidationAgent::assertNeverPrompted();
});
