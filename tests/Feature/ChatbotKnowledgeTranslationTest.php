<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;

test('administrators can save and serve a reviewed custom FAQ in the requested language', function (string $language, string $question, string $answer) {
    config(['ai_integration.localization_enabled' => true]);
    $administrator = User::factory()->administrator()->create();
    $sourceQuestion = 'How do I search for products?';
    $sourceAnswer = 'Use catalog search and filter by brand.';
    $this->actingAs($administrator)->post(route('administration.chatbot-knowledge.store'), [
        'question_pattern' => $sourceQuestion, 'response_template' => $sourceAnswer,
        'translations' => [$language => ['question_pattern' => $question, 'response_template' => $answer, 'reviewed' => '1', 'source_question' => $sourceQuestion, 'source_answer' => $sourceAnswer]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $record = ChatbotKnowledge::query()->sole();
    expect($record->reviewedTranslation($language)['response_template'])->toBe($answer);
    $result = app(ResolveFaqContext::class)->execute($question, $language);
    expect($result['knowledge'][0]['response_template'])->toBe($answer);
    $response = app(ChatbotConversation::class)->respond($question.' Reply in '.['ceb' => 'Cebuano', 'tl' => 'Tagalog', 'hil' => 'Hiligaynon'][$language], null, null, 'web:translations');
    expect($response['message'])->toBe($answer);
})->with([
    ['ceb', 'Unsaon mangita sa katalogo?', 'Gamita ang search sa katalogo ug pagpili og brand.'],
    ['tl', 'Paano maghanap sa katalogo?', 'Gamitin ang search sa katalogo at pumili ng brand.'],
    ['hil', 'Paano mangita sa katalogo?', 'Gamita ang search sa katalogo kag magpili sang brand.'],
]);

test('source edits immediately invalidate previously reviewed translations', function () {
    config(['ai_integration.localization_enabled' => true]);
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => 'Original guidance.', 'translations' => ['hil' => ['question_pattern' => 'Paano mangita sa katalogo?', 'response_template' => 'Orihinal nga sabat.', 'reviewed' => true, 'source_question' => 'How do I search for products?', 'source_answer' => 'Original guidance.']]]);
    $record->update(['response_template' => 'Changed guidance.']);
    expect($record->fresh()->reviewedTranslation('hil'))->toBeNull();
    $result = app(ResolveFaqContext::class)->execute('How do I search for products?', 'hil');
    expect($result['knowledge'][0]['response_template'])->not->toContain('Orihinal', 'Changed guidance.');
});

test('stale review attestations and unsupported languages are rejected', function () {
    $this->actingAs(User::factory()->administrator()->create())->post(route('administration.chatbot-knowledge.store'), [
        'question_pattern' => 'How do I search for products?', 'response_template' => 'New guidance.',
        'translations' => ['hil' => ['question_pattern' => 'Paano mangita?', 'response_template' => 'Gamita ang search.', 'reviewed' => true, 'source_question' => 'Old question', 'source_answer' => 'Old answer']],
    ])->assertSessionHasErrors('translations.hil.reviewed');
    $this->assertDatabaseCount('chatbot_knowledge', 0);
});

test('customers cannot approve or save FAQ translations', function () {
    $this->actingAs(User::factory()->create())->post(route('administration.chatbot-knowledge.store'), ['question_pattern' => 'How do I search for products?', 'response_template' => 'Use search.', 'translations' => []])->assertForbidden();
});

test('fallback localization preserves literal catalog names that resemble application labels', function () {
    config(['ai_integration.localization_enabled' => true, 'ai_integration.offline' => true]);
    Product::factory()->available()->create(['name' => 'Phone: Labs SSD', 'price' => 2500, 'discount_price' => null]);
    $response = app(ChatbotConversation::class)->respond('Magkano ang Phone: Labs SSD?', null, null, 'web:literal-product');
    expect($response['message'])->toContain('Phone: Labs SSD', 'Presyo: PHP 2,500.00');
});

test('regional PC Builder navigation and stock reservation followups retain the approved FAQ context', function (string $message, string $followUp, string $language) {
    config(['ai_integration.localization_enabled' => true]);
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    $chatbot = app(ChatbotConversation::class);
    $first = $chatbot->respond($message, null, null, 'web:faq-context');
    expect($first['message'])->toContain('public link');
    $second = $chatbot->respond($followUp, null, $first['context_token'], 'web:faq-context');
    expect($second['message'])->toContain('public link', 'stock');
    expect($second['language'])->toBe($language);
})->with([
    ['Unsaon pag-save og PC build? Reply in Cebuano.', 'Mareserba ba ang stock kung i-save?', 'ceb'],
    ['Paano mag-save ng PC build? Reply in Tagalog.', 'Nagrereserba ba ito ng stock?', 'tl'],
    ['Paano mag-save sang PC build? Reply in Hiligaynon.', 'Mareserba bala ang stock kon i-save?', 'hil'],
]);
