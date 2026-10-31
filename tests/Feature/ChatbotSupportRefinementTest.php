<?php

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\Context\ResolveChatbotRuleGuidance;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\ChatbotKnowledge;
use App\Models\User;
use App\Services\Chatbot\ChatbotOrchestrationService;
use App\Services\Order\CheckoutPaymentOptions;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Facades\Http;

test('payment guidance follows checkout configuration without exposing disabled methods', function () {
    config(['services.paymongo.enabled' => true, 'services.paymongo.hosted_methods' => ['gcash']]);
    $options = app(CheckoutPaymentOptions::class);
    expect(array_column($options->methods(), 'value'))->toBe(['cash', 'card_at_store', 'cod', 'online']);
    $guidance = app(ResolveChatbotRuleGuidance::class)->execute('payment_methods')['guidance'];
    expect($guidance)->toContain('Hosted Checkout for GCash.', 'Direct online banking is not offered')
        ->not->toContain('Hosted Checkout for QR Ph', 'GCash, Maya');
    config(['services.paymongo.hosted_methods' => []]);
    expect(array_column($options->methods(), 'value'))->not->toContain('online');
    expect(app(ResolveChatbotRuleGuidance::class)->execute('payment_methods')['guidance'])->toContain('not currently offered');
    config(['services.paymongo.enabled' => false]);
    expect(array_column($options->methods(), 'value'))->toBe(['cash', 'card_at_store', 'gcash', 'maya', 'cod']);
    expect(app(ResolveChatbotRuleGuidance::class)->execute('payment_proof')['guidance'])->toContain('manual wallet payment', 'collection alone does not complete payment');
});

test('seeded builder help answers directly and preserves inactive edited FAQs', function () {
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    $question = 'Where can I view PC Builder compatibility results?';
    $chat = app(ChatbotOrchestrationService::class);
    $result = $chat->respond($question);
    expect($result['source'])->toBe('fallback');
    expect($result['message'])->toContain('Unknown or draft evidence is not verified compatibility');
    $faq = ChatbotKnowledge::where('question_pattern', $question)->sole();
    $faq->update(['response_template' => 'Edited builder instructions.', 'is_active' => false]);
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    expect($faq->refresh()->response_template)->toBe('Edited builder instructions.');
    expect($chat->respond($question)['message'])->toContain('currently unavailable');
    $this->assertDatabaseCount('chatbot_knowledge', 9);
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('support questions route consistently without granting compatibility advice', function (string $question, string $owner) {
    expect(app(ClassifyChatbotInquiry::class)->execute($question)['owner'])->toBe($owner);
})->with([
    ['How do I use PC Builder?', 'managed_faq'],
    ['How can I select components in PC Builder?', 'managed_faq'],
    ['How do saved PC builds work?', 'managed_faq'],
    ['How do PC Builder templates work?', 'managed_faq'],
    ['Where can I see PC Builder compatibility results?', 'managed_faq'],
    ['Are Ryzen 5 and this motherboard compatible?', 'unsupported'],
    ['Where can I view PC Builder compatibility results and recommend parts?', 'unsupported'],
    ['What payment methods are available?', 'application_rules'],
    ['Can I use QR Ph?', 'application_rules'],
    ['Do you offer online banking?', 'application_rules'],
]);

test('builder help works on web mobile and administration preview', function () {
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    $question = 'How do PC Builder templates work?';
    $web = $this->postJson(route('chatbot.store'), ['message' => $question])->assertOk();
    $api = $this->postJson(route('api.v1.chatbot.store'), ['message' => $question])->assertOk();
    expect($api->json('data.message'))->toBe($web->json('message'));
    $this->actingAs(User::factory()->administrator()->create())
        ->postJson(route('administration.chatbot-knowledge.preview'), ['message' => $question])
        ->assertOk()->assertJsonPath('eligible_for_faq', true)->assertJsonPath('owner', 'managed_faq');
});

test('cancellation and delivery guidance preserves financial boundaries', function () {
    $rules = app(ResolveChatbotRuleGuidance::class);
    expect($rules->execute('cancellation')['guidance'])->toContain('reconciled', 'confirmed closed', 'not an automatic refund');
    config(['battlefront.delivery.regional_estimates.fees.visayas' => '170.00']);
    expect($rules->execute('delivery_quote')['guidance'])->toContain('Visayas/NIR PHP 170.00', 'Missing regional pricing blocks', 'Only historical orders');
});

test('every quick question reaches its intended support answer without a provider call', function (string $question, string $answer) {
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    $result = app(ChatbotOrchestrationService::class)->respond($question);
    expect($result['message'])->toContain($answer);
    expect($result['source'])->toBe('fallback');
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotIntentAgent::assertNeverPrompted();
})->with([
    ['What payment methods can I use?', 'PayMongo Hosted Checkout'],
    ['How are delivery fees and delivery times estimated?', 'regional base fees'],
    ['How can I check my order status?', 'order history'],
    ['How do I cancel my order?', 'available cancellation action'],
    ['How do I request a return?', 'physical item receipt'],
    ['My return status', 'sign in'],
    ['My refund status', 'sign in'],
    ['How do I use PC Builder?', 'Open PC Builder'],
    ['How do I select components in PC Builder?', 'component category'],
    ['How do saved PC builds work?', 'does not reserve stock'],
    ['How can I share my PC build?', 'public link'],
    ['How can I save a PC build?', 'does not reserve stock'],
    ['Where can I open PC Builder?', 'Open PC Builder'],
    ['How can I load a PC Builder template?', 'predefined template'],
    ['How do PC Builder templates work?', 'predefined template'],
    ['Where can I view PC Builder compatibility results?', 'Unknown or draft'],
]);
