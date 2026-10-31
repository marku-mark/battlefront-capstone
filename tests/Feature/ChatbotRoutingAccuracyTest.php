<?php

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Enums\ChatbotQueryCategory;
use App\Models\ChatbotKnowledge;
use App\Models\Order;
use App\Models\User;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Support\Facades\Http;

test('payment aliases use application rules instead of searching saved answer text', function (string $message) {
    $knowledge = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
        'response_template' => 'Pickup supports GCash and Maya.',
    ]);
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => $message,
        'response_template' => 'Inactive.',
    ]);

    $context = app(ResolveFaqContext::class)->execute($message);

    expect($context['knowledge'])->toBe([])
        ->and(app(ClassifyChatbotInquiry::class)->execute($message)['rule_topic'])->toBe('payment_methods');
})->with(['Do you accept GCash?', 'Can I pay cash?', 'Do you accept Maya?', 'How do I pay for my order?']);

test('saved FAQs are reachable but do not bypass personal orders or unsupported restrictions', function (string $message, ChatbotQueryCategory $category) {
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => $message,
    ]);

    expect(app(RouteChatbotQuery::class)->execute($message)['category'])->toBe($category);
})->with([
    ['What is your warranty policy?', ChatbotQueryCategory::Faq],
    ['Is my payment verified?', ChatbotQueryCategory::Order],
    ['Payment for BF-12?', ChatbotQueryCategory::Order],
    ['Recommend a laptop', ChatbotQueryCategory::Unsupported],
    ['Tell me a joke', ChatbotQueryCategory::Unsupported],
    ['What is the price of Aurelius Mouse?', ChatbotQueryCategory::Product],
    ['Where is Sagay branch located?', ChatbotQueryCategory::Store],
]);

test('stock at a reference only branch is not answered with Sagay inventory', function (string $message) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond($message);

    expect($result['source'])->toBe('fallback')->and($result['message'])->toContain('Sagay branch only');
    ChatbotResponseAgent::assertNeverPrompted();
})->with(['Is Aurelius Mouse available at San Carlos?', 'Is Aurelius Mouse availble at San-Carlos?']);

test('inactive exact questions and substring collisions do not match knowledge', function () {
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'What is your warranty policy?',
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'Carpet care',
    ]);

    expect(app(RouteChatbotQuery::class)->execute('What is your warranty policy?')['category'])->toBe(ChatbotQueryCategory::Unsupported)
        ->and(app(ResolveFaqContext::class)->execute('car'))->toBe(['knowledge' => []]);
});

test('customers must identify an order for personal payment status', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    Order::factory()->for($customer)->create();

    $result = app(ChatbotOrchestrationService::class)->respond('Is my payment verified?', $customer);

    expect($result['source'])->toBe('fallback')
        ->and($result['message'])->toContain('BF order reference');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('general payment guidance is available to guests without resolving their orders', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Approved payment guidance.'])->preventStrayPrompts();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond('How do I pay for my order?');

    expect($result['category'])->toBe(ChatbotQueryCategory::Faq)
        ->and($result['source'])->toBe('fallback')
        ->and($result['message'])->toBe('Pickup orders support Cash (Pay at Pickup), Debit/Credit Card (Pay at Store), Pay online. Delivery orders support Cash on Delivery (COD), Pay online. Pay online uses PayMongo Hosted Checkout for QR Ph, online card, GCash, Maya. New online payments require no receipt or screenshot upload. Confirmation comes from authenticated server processing; a browser return is not proof of payment. Use Continue payment in your saved order when available. Development transactions are test mode only. Route restrictions still apply; use the options offered at checkout. Direct online banking is not offered for new orders.');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('mixed requests clarify but branch qualifiers and payment alternatives remain a single topic', function (string $message, int $choices) {
    expect(app(RouteChatbotQuery::class)->execute($message)['choices'])->toHaveCount($choices);
})->with([
    ['What is the delivery fee and laptop price?', 2],
    ['Where is the store and what is my order status?', 2],
    ['Is the laptop available at Sagay?', 0],
    ['Do you accept GCash and Maya?', 0],
]);

test('saved FAQ clauses participate in mixed topic clarification without losing repeated topics', function () {
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What is your warranty policy?',
    ]);

    $routing = app(RouteChatbotQuery::class)->execute('What is your warranty policy? And laptop price and delivery options?');

    expect($routing['choices'])->toHaveCount(2)
        ->and($routing['choices']['faq'])->toContain('warranty policy', 'delivery options')
        ->and($routing['choices']['product'])->toBe('laptop price');
});
