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
    Branch::factory()->create([
        'city' => 'Sagay City',
        'contact_number' => '0938 647 6046',
        'email' => 'battlefrontcomputertrading@gmail.com',
        'address' => 'Unrequested address',
    ]);

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
        ->assertJsonPath('source', 'fallback')
        ->assertSee('Sagay City')->assertSee('Escalante City')->assertDontSee('Which branch');

    ChatbotResponseAgent::assertNeverPrompted();
});

test('competing FAQ matches clarify before provider generation', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search the catalog?', 'response_template' => 'Pickup answer.', 'priority' => 100]);
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How can I search the catalog?', 'response_template' => 'Delivery answer.', 'priority' => 90]);

    $this->postJson(route('chatbot.store'), ['message' => 'How should I search the catalog?'])
        ->assertJsonPath('message', "Which question do you mean?\n\n- How do I search the catalog?\n- How can I search the catalog?");

    ChatbotResponseAgent::assertNeverPrompted();
});

test('an exact approved FAQ is preferred over broader matches without calling the provider', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => 'Confirmed search guidance.']);
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search the catalog?', 'response_template' => 'Other guidance.']);

    $this->postJson(route('chatbot.store'), ['message' => 'How do I search for products?'])
        ->assertJsonPath('message', 'Confirmed search guidance.');

    ChatbotResponseAgent::assertNeverPrompted();
});

test('provider outage returns catalog price and preserves demo notice', function (string $name, string $expected) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Product::factory()->create(['name' => $name, 'price' => 1200, 'discount_price' => 1000]);

    $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Aurelius Mouse?'])
        ->assertJsonPath('source', 'fallback')->assertJsonPath('message', $expected);

    ChatbotResponseAgent::assertPromptedTimes(1);
})->with([
    'real' => ['Aurelius Mouse', "Aurelius Mouse.\n\nListed price: PHP 1,200.00.\n\nDiscount price: PHP 1,000.00."],
    'demo' => ['[DEMO] Aurelius Mouse', "[DEMO] Aurelius Mouse.\n\nDemo item only; listed prices are samples and Battlefront stock is unconfirmed.\n\nListed price: PHP 1,200.00.\n\nDiscount price: PHP 1,000.00."],
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

test('provider outage lists matching product prices without forced selection', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    Product::factory()->available()->create(['name' => 'Aurelius Mouse']);
    Product::factory()->available()->create(['name' => 'Aurelius Keyboard']);

    $response = $this->postJson(route('chatbot.store'), ['message' => 'What is the price of Aurelius?']);

    expect($response->json('message'))->toContain('Aurelius Mouse', 'Aurelius Keyboard', 'PHP')->not->toContain('Which product');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('provider outage returns owned payment status without private order fields', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->paidWithGCash()->create(['recipient_name' => 'Private recipient']);

    $this->actingAs($customer)->postJson(route('chatbot.store'), ['message' => 'What is the payment status of '.$order->reference.'?'])
        ->assertJsonPath('message', $order->reference.": Order status: Pending.\n\nFulfillment: Pickup.\n\nPayment method: GCash. Payment status: Pending.")
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
    ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => '   ']);

    $this->postJson(route('chatbot.store'), ['message' => 'How do I search for products?'])
        ->assertJsonPath('message', 'Approved information for that question is currently unavailable.');

    ChatbotResponseAgent::assertNeverPrompted();
});
