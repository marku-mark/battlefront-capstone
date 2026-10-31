<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;

use function Pest\Laravel\mock;

test('customers can access the floating assistant on their dashboard', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.useCustomerCart', true));
});

test('the standalone chatbot page is no longer available', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get('/chatbot')
        ->assertMethodNotAllowed();
});

test('only customers receive the shared permission used by the floating assistant', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.useCustomerCart', false));

    auth()->logout();

    $this->get(route('branches.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.useCustomerCart', false));
});

test('customers receive only the chatbot response fields for a supported inquiry', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake([
        'The Sagay store is at the confirmed address.',
    ])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    Branch::factory()->create([
        'city' => 'Sagay City',
        'address' => 'Confirmed Sagay address',
    ]);

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'The Sagay store is at the confirmed address.',
            'source' => 'gemini',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('the chatbot rejects invalid messages without calling Gemini', function (array $payload) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'blank' => [['message' => '   ']],
    'non-string' => [['message' => ['Where is the store?']]],
    'too long' => [['message' => str_repeat('a', 1001)]],
]);

test('guests can ask about catalog products', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['The Aurelius Link Station is available.'])->preventStrayPrompts();
    $category = Category::factory()->create(['name' => 'Networking']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Aurelius Link Station',
        'brand' => 'Helios Labs',
    ]);
    Inventory::factory()->for($product)->create(['quantity' => 7]);

    $this->postJson(route('chatbot.store'), ['message' => 'Is the Aurelius Link Station available?'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'The Aurelius Link Station is available. Live inventory information covers the Sagay branch only.',
            'source' => 'gemini',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('guests can ask about store information', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['The Sagay store is at the confirmed address.'])->preventStrayPrompts();
    Branch::factory()->create([
        'city' => 'Sagay City',
        'address' => 'Confirmed Sagay address',
    ]);

    $this->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'The Sagay store is at the confirmed address.',
            'source' => 'gemini',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('guests receive application payment rules without saved FAQ overrides or Gemini', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['These are the approved payment methods.'])->preventStrayPrompts();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
        'response_template' => 'Approved payment guidance.',
    ]);

    $this->postJson(route('chatbot.store'), ['message' => 'What payment methods are accepted?'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'Pickup orders support Cash (Pay at Pickup), Debit/Credit Card (Pay at Store), Pay online. Delivery orders support Cash on Delivery (COD), Pay online. Pay online uses PayMongo Hosted Checkout for QR Ph, online card, GCash, Maya. New online payments require no receipt or screenshot upload. Confirmation comes from authenticated server processing; a browser return is not proof of payment. Use Continue payment in your saved order when available. Development transactions are test mode only. Route restrictions still apply; use the options offered at checkout. Direct online banking is not offered for new orders.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('guest order inquiries require sign-in without resolving an order or calling Gemini', function (string $messageFormat) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $order = Order::factory()->create();
    mock(ResolveOrderContext::class)->shouldNotReceive('execute');

    $this->postJson(route('chatbot.store'), ['message' => sprintf($messageFormat, $order->reference)])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'Please sign in with a customer account to check order status.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'status' => ['Track my order %s.'],
    'payment' => ['Payment for %s?'],
    'sensitive input' => ['Track my order %s. password: private-value'],
]);

test('guests receive the existing validation error for blank questions', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    $this->postJson(route('chatbot.store'), ['message' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('sensitive customer input returns a safe fallback before context or Gemini is called', function (string $message) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    mock(ResolveProductContext::class)->shouldNotReceive('execute');
    mock(ResolveOrderContext::class)->shouldNotReceive('execute');
    mock(ResolveStoreContext::class)->shouldNotReceive('execute');
    mock(ResolveFaqContext::class)->shouldNotReceive('execute');

    $this->postJson(route('chatbot.store'), ['message' => $message])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'Please remove sensitive information from your question and try again.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'labeled password' => ['Where is the Sagay store? password: private-value'],
    'labeled API key' => ['Where is the Sagay store? api_key=private-value'],
    'labeled API secret' => ['Where is the Sagay store? api-secret: private-value'],
    'bearer token' => ['Where is the Sagay store? Bearer private-token'],
    'payment proof path' => ['Where is the Sagay store? payment-proofs/private-proof.jpg'],
    'email address' => ['Where is the Sagay store? person@example.com'],
    'phone number' => ['Where is the Sagay store? 09171234567'],
    'international phone number' => ['Where is the Sagay store? +63 917 123 4567'],
    'internal note' => ['Where is the Sagay store? internal note: private detail'],
]);

test('customers can ask about their own order', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Your order is pending.'])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->for($customer)->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => "Track my order {$order->reference}."])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'Your order is pending.',
            'source' => 'gemini',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('administrators are forbidden from chatbot submission', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->postJson(route('chatbot.store'), ['message' => 'Show my order status'])
        ->assertForbidden();
});

test('unsupported inquiries reach the customer as a safe fallback', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Recommend the best laptop.'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('provider failures reach the customer as a safe fallback', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(
        fn () => throw new AiException('private provider error'),
    )->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    Branch::factory()->create(['city' => 'Sagay City', 'address' => 'Confirmed Sagay address']);

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => 'Sagay City: Address: Confirmed Sagay address.',
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('another customers order never reaches Gemini through the chatbot endpoint', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->for($otherCustomer)->create();

    $this->actingAs($customer)
        ->postJson(route('chatbot.store'), [
            'message' => "Track my order {$foreignOrder->reference}.",
        ])
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJson([
            'message' => "I couldn't find a matching order in your account.",
            'source' => 'fallback',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('guest requests are rate limited before Gemini is called again', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(array_fill(0, 6, 'The Sagay store is open.'))->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
            ->assertOk();
    }

    $this->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
        ->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'Too many questions. Please wait a minute and try again.',
        ])
        ->assertHeader('Retry-After');

    ChatbotResponseAgent::assertPromptedTimes(5);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
        ->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
        ->assertOk();

    ChatbotResponseAgent::assertPromptedTimes(6);
    Http::assertNothingSent();
});

test('customer requests are limited per account rather than per shared IP', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(array_fill(0, 11, 'The Sagay store is open.'))->preventStrayPrompts();
    Branch::factory()->create(['city' => 'Sagay City']);
    $firstCustomer = User::factory()->customer()->create();
    $secondCustomer = User::factory()->customer()->create();

    $this->actingAs($firstCustomer);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
            ->assertOk();
    }

    $this->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
        ->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'Too many questions. Please wait a minute and try again.',
        ]);

    $this->actingAs($secondCustomer)
        ->postJson(route('chatbot.store'), ['message' => 'What are the Sagay store hours?'])
        ->assertOk();

    ChatbotResponseAgent::assertPromptedTimes(11);
    Http::assertNothingSent();
});

test('429 throttled requests never resolve context or prompt Gemini', function (bool $authenticated, int $requestLimit) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();

    if ($authenticated) {
        $this->actingAs(User::factory()->customer()->create());
    }

    for ($attempt = 0; $attempt < $requestLimit; $attempt++) {
        $this->postJson(route('chatbot.store'), ['message' => 'Tell me a joke.'])
            ->assertOk();
    }

    mock(ResolveStoreContext::class)->shouldNotReceive('execute');

    $this->postJson(route('chatbot.store'), ['message' => 'Where is the Sagay store?'])
        ->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'Too many questions. Please wait a minute and try again.',
        ]);

    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'guest IP limit' => [false, 5],
    'customer account limit' => [true, 10],
]);
