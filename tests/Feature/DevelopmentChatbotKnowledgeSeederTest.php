<?php

use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Enums\ChatbotCategory;
use App\Enums\ChatbotQueryCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Str;

test('the default seed workflow creates approved chatbot FAQ scenarios', function () {
    $this->seed();
    $knowledge = ChatbotKnowledge::query()->get();
    $this->assertDatabaseCount('chatbot_knowledge', 9);
    expect($knowledge->every(fn ($record): bool => $record->category === ChatbotCategory::Faq && $record->is_active && $record->priority === 0))->toBeTrue();
    expect($knowledge->pluck('question_pattern')->all())->toBe([
        'How do I use PC Builder?', 'How do I select components in PC Builder?', 'How do saved PC builds work?', 'How do PC Builder templates work?', 'Where can I view PC Builder compatibility results?',
        'How can I check my order status?', 'Do you offer real-time delivery tracking?',
        'How do I search for products?', "How can I view a product's details?",
    ]);
});

test('rerunning the default seed workflow preserves administrator edits and adds no duplicates', function () {
    $this->seed();
    $record = ChatbotKnowledge::query()->where('question_pattern', 'How do I search for products?')->firstOrFail();
    $record->update(['response_template' => 'Edited search guidance.', 'priority' => -1, 'is_active' => false]);
    $this->seed();
    $this->assertDatabaseCount('chatbot_knowledge', 9);
    expect($record->refresh()->response_template)->toBe('Edited search guidance.')
        ->and($record->priority)->toBe(-1)->and($record->is_active)->toBeFalse();
});

test('seeded FAQs route to knowledge while referenced customer orders still use the order resolver', function () {
    $this->seed();
    $routing = app(RouteChatbotQuery::class);
    expect($routing->execute('How can I check my order status?')['category'])->toBe(ChatbotQueryCategory::Faq)
        ->and($routing->execute('Do you offer real-time delivery tracking?')['category'])->toBe(ChatbotQueryCategory::Faq)
        ->and($routing->execute('Is my GCash payment for BF-123 verified?')['category'])->toBe(ChatbotQueryCategory::Order)
        ->and(app(ResolveFaqContext::class)->execute('Do you offer real-time delivery tracking?')['knowledge'][0]['response_template'])
        ->toBe('Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.');
});

test('payment verification and method availability use application rules instead of seeded templates', function () {
    $this->seed();
    foreach (['How is GCash payment verified?', 'How is Maya payment verified?', 'Do I need payment proof for Maya?', 'How do staff verify cash payment?', 'How is card at store payment verified?', 'Do you accept GCash?'] as $question) {
        expect(app(MatchChatbotKnowledge::class)->execute($question))->toBe([]);
    }
    expect(app(ResolveChatbotRuleGuidance::class)->execute('payment_proof')['guidance'])->toContain('New online payments require no receipt or screenshot upload', 'Historical manual GCash and Maya', 'product handover together');
});

test('rerunning the FAQ seeder preserves legacy samples for migration or human review', function () {
    $original = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How is GCash payment verified?',
        'response_template' => 'GCash payment proof is reviewed manually by Battlefront staff. Check your order history for the updated payment status.',
        'priority' => 60,
    ]);
    $this->seed();
    $this->assertDatabaseCount('chatbot_knowledge', 10);
    expect($original->refresh()->question_pattern)->toBe('How is GCash payment verified?')
        ->and($original->is_active)->toBeTrue();
    expect(app(MatchChatbotKnowledge::class)->execute($original->question_pattern))->toBe([]);
});

test('seeded chatbot knowledge excludes unsupported policies and stale operational claims', function () {
    $this->seed();
    $seededText = Str::lower(ChatbotKnowledge::query()->get(['question_pattern', 'response_template'])
        ->flatMap(fn ($record): array => [$record->question_pattern, $record->response_template])->implode(' '));
    expect($seededText)->not->toContain('warranty', 'refund', 'return policy', 'repair service', 'delivery time', 'delivery fee', 'promotion', 'pricing policy', 'reservation expiry');
});

test('development chatbot knowledge is not seeded outside local and testing environments', function () {
    $originalEnvironment = app()->environment();
    try {
        app()->detectEnvironment(fn (): string => 'production');
        app(DevelopmentChatbotKnowledgeSeeder::class)->run();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
    $this->assertDatabaseCount('chatbot_knowledge', 0);
});
