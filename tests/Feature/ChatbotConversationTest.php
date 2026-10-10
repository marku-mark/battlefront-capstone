<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

test('branch follow ups retain the intent and use the newly named city', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Sagay address.', 'San Carlos address.'])->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City', 'address' => 'Sagay address']);
    Branch::factory()->create(['city' => 'San Carlos City', 'address' => 'San Carlos address']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Where is your Sagay branch?', null, null, 'session-a');
    $second = $chat->respond('What about San Carlos?', null, $first['context_token'], 'session-a');

    expect($second['message'])->toBe('San Carlos address.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'San Carlos address') && ! str_contains($prompt->prompt, 'Sagay address'));
});

test('product follow ups requery inventory and stop after product deactivation', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Product price.', 'Current stock.'])->preventStrayPrompts();
    $product = Product::factory()->create(['name' => 'Aurelius Mouse']);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 7]);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('What is the price of Aurelius Mouse?', null, null, 'session-a');
    $inventory->update(['quantity' => 0]);
    $second = $chat->respond('Is it available?', null, $first['context_token'], 'session-a');
    $product->update(['is_active' => false]);
    $third = $chat->respond('Is it available?', null, $second['context_token'], 'session-a');

    expect($second['source'])->toBe('gemini')
        ->and($third['source'])->toBe('fallback');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"quantity":0'));
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('product to store switches do not revive a product for an ambiguous pronoun', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Mouse.', 'Branch.'])->preventStrayPrompts();
    Product::factory()->create(['name' => 'Aurelius Mouse']);
    Branch::factory()->create(['city' => 'Sagay City']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Price of Aurelius Mouse?', null, null, 'session-a');
    $second = $chat->respond('Where is Sagay branch?', null, $first['context_token'], 'session-a');
    $third = $chat->respond('Is it available?', null, $second['context_token'], 'session-a');

    expect($third['source'])->toBe('fallback')->and($third['message'])->toContain('Which product');
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('multiple matching products require an explicit name on follow up', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Two mice.'])->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Aurelius Mouse']);
    Product::factory()->available()->create(['name' => 'Helios Mouse']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Mouse price?', null, null, 'session-a');
    $second = $chat->respond('Is it available?', null, $first['context_token'], 'session-a');

    expect($second['message'])->toContain('Which product');
    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('expired tampered and foreign session context cannot resolve a follow up', function (string $change) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Product.'])->preventStrayPrompts();
    Product::factory()->create(['name' => 'Aurelius Mouse']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Aurelius Mouse price?', null, null, 'session-a');
    $token = $first['context_token'];
    if ($change === 'expired') {
        $this->travel(16)->minutes();
    }
    if ($change === 'tampered') {
        $token .= 'invalid';
    }

    $second = $chat->respond('Is it available?', null, $token, $change === 'session' ? 'session-b' : 'session-a');

    expect($second['source'])->toBe('fallback');
    ChatbotResponseAgent::assertPromptedTimes(1);
})->with(['expired', 'tampered', 'session']);

test('order follow ups recheck payment and ownership and cannot cross accounts', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Order.', 'Verified.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Status of '.$order->reference, $customer, null, 'session-a');
    $order->update(['payment_status' => PaymentStatus::Verified]);
    $second = $chat->respond('Is my payment verified?', $customer, $first['context_token'], 'session-a');
    $foreign = $chat->respond('Is my payment verified?', $otherCustomer, $second['context_token'], 'session-a');
    $order->update(['user_id' => $otherCustomer->id]);
    $lost = $chat->respond('Is my payment verified?', $customer, $second['context_token'], 'session-a');

    expect($second['message'])->toBe('Verified.')
        ->and($foreign['source'])->toBe('fallback')
        ->and($lost['source'])->toBe('fallback');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"value":"verified"'));
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('mixed questions resume only the selected topic', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Mouse price.'])->preventStrayPrompts();
    Product::factory()->create(['name' => 'Aurelius Mouse']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Delivery fee and Aurelius Mouse price?', null, null, 'session-a');
    expect($first['source'])->toBe('fallback');
    ChatbotResponseAgent::assertNeverPrompted();
    $second = $chat->respond('product pricing', null, $first['context_token'], 'session-a');

    expect($second['message'])->toBe('Mouse price.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, 'Delivery fee'));
    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('a new explicit question replaces a pending topic choice', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Branch.'])->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Delivery fee and laptop price?', null, null, 'session-a');
    $second = $chat->respond('Where is Sagay branch?', null, $first['context_token'], 'session-a');

    expect($second['message'])->toBe('Branch.');
    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('the HTTP conversation contract returns and accepts an opaque token', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Branch.', 'Branch hours.'])->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    Branch::factory()->create(['city' => 'San Carlos City']);

    $first = $this->postJson(route('chatbot.store'), ['message' => 'Where is Sagay branch?'])
        ->assertOk()->assertJsonCount(3)->assertJsonStructure(['message', 'source', 'context_token']);
    $this->withCredentials()->withCookie(config('session.cookie'), session()->getId())
        ->postJson(route('chatbot.store'), ['message' => 'What are its hours?', 'context_token' => $first->json('context_token')])
        ->assertOk()->assertJsonPath('source', 'gemini');
    ChatbotResponseAgent::assertPromptedTimes(2);
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'its hours') && ! str_contains($prompt->prompt, 'San Carlos'));
});

test('an explicit product name overrides store context even with how much wording', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Branch.', 'Mouse price.'])->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    Product::factory()->create(['name' => 'Aurelius Mouse']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Where is Sagay branch?', null, null, 'session-a');
    $second = $chat->respond('How much is this Aurelius Mouse?', null, $first['context_token'], 'session-a');

    expect($second['message'])->toBe('Mouse price.');
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('an explicit order reference overrides pronouns and previous order context', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['First.', 'Second.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $firstOrder = Order::factory()->for($customer)->create();
    $secondOrder = Order::factory()->for($customer)->create();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Status of '.$firstOrder->reference, $customer, null, 'session-a');
    $second = $chat->respond('Is this order '.$secondOrder->reference.' completed?', $customer, $first['context_token'], 'session-a');

    expect($second['message'])->toBe('Second.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, $secondOrder->reference) && ! str_contains($prompt->prompt, $firstOrder->reference));
});

test('an explicit earlier order can be revisited after a FAQ without retaining its facts', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Order.', 'Payment methods.', 'Current order payment.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods can I use?',
    ]);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Status of '.$order->reference, $customer, null, 'session-a');
    $second = $chat->respond('Do you accept GCash?', $customer, $first['context_token'], 'session-a');
    $order->update(['payment_status' => PaymentStatus::Verified]);
    $third = $chat->respond('Payment for the earlier order?', $customer, $second['context_token'], 'session-a');

    expect($third['message'])->toBe('Current order payment.');
    ChatbotResponseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'earlier order') && str_contains($prompt->prompt, 'verified'));
    ChatbotResponseAgent::assertPromptedTimes(3);
});

test('an explicit new topic after what about is not rewritten to the previous branch intent', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Branch.'])->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    Branch::factory()->create(['city' => 'San Carlos City']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Where is Sagay branch?', null, null, 'session-a');
    $second = $chat->respond('What about laptop stock at San Carlos?', null, $first['context_token'], 'session-a');

    expect($second['source'])->toBe('fallback')->and($second['message'])->toContain('Sagay branch only');
    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('chat requests reject invalid context token payloads before Gemini', function (mixed $token) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    $this->postJson(route('chatbot.store'), ['message' => 'Where is Sagay branch?', 'context_token' => $token])
        ->assertUnprocessable()->assertJsonValidationErrors('context_token');
    ChatbotResponseAgent::assertNeverPrompted();
})->with(['wrong type' => [['product' => 1]], 'oversized' => [str_repeat('x', 16385)]]);

test('knowledge deactivated during a conversation cannot answer the next turn', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Payment guidance.'])->preventStrayPrompts();
    $knowledge = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'What payment methods can I use?',
    ]);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Do you accept GCash?', null, null, 'session-a');
    $knowledge->update(['is_active' => false]);
    $second = $chat->respond('Do you accept Maya?', null, $first['context_token'], 'session-a');

    expect($second['source'])->toBe('fallback')->and($second['message'])->toContain('currently unavailable');
    ChatbotResponseAgent::assertPromptedTimes(1);
});
