<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Models\Branch;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;

test('provider outage returns only the requested confirmed branch information', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException('private error'))->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City', 'contact_number' => '0938 647 6046', 'address' => 'Unrequested address']);

    $this->postJson(route('chatbot.store'), ['message' => 'How can I contact the Sagay store?'])
        ->assertOk()->assertJsonPath('source', 'fallback')
        ->assertJsonPath('message', 'Sagay City: Phone: 0938 647 6046. Email: battlefrontcomputertrading@gmail.com.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage preserves unavailable branch fields', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Escalante City', 'contact_number' => null]);

    $this->postJson(route('chatbot.store'), ['message' => 'How can I contact the Escalante store?'])
        ->assertJsonPath('message', 'Escalante City: Phone: unavailable. Email: unavailable.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage asks for a branch instead of choosing one', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    Branch::factory()->create(['city' => 'Escalante City']);

    $this->postJson(route('chatbot.store'), ['message' => 'Where is your store?'])
        ->assertJsonPath('message', 'Which branch do you mean? Sagay City, Escalante City.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage clarifies competing knowledge matches instead of combining answers', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What payment methods are accepted for pickup?', 'response_template' => 'Pickup answer.', 'priority' => 100]);
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Order, 'question_pattern' => 'What payment methods are accepted for delivery?', 'response_template' => 'Delivery answer.', 'priority' => 90]);

    $this->postJson(route('chatbot.store'), ['message' => 'Do you accept GCash?'])
        ->assertJsonPath('message', 'Which question do you mean? What payment methods are accepted for pickup? / What payment methods are accepted for delivery?');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage prefers an exact approved FAQ over broader matches', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Order, 'question_pattern' => 'What payment methods are accepted?', 'response_template' => 'Confirmed payment options.']);
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What payment methods are accepted for pickup?', 'response_template' => 'Other guidance.']);

    $this->postJson(route('chatbot.store'), ['message' => 'What payment methods are accepted?'])
        ->assertJsonPath('message', 'Confirmed payment options.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage returns catalog price and preserves demo notice', function (string $name, string $expected) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Product::factory()->create(['name' => $name, 'price' => 1200, 'discount_price' => 1000]);

    $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Aurelius Mouse?'])
        ->assertJsonPath('source', 'fallback')->assertJsonPath('message', $expected);

    ChatbotResponseAgent::assertPromptedTimes(1);
})->with([
    'real' => ['Aurelius Mouse', 'Aurelius Mouse. Listed price: PHP 1,200.00. Discount price: PHP 1,000.00.'],
    'demo' => ['[DEMO] Aurelius Mouse', '[DEMO] Aurelius Mouse. Demo item only; listed prices are samples and Battlefront stock is unconfirmed. Listed price: PHP 1,200.00. Discount price: PHP 1,000.00.'],
]);

test('provider outage distinguishes unknown stock from zero stock', function (?int $quantity, string $expected) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $product = Product::factory()->create(['name' => 'Aurelius Mouse']);
    if ($quantity !== null) {
        Inventory::factory()->for($product)->create(['quantity' => $quantity]);
    }

    $response = $this->postJson(route('chatbot.store'), ['message' => 'Is Aurelius Mouse available?'])
        ->assertJsonPath('source', 'fallback');

    expect($response->json('message'))->toContain($expected);
    ChatbotResponseAgent::assertPromptedTimes(1);
})->with([
    'unknown' => [null, 'Sagay stock information is unavailable.'],
    'zero' => [0, 'Currently out of stock at Sagay.'],
    'available' => [3, 'Sagay stock: 3 available.'],
]);

test('provider outage asks which product when several match', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Aurelius Mouse']);
    Product::factory()->available()->create(['name' => 'Aurelius Keyboard']);

    $response = $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Aurelius?']);

    expect($response->json('message'))->toStartWith('Which product do you mean?')->toContain('Aurelius Mouse', 'Aurelius Keyboard');
    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage returns owned payment status without private order fields', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create(['recipient_name' => 'Private recipient']);

    $this->actingAs($customer)->postJson(route('chatbot.store'), ['message' => 'What is the payment status of '.$order->reference.'?'])
        ->assertJsonPath('message', $order->reference.': Order status: Pending. Fulfillment: Pickup. Payment method: GCash. Payment status: Pending.')
        ->assertJsonPath('source', 'fallback');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('provider outage cannot expose another customers order', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $order = Order::factory()->create();

    $this->actingAs(User::factory()->customer()->create())
        ->postJson(route('chatbot.store'), ['message' => 'What is the status of '.$order->reference.'?'])
        ->assertJsonPath('message', "I couldn't find a matching order in your account.");

    ChatbotResponseAgent::assertNeverPrompted();
});

test('fallback conversation follow ups retrieve current stock and respect deactivation', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $product = Product::factory()->create(['name' => 'Aurelius Mouse', 'price' => 1000]);
    $inventory = Inventory::factory()->for($product)->create(['quantity' => 3]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Price of Aurelius Mouse?', null, null, 'session');
    $inventory->update(['quantity' => 0]);

    $second = $chat->respond('Is it available?', null, $first['context_token'], 'session');
    $product->update(['is_active' => false]);
    $third = $chat->respond('Is it available?', null, $second['context_token'], 'session');

    expect($second['message'])->toContain('Currently out of stock at Sagay.');
    expect($third['message'])->toContain("I couldn't find a matching product");
    ChatbotResponseAgent::assertPromptedTimes(2);
});

test('confirmed timeout still returns configured operating hours', function () {
    Http::preventStrayRequests();
    config(['battlefront.operating_hours' => '8:00 AM–6:00 PM']);
    ChatbotResponseAgent::fake(fn () => throw new NetworkTimeoutException(
        'Private transport error', new Request('POST', 'https://example.test'),
    ))->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);

    $this->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('message', 'Sagay City: Operating hours: 8:00 AM–6:00 PM.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});

test('blank approved knowledge never becomes an empty customer reply after provider failure', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'What payment methods are accepted?', 'response_template' => '   ']);

    $this->postJson(route('chatbot.store'), ['message' => 'What payment methods are accepted?'])
        ->assertJsonPath('message', 'The chatbot is temporarily unavailable. Please try again later.');

    ChatbotResponseAgent::assertPromptedTimes(1);
});
