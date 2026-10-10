<?php

use App\Actions\Chatbot\Context\ResolveFaqContext;
use App\Actions\Chatbot\Context\ResolveOrderContext;
use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\Context\ResolveStoreContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Actions\Chatbot\RouteChatbotQuery;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Enums\ChatbotQueryCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\mock;

test('categorizes before resolving context and resolves context before generating wording', function () {
    $context = [
        'products' => [[
            'name' => 'Aurelius Link Station',
            'price' => '12999.00',
            'inventory' => ['quantity' => 7, 'status' => 'in_stock'],
        ]],
    ];
    $categorizer = mock(RouteChatbotQuery::class);
    $productResolver = mock(ResolveProductContext::class);
    $orderResolver = mock(ResolveOrderContext::class);
    $storeResolver = mock(ResolveStoreContext::class);
    $faqResolver = mock(ResolveFaqContext::class);
    $adapter = mock(ChatbotAiAdapter::class);
    $categorizer->shouldReceive('execute')
        ->once()
        ->with('Is the Aurelius Link Station available?')
        ->globally()->ordered()
        ->andReturn(['category' => ChatbotQueryCategory::Product, 'choices' => []]);
    $productResolver->shouldReceive('execute')
        ->once()
        ->with('Is the Aurelius Link Station available?')
        ->globally()->ordered()
        ->andReturn($context);
    $adapter->shouldReceive('generate')
        ->once()
        ->with('Is the Aurelius Link Station available?', $context)
        ->globally()->ordered()
        ->andReturn([
            'successful' => true,
            'text' => 'The Aurelius Link Station is in stock.',
            'failure' => null,
        ]);
    $orderResolver->shouldNotReceive('execute');
    $storeResolver->shouldNotReceive('execute');
    $faqResolver->shouldNotReceive('execute');

    $result = (new ChatbotOrchestrationService(
        $categorizer,
        $productResolver,
        $orderResolver,
        $storeResolver,
        $faqResolver,
        $adapter,
        new RenderChatbotFallback,
    ))->respond("  Is the Aurelius Link Station\navailable?  ");

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Product,
        'message' => 'The Aurelius Link Station is in stock.',
        'source' => 'gemini',
    ]);
});

test('returns predefined fallbacks without Gemini for empty and unsupported inquiries', function (
    string $message,
    string $expectedMessage,
) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    mock(ResolveProductContext::class)->shouldNotReceive('execute');
    mock(ResolveOrderContext::class)->shouldNotReceive('execute');
    mock(ResolveStoreContext::class)->shouldNotReceive('execute');
    mock(ResolveFaqContext::class)->shouldNotReceive('execute');

    $result = app(ChatbotOrchestrationService::class)->respond($message);

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Unsupported,
        'message' => $expectedMessage,
        'source' => 'fallback',
    ]);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'empty input' => [
        '   ',
        'Please enter a question about Battlefront products, orders, stores, payment methods, pickup, or delivery.',
    ],
    'open-domain inquiry' => [
        'Tell me a joke.',
        'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.',
    ],
    'product recommendation inquiry' => [
        'Recommend the best laptop for gaming.',
        'I can only help with Battlefront products, your orders, store information, payment methods, pickup, and delivery.',
    ],
]);

test('sends only matched catalog and Sagay inventory facts for a product inquiry', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['The Aurelius Link Station is in stock.'])->preventStrayPrompts();
    $category = Category::factory()->create(['name' => 'Networking']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Aurelius Link Station',
        'description' => 'Outdoor networking unit',
        'brand' => 'Helios Labs',
        'price' => '12999.00',
        'discount_price' => null,
        'image_path' => 'images/private-catalog-path.jpg',
    ]);
    $tag = Tag::factory()->create(['name' => 'Field Ready']);
    $product->tags()->attach($tag);
    Inventory::factory()->for($product)->create(['quantity' => 7, 'reorder_level' => 2]);
    Product::factory()->create([
        'name' => 'Unrelated Device',
        'description' => 'Unrelated private product detail.',
    ]);

    app(ChatbotOrchestrationService::class)->respond('Is the Aurelius Link Station available?');

    $expectedContext = [
        'products' => [[
            'name' => 'Aurelius Link Station',
            'description' => 'Outdoor networking unit',
            'brand' => 'Helios Labs',
            'category' => 'Networking',
            'tags' => ['Field Ready'],
            'price' => '12999.00',
            'discount_price' => null,
            'is_demo' => false,
            'inventory' => ['quantity' => 7, 'status' => 'in_stock'],
        ]],
    ];
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($expectedContext): bool {
        return str_contains($prompt->prompt, json_encode($expectedContext, JSON_THROW_ON_ERROR))
            && ! str_contains($prompt->prompt, 'images/private-catalog-path.jpg')
            && ! str_contains($prompt->prompt, 'Unrelated private product detail.');
    });
    Http::assertNothingSent();
});

test('sends only approved reference facts for the requested store', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['The Sagay store is open during the listed hours.'])->preventStrayPrompts();
    config()->set('battlefront.operating_hours', '08:00-18:00');
    config()->set('battlefront.branch_emails', ['Sagay City' => 'approved-store@example.test']);
    Branch::factory()->create([
        'city' => 'Sagay City',
        'address' => 'Approved Sagay address',
        'contact_number' => '0938 647 6046',
        'latitude' => '10.123456',
    ]);
    Branch::factory()->create([
        'city' => 'Other City',
        'address' => 'Unrelated private branch detail.',
    ]);

    app(ChatbotOrchestrationService::class)->respond('Where is the Sagay store?');

    $expectedContext = [
        'branches' => [[
            'name' => 'Battlefront Computer Trading',
            'city' => 'Sagay City',
            'address' => 'Approved Sagay address',
            'contact_number' => '0938 647 6046',
            'email' => 'approved-store@example.test',
            'operating_hours' => '08:00-18:00',
            'is_operational' => true,
        ]],
    ];
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($expectedContext): bool {
        return str_contains($prompt->prompt, json_encode($expectedContext, JSON_THROW_ON_ERROR))
            && ! str_contains($prompt->prompt, '10.123456')
            && ! str_contains($prompt->prompt, 'Unrelated private branch detail.');
    });
    Http::assertNothingSent();
});

test('sends only matching active managed knowledge for an FAQ inquiry', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Approved payment guidance.'])->preventStrayPrompts();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
        'response_template' => 'Approved payment guidance.',
    ]);
    ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
        'response_template' => 'Inactive private guidance.',
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product,
        'question_pattern' => 'What payment methods are accepted?',
        'response_template' => 'Wrong-topic private guidance.',
    ]);

    app(ChatbotOrchestrationService::class)->respond('What payment methods are accepted?');

    $expectedContext = [
        'knowledge' => [[
            'question_pattern' => 'What payment methods are accepted?',
            'response_template' => 'Approved payment guidance.',
        ]],
    ];
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($expectedContext): bool {
        return str_contains($prompt->prompt, json_encode($expectedContext, JSON_THROW_ON_ERROR))
            && ! str_contains($prompt->prompt, 'Inactive private guidance.')
            && ! str_contains($prompt->prompt, 'Wrong-topic private guidance.');
    });
    Http::assertNothingSent();
});

test('returns category-specific missing-context fallbacks without Gemini', function (
    string $message,
    ChatbotQueryCategory $category,
    string $expectedMessage,
    bool $authenticatedCustomer,
) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = $authenticatedCustomer ? User::factory()->customer()->create() : null;

    $result = app(ChatbotOrchestrationService::class)->respond($message, $customer);

    expect($result)->toBe([
        'category' => $category,
        'message' => $expectedMessage,
        'source' => 'fallback',
    ]);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'no matching product' => [
        'Is the Nebula Quantum Laptop available?',
        ChatbotQueryCategory::Product,
        "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.",
        false,
    ],
    'guest order inquiry' => [
        'What is my order status?',
        ChatbotQueryCategory::Order,
        'Please sign in with a customer account to check order status.',
        false,
    ],
    'customer order not found' => [
        'Track my order BF-999999.',
        ChatbotQueryCategory::Order,
        "I couldn't find a matching order in your account.",
        true,
    ],
    'no branch reference data' => [
        'Where is the Sagay store?',
        ChatbotQueryCategory::Store,
        'Store information is currently unavailable. Please try again later.',
        false,
    ],
    'no active FAQ knowledge' => [
        'What payment methods are accepted?',
        ChatbotQueryCategory::Faq,
        'Approved information for that question is currently unavailable.',
        false,
    ],
]);

test('passes only minimized owned order context to Gemini', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake([
        'Your order is being prepared for delivery.',
    ])->preventStrayPrompts();
    $customer = User::factory()->customer()->create([
        'email' => 'private-customer@example.test',
    ]);
    $order = Order::factory()->delivery()->paidWithGCash()->for($customer)->create([
        'status' => OrderStatus::Processing,
        'payment_rejection_reason' => PaymentRejectionReason::Other,
        'payment_rejection_note' => 'Private administrator feedback.',
        'payment_proof_path' => 'payment-proofs/private-proof.jpg',
        'created_at' => '2026-09-20 10:00:00',
    ]);
    $otherCustomer = User::factory()->customer()->create();
    Order::factory()->for($otherCustomer)->create([
        'recipient_name' => 'Unrelated private recipient',
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond(
        "What is the status of my order {$order->reference}?",
        $customer,
    );

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Order,
        'message' => 'Your order is being prepared for delivery.',
        'source' => 'gemini',
    ]);
    $expectedContext = [
        'orders' => [[
            'reference' => $order->reference,
            'created_at' => '2026-09-20T10:00:00+00:00',
            'status' => ['value' => 'processing', 'label' => 'Processing'],
            'fulfillment' => ['value' => 'delivery', 'label' => 'Delivery'],
        ]],
    ];
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($expectedContext): bool {
        return str_contains($prompt->prompt, json_encode($expectedContext, JSON_THROW_ON_ERROR))
            && ! str_contains($prompt->prompt, 'private-customer@example.test')
            && ! str_contains($prompt->prompt, 'Unrelated private recipient')
            && ! str_contains($prompt->prompt, 'Private administrator feedback.')
            && ! str_contains($prompt->prompt, 'payment-proofs/private-proof.jpg')
            && ! str_contains($prompt->prompt, 'recipient_name')
            && ! str_contains($prompt->prompt, 'contact_number')
            && ! str_contains($prompt->prompt, 'total_amount');
    });
    Http::assertNothingSent();
});

test('uses owned order payment context for a shorthand reference instead of generic FAQ knowledge', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake([
        'Your delivery order uses GCash and its payment is verified.',
    ])->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->delivery()->paidWithGCash()->for($customer)->create([
        'payment_status' => PaymentStatus::Verified,
        'payment_proof_path' => 'payment-proofs/private-proof.jpg',
        'payment_rejection_note' => 'Private administrator note.',
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond(
        "Payment for BF-{$order->id}?",
        $customer,
    );

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Order,
        'message' => 'Your delivery order uses GCash and its payment is verified.',
        'source' => 'gemini',
    ]);
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt) use ($order): bool {
        return str_contains($prompt->prompt, $order->reference)
            && str_contains($prompt->prompt, '"fulfillment":{"value":"delivery","label":"Delivery"}')
            && str_contains($prompt->prompt, '"method":{"value":"gcash","label":"GCash"}')
            && str_contains($prompt->prompt, '"status":{"value":"verified","label":"Verified"}')
            && ! str_contains($prompt->prompt, 'payment-proofs/private-proof.jpg')
            && ! str_contains($prompt->prompt, 'Private administrator note.');
    });
    Http::assertNothingSent();
});

test('passes explicitly named demo products to Gemini as unconfirmed samples', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake([
        'This is a demo listing; Battlefront stock is unconfirmed.',
    ])->preventStrayPrompts();
    Product::factory()->create([
        'name' => '[DEMO] Samsung Sprint NVMe SSD',
        'brand' => 'Samsung',
        'price' => '4599.00',
        'description' => null,
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond(
        'Do you have Samsung Sprint NVMe SSD available currently?',
    );

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Product,
        'message' => 'This is a demo listing; Battlefront stock is unconfirmed.',
        'source' => 'gemini',
    ]);
    ChatbotResponseAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return str_contains($prompt->prompt, '[DEMO] Samsung Sprint NVMe SSD')
            && str_contains($prompt->prompt, '"is_demo":true')
            && str_contains($prompt->prompt, 'Battlefront stock is unconfirmed.')
            && str_contains($prompt->prompt, '"quantity":null')
            && ! str_contains($prompt->prompt, '"quantity":15');
    });
    Http::assertNothingSent();
});

test('cannot use another customers order as Gemini context', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $foreignOrder = Order::factory()->for($otherCustomer)->create();

    $result = app(ChatbotOrchestrationService::class)->respond(
        "Track my order {$foreignOrder->reference}.",
        $customer,
    );

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Order,
        'message' => "I couldn't find a matching order in your account.",
        'source' => 'fallback',
    ]);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('returns the confirmed address after a provider connection failure', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw ProviderConnectionException::forProvider(
        'gemini',
        previous: new RuntimeException('sensitive timeout detail'),
    ))->preventStrayPrompts();
    Branch::factory()->create([
        'city' => 'Sagay City',
        'address' => 'Approved Sagay address',
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond('Where is the Sagay store?');

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Store,
        'message' => 'Sagay City: Address: Approved Sagay address.',
        'source' => 'fallback',
    ])->not->toContain('sensitive timeout detail');
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});

test('returns the approved knowledge answer for provider errors', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(
        fn () => throw new AiException('provider response containing a secret'),
    )->preventStrayPrompts();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What payment methods are accepted?',
        'response_template' => 'Approved payment guidance.',
    ]);

    $result = app(ChatbotOrchestrationService::class)->respond(
        'What payment methods are accepted?',
    );

    expect($result)->toBe([
        'category' => ChatbotQueryCategory::Faq,
        'message' => 'Approved payment guidance.',
        'source' => 'fallback',
    ])->not->toContain('provider response containing a secret');
    ChatbotResponseAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
});
