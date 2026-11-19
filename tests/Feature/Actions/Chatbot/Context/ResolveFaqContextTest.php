<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Facades\DB;

test('returns only relevant active FAQ knowledge', function () {
    $activeFaq = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How do I search for products?',
        'response_template' => 'Approved search guidance.',
        'priority' => 20,
    ]);
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How do I search for products online?',
        'response_template' => 'Inactive guidance.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product,
        'question_pattern' => 'How do I search for products?',
        'response_template' => 'Wrong category.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'Can I choose pickup or delivery?',
        'response_template' => 'Unrelated guidance.',
        'priority' => 100,
    ]);

    $context = (new ResolveFaqContext)->execute('How can I search products?');

    expect($context)->toBe([
        'knowledge' => [[
            'question_pattern' => $activeFaq->question_pattern,
            'response_template' => 'Approved search guidance.',
        ]],
    ]);
});

test('resolves seeded order navigation as managed FAQ guidance', function () {
    $this->seed([
        BranchSeeder::class,
        DevelopmentChatbotKnowledgeSeeder::class,
    ]);

    $context = (new ResolveFaqContext)->execute('How can I check my order status?');

    expect($context['knowledge'][0])->toBe([
        'question_pattern' => 'How can I check my order status?',
        'response_template' => 'Sign in and open your order history to view the latest status for your order.',
    ]);
});

test('selects the exact FAQ over broader matches and ignores priority', function () {
    $mostRelevant = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How do I search for products?',
        'response_template' => 'Most relevant.',
        'priority' => 0,
    ]);
    $secondMostRelevant = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How do I search the catalog?',
        'response_template' => 'Second most relevant.',
        'priority' => 10,
    ]);
    $higherPriority = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How can I search the catalog?',
        'response_template' => 'Higher priority.',
        'priority' => 100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'How do I search products online?',
        'response_template' => 'Lower priority.',
        'priority' => 50,
    ]);

    $context = (new ResolveFaqContext)->execute('How do I search for products?');

    expect(array_column($context['knowledge'], 'question_pattern'))->toBe([
        $mostRelevant->question_pattern,
    ]);
});

test('returns explicit empty FAQ context when no managed knowledge matches', function (string $message) {
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
    ]);

    $context = (new ResolveFaqContext)->execute($message);

    expect($context)->toBe(['knowledge' => []]);
})->with([
    'empty input' => ['  ...  '],
    'no relevant knowledge' => ['Do you provide technical training?'],
]);

test('routing and FAQ resolution share one lexical fetch', function () {
    $message = 'How do I search for products?';
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => $message,
        'response_template' => 'Approved search guidance.',
    ]);
    DB::enableQueryLog();

    $this->postJson('/chatbot', ['message' => $message])->assertOk()->assertJsonPath('message', 'Approved search guidance.');
    expect(collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'chatbot_knowledge') && str_contains($query['query'], 'translations')))->toHaveCount(1);
});
