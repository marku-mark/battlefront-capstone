<?php

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

test('defense questions return the documented deterministic topic and guidance', function (string $question, ChatbotQueryCategory $category, ?string $rule, array $phrases) {
    Http::preventStrayRequests();
    $this->seed([DevelopmentChatbotKnowledgeSeeder::class, BranchSeeder::class]);

    $result = app(ChatbotOrchestrationService::class)->respond($question);

    expect($result['category'])->toBe($category);
    expect($result['source'])->toBe('fallback');
    expect($result['message'])->toContain(...$phrases);
    expect(app(ClassifyChatbotInquiry::class)->execute($question)['rule_topic'])->toBe($rule);
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'Q01 catalog search FAQ' => ['How do I search for products?', ChatbotQueryCategory::Faq, null, ['Open the product catalog and use search. You can narrow results by category, brand, or tag.']],
    'Q02 product details FAQ' => ["How can I view a product's details?", ChatbotQueryCategory::Faq, null, ['Open a product from the catalog to view its description, listed price, and availability.']],
    'Q03 order history FAQ' => ['How can I check my order status?', ChatbotQueryCategory::Faq, null, ['Sign in and open your order history to view the latest status for your order.']],
    'Q04 generic catalog' => ['What products do you have?', ChatbotQueryCategory::Product, null, ['Browse the Products catalog', 'current price or Sagay availability']],
    'Q05 payment methods' => ['How do I pay?', ChatbotQueryCategory::Faq, 'payment_methods', ['Pickup orders support Cash (Pay at Pickup), Debit/Credit Card (Pay at Store), Pay online.', 'Delivery orders support Cash on Delivery (COD), Pay online.']],
    'Q06 GCash' => ['Do you accept GCash?', ChatbotQueryCategory::Faq, 'payment_methods', ['Pickup orders support', 'Hosted Checkout for QR Ph, online card, GCash, Maya']],
    'Q07 payment proof' => ['Do I need payment proof for Maya?', ChatbotQueryCategory::Faq, 'payment_proof', ['New online payments require no receipt or screenshot upload', 'Historical manual GCash and Maya', 'COD is delivery-only and requires no upload']],
    'Q08 fulfillment' => ['Can I choose pickup or delivery?', ChatbotQueryCategory::Faq, 'fulfillment', ['Battlefront supports pickup and delivery.', 'a delivery address is required']],
    'Q09 delivery quote' => ['What is the delivery fee?', ChatbotQueryCategory::Faq, 'delivery_quote', ['regional base fees', 'demonstration estimates', 'Only historical orders retain saved zero-fee placeholders', 'without an automatic ETA or Shipment']],
    'Q10 tracking FAQ' => ['Do you offer real-time delivery tracking?', ChatbotQueryCategory::Faq, null, ['Real-time delivery tracking is not available. Sign in and check your order history for the latest order status.']],
    'Q11 physical returns' => ['Can I return my item?', ChatbotQueryCategory::Faq, 'returns', ['physical item receipt and staff review', 'does not guarantee acceptance or a refund', 'The chatbot cannot submit or approve a return.']],
    'Q12 cancellation' => ['How do I cancel an order?', ChatbotQueryCategory::Faq, 'cancellation', ['cancellation review request', 'approval is not automatic', 'separate from returning a received item']],
    'Q13 refunds' => ['How are refunds processed?', ChatbotQueryCategory::Faq, 'refunds', ['separate workflows', 'confirmed refund payments', 'No processing deadline can be confirmed']],
    'Q16 non-Sagay inventory scope' => ['May ara kamo Logitech mouse sa Bacolod?', ChatbotQueryCategory::Product, null, ['Sagay branch only', 'cannot confirm stock at the other branches']],
    'Q21 Tagalog payment' => ['Paano magbayad?', ChatbotQueryCategory::Faq, 'payment_methods', ['Pickup orders support', 'Delivery orders support']],
    'Q22 Cebuano payment' => ['Unsaon pagbayad?', ChatbotQueryCategory::Faq, 'payment_methods', ['Pickup orders support', 'Delivery orders support']],
    'Q23 Hiligaynon-requested delivery guidance' => ['Pila ang delivery fee?', ChatbotQueryCategory::Faq, 'delivery_quote', ['demonstration estimates', 'Only historical orders retain saved zero-fee placeholders']],
    'Q24 Hiligaynon location clarification' => ['Diin kamo dampi?', ChatbotQueryCategory::Store, null, ['Which branch do you mean?', 'Sagay City', 'San Carlos City']],
    'Q25 Cebuano location clarification' => ['Asa inyong tindahan?', ChatbotQueryCategory::Store, null, ['Which branch do you mean?', 'Sagay City', 'San Carlos City']],
    'Q26 Tagalog location clarification' => ['Saan kayo located?', ChatbotQueryCategory::Store, null, ['Which branch do you mean?', 'Sagay City', 'San Carlos City']],
    'Q27 localized physical return' => ['Pwede ibalik ang item?', ChatbotQueryCategory::Faq, 'returns', ['physical item receipt', 'The chatbot cannot submit or approve a return.']],
    'Q28 localized cancellation' => ['Paano i-cancel ang order?', ChatbotQueryCategory::Faq, 'cancellation', ['cancellation review request', 'The chatbot cannot cancel an order or submit a request.']],
    'Q38 unsupported chat' => ['Tell me a joke.', ChatbotQueryCategory::Unsupported, null, ['I can only help with Battlefront products']],
    'Q39 guest order boundary' => ['Where is my order?', ChatbotQueryCategory::Order, null, ['Please sign in with a customer account to check order status.']],
]);

test('defense product questions use current catalog facts and safe provider fallbacks', function (string $question, string $language, string $wording, string $fact, string $provider) {
    Http::preventStrayRequests();
    $product = Product::factory()->available()->create(['name' => 'RAPOO WIRED OPTICAL USB MOUSE N100', 'price' => '180.00', 'discount_price' => null, 'description' => null]);
    $product->inventory->update(['quantity' => 19]);
    ChatbotResponseAgent::fake($provider === 'generated' ? [$wording] : fn () => throw new AiException('Simulated unavailable provider'))->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond($question);

    expect($result['category'])->toBe(ChatbotQueryCategory::Product);
    expect($result['source'])->toBe($provider === 'generated' ? 'gemini' : 'fallback');
    expect($result['message'])->toContain($provider === 'generated' ? $wording : $fact);
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'RAPOO WIRED OPTICAL USB MOUSE N100')
        && str_contains($prompt->prompt, '"price":"180.00"')
        && str_contains($prompt->prompt, '"quantity":19')
        && str_contains($prompt->agent->instructions(), 'Reply in '.$language));
    ChatbotResponseAgent::assertPromptedTimes(1);
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'Q14 English price' => ['How much is the RAPOO WIRED OPTICAL USB MOUSE N100?', 'English', 'RAPOO WIRED OPTICAL USB MOUSE N100 costs PHP 180.00.', 'PHP 180.00'],
    'Q15 Sagay stock' => ['Is the RAPOO WIRED OPTICAL USB MOUSE N100 available?', 'English', 'RAPOO WIRED OPTICAL USB MOUSE N100 has 19 available at Sagay.', 'Sagay stock: 19 available.'],
    'Q29 explicit Hiligaynon request' => ['Magkano ang RAPOO WIRED OPTICAL USB MOUSE N100? Answer in Hiligaynon.', 'Hiligaynon', 'Ang presyo sang RAPOO WIRED OPTICAL USB MOUSE N100 amo ang PHP 180.00.', 'PHP 180.00'],
])->with(['generated', 'unavailable']);

test('defense branch questions use published seeded details without internal flags', function (string $question, string $fact, string $provider) {
    Http::preventStrayRequests();
    $this->seed(BranchSeeder::class);
    ChatbotResponseAgent::fake($provider === 'generated' ? [$fact] : fn () => throw new AiException('Simulated unavailable provider'))->preventStrayPrompts();

    $result = app(ChatbotOrchestrationService::class)->respond($question);

    expect($result['category'])->toBe(ChatbotQueryCategory::Store);
    expect($result['source'])->toBe($provider === 'generated' ? 'gemini' : 'fallback');
    expect($result['message'])->toContain($fact)->not->toContain('is_operational');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, $fact) && ! str_contains($prompt->prompt, 'is_operational'));
    ChatbotResponseAgent::assertPromptedTimes(1);
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'Q17 Sagay location' => ['Where is your Sagay branch?', 'beside LBC Sagay City'],
    'Q18 Sagay hours' => ['What are the Sagay branch opening hours?', '8:00 AM–6:00 PM'],
    'Q19 Sagay contact' => ['What is the Sagay branch contact number?', '0938 647 6046'],
    'Q20 Cebuano San Carlos location' => ['asa inyong tindahan sa san carlos branch?', 'Carmona St., Brgy. V, San Carlos City'],
])->with(['generated', 'unavailable']);

test('defense mixed-topic sequence retains the choices until returns is selected', function () {
    Http::preventStrayRequests();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('How do I pay and can I return an item?', null, null, 'defense');
    $second = $chat->respond('Oo', null, $first['context_token'], 'defense');
    $third = $chat->respond('Returns', null, $second['context_token'], 'defense');

    expect($first['message'])->toContain('payment guidance', 'return guidance');
    expect($second['message'])->toBe($first['message']);
    expect($third['message'])->toContain('physical item receipt')->not->toContain('Pickup orders support');
    ChatbotIntentAgent::assertNeverPrompted();
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('defense product clarification and language switch keep the original price intent', function () {
    Http::preventStrayRequests();
    Product::factory()->available()->create(['name' => 'RAPOO MOUSE AND KEYBOARD BUNDLE NX1600', 'price' => '650.00', 'discount_price' => null]);
    Product::factory()->available()->create(['name' => 'RAPOO WIRED OPTICAL USB MOUSE N100', 'price' => '180.00', 'discount_price' => null]);
    ChatbotResponseAgent::fake(fn () => throw new AiException('Simulated unavailable provider'))->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Tagpila inyo Rapoo mouse?', null, null, 'defense');
    $second = $chat->respond('Huo', null, $first['context_token'], 'defense');
    ChatbotResponseAgent::assertNeverPrompted();
    $selected = $chat->respond('N100', null, $second['context_token'], 'defense');
    $switch = $chat->respond('Answer in Hiligaynon.', null, $selected['context_token'], 'defense');

    expect($first['message'])->toContain('Which product', 'NX1600', 'N100');
    expect($second['message'])->toContain('Which product', 'NX1600', 'N100');
    expect($selected['message'])->toContain('RAPOO WIRED OPTICAL USB MOUSE N100', 'PHP 180.00')->not->toContain('NX1600');
    expect($switch['message'])->toContain('Approved guidance and fallback messages remain in English.');
    ChatbotResponseAgent::assertPromptedTimes(1);
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('defense branch follow up uses the remembered branch and current hours', function () {
    Http::preventStrayRequests();
    $this->seed(BranchSeeder::class);
    ChatbotResponseAgent::fake(fn () => throw new AiException('Simulated unavailable provider'))->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Where is your Sagay branch?', null, null, 'defense');
    $followUp = $chat->respond('What are its opening hours?', null, $first['context_token'], 'defense');

    expect($first['message'])->toContain('beside LBC Sagay City');
    expect($followUp['message'])->toContain('Sagay City', 'Operating hours: 8:00 AM–6:00 PM.');
    ChatbotResponseAgent::assertPromptedTimes(2);
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('defense authenticated order question still requires an owned reference', function () {
    Http::preventStrayRequests();
    $customer = User::factory()->customer()->create();

    $result = app(ChatbotOrchestrationService::class)->respond('Diin na ang akon order?', $customer);

    expect($result['category'])->toBe(ChatbotQueryCategory::Order);
    expect($result['message'])->toContain('Please provide its BF order reference from your order history.');
    ChatbotResponseAgent::assertNeverPrompted();
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
});
