<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotOrchestrationService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;

test('administrators create FAQs with server owned category and priority', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('administration.chatbot-knowledge.store'), [
            'question_pattern' => 'How do I search for products?',
            'response_template' => 'Use catalog search.',
            'category' => 'product',
            'priority' => 999,
        ])->assertSessionHasNoErrors()->assertRedirect(route('administration.chatbot-knowledge.index'));

    $this->assertDatabaseHas('chatbot_knowledge', [
        'category' => 'faq', 'question_pattern' => 'How do I search for products?',
        'priority' => 0, 'is_active' => true,
    ]);
});

test('editing legacy guidance converts category and preserves priority and activation', function () {
    $record = ChatbotKnowledge::factory()->inactive()->create(['category' => ChatbotCategory::Order, 'priority' => 70]);
    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('administration.chatbot-knowledge.update', $record), [
            'question_pattern' => 'How can I check my order status?',
            'response_template' => 'Open your order history.',
            'category' => 'store', 'priority' => -1,
        ])->assertSessionHasNoErrors();

    expect($record->refresh()->category)->toBe(ChatbotCategory::Faq)
        ->and($record->priority)->toBe(70)->and($record->is_active)->toBeFalse();
});

test('save and preview share resolver ownership boundaries', function (string $question, string $owner) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $this->actingAs(User::factory()->administrator()->create());
    $this->postJson(route('administration.chatbot-knowledge.preview'), ['message' => $question])
        ->assertOk()->assertJsonPath('owner', $owner)->assertJsonPath('eligible_for_faq', false);
    $this->postJson(route('administration.chatbot-knowledge.store'), [
        'question_pattern' => $question, 'response_template' => 'An override.',
    ])->assertUnprocessable()->assertJsonValidationErrors('question_pattern');
    $this->assertDatabaseCount('chatbot_knowledge', 0);
    ChatbotResponseAgent::assertNeverPrompted();
})->with([
    ['What is the laptop price?', 'product'],
    ['What is the price of Maya Mechanical Keyboard?', 'product'],
    ['Where is the Cash branch located?', 'store'],
    ['Where is the Sagay branch located?', 'store'],
    ['Is my payment verified?', 'order'],
    ['What payment methods can I use?', 'application_rules'],
    ['How is payment proof verified?', 'application_rules'],
    ['Can I choose pickup or delivery?', 'application_rules'],
    ['What is the delivery fee?', 'application_rules'],
    ['Tell me a joke', 'unsupported'],
    ['password: secret', 'unsupported'],
    ['Delivery fee and laptop price?', 'clarification'],
]);

test('normalized duplicates include inactive FAQs but ignore the record being edited', function () {
    $record = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?',
    ]);
    $this->actingAs(User::factory()->administrator()->create());
    $payload = ['question_pattern' => 'HOW do I search for products!!!', 'response_template' => 'Use search.'];
    $this->postJson(route('administration.chatbot-knowledge.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('question_pattern');
    $this->put(route('administration.chatbot-knowledge.update', $record), $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('chatbot_knowledge', 1);
});

test('reactivation requires eligible FAQ guidance and deactivation preserves legacy records', function () {
    $record = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product, 'question_pattern' => 'What is the laptop price?',
    ]);
    $this->actingAs(User::factory()->administrator()->create());
    $url = route('administration.chatbot-knowledge.activation.update', $record);
    $this->patchJson($url, ['is_active' => false])->assertRedirect();
    $this->patchJson($url, ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    expect($record->refresh()->is_active)->toBeFalse();
    $this->assertModelExists($record);
});

test('rule guidance ignores saved overrides and answers without Gemini or active knowledge', function (string $question, string $answer) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(fn () => throw new AiException('Provider unavailable'))->preventStrayPrompts();
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => $question, 'response_template' => 'Wrong rule override.',
    ]);
    $result = app(ChatbotOrchestrationService::class)->respond($question);
    expect($result['source'])->toBe('fallback')->and($result['message'])->toContain($answer);
    ChatbotKnowledge::query()->update(['is_active' => false]);
    expect(app(ChatbotOrchestrationService::class)->respond($question)['message'])->toContain($answer);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    ['Do you accept GCash?', 'Pay online uses PayMongo Hosted Checkout'],
    ['Do I need payment proof for Maya?', 'New online payments require no receipt or screenshot upload'],
    ['Can I choose pickup or delivery?', 'Battlefront supports pickup and delivery. Choose an available fulfillment option during checkout; a delivery address is required for delivery.'],
    ['What is the delivery fee?', 'nationwide fallback routes require manual arrangements without an automatic ETA or Shipment'],
]);

test('FAQ exactness and relevance select one answer regardless of priority', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Search guidance.'])->preventStrayPrompts();
    $exact = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?',
        'response_template' => 'Use search.', 'priority' => -100,
    ]);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search the catalog?',
        'response_template' => 'Other guidance.', 'priority' => 1000,
    ]);
    expect(app(MatchChatbotKnowledge::class)->bestMatches('How do I search for products?')[0]['id'])->toBe($exact->id);
    expect(app(ChatbotOrchestrationService::class)->respond('How can I search products?')['message'])->toBe('Use search.');
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('equal best FAQ matches clarify before calling Gemini or returning a template', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    foreach ([['How do I search the catalog?', 0], ['How can I search the catalog?', 1000]] as [$question, $priority]) {
        ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => $question, 'priority' => $priority]);
    }
    $message = 'How should I search the catalog?';
    $result = app(ChatbotOrchestrationService::class)->respond($message);
    expect($result['source'])->toBe('fallback')->and($result['message'])->toContain('Which question do you mean?', 'How do I search the catalog?', 'How can I search the catalog?');
    $this->actingAs(User::factory()->administrator()->create())->postJson(route('administration.chatbot-knowledge.preview'), ['message' => $message])
        ->assertOk()->assertJsonPath('ambiguous', true)->assertJsonCount(2, 'matches');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('deactivation removes FAQ answers on the next conversation turn', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Search guidance.'])->preventStrayPrompts();
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond($record->question_pattern, null, null, 'session-a');
    $record->update(['is_active' => false]);
    $second = $chat->respond($record->question_pattern, null, $first['context_token'], 'session-a');
    expect($second['source'])->toBe('fallback')->and($second['message'])->toContain('currently unavailable');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('generic Facebook inquiries request identification without claiming a catalog search failed', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $message = "Do you have the product I saw on Battlefront's Facebook page?";
    expect(app(ResolveProductContext::class)->execute($message)['resolution'])->toBe('needs_details');
    $result = app(ChatbotOrchestrationService::class)->respond($message);
    expect($result['source'])->toBe('fallback')->and($result['message'])->toContain('product name', 'Facebook post')->not->toContain("couldn't find");
    ChatbotResponseAgent::assertNeverPrompted();
});

test('Facebook wording preserves an explicitly named product search', function () {
    $product = Product::factory()->create(['name' => 'Aurelius Mouse']);
    Inventory::factory()->for($product)->create(['quantity' => 5]);
    $result = app(ResolveProductContext::class)->execute("Do you have Aurelius Mouse from Battlefront's Facebook page?");
    expect($result['resolution'])->toBe('matched')->and($result['products'][0]['name'])->toBe('Aurelius Mouse');
    expect(app(ResolveProductContext::class)->execute('Do you have Nebula Quantum Parts?')['resolution'])->toBe('not_found');
});

test('legacy records expose review status and create pages do not expose category choices', function () {
    config(['inertia.ssr.enabled' => false]);
    $record = ChatbotKnowledge::factory()->create(['category' => ChatbotCategory::Store]);
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.chatbot-knowledge.create'))
        ->assertInertia(fn (Assert $page) => $page->missing('categories'));
    $this->get(route('administration.chatbot-knowledge.show', $record))
        ->assertInertia(fn (Assert $page) => $page->where('knowledge.eligible_for_faq', false)->where('knowledge.category', 'store'));
});

test('meaningless draft questions are rejected consistently by preview and saving', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $this->postJson(route('administration.chatbot-knowledge.preview'), ['message' => 'What can I do?'])
        ->assertOk()->assertJsonPath('eligible_for_faq', false)
        ->assertJsonPath('explanation', 'Enter a specific Battlefront support question with meaningful wording.');
    $this->postJson(route('administration.chatbot-knowledge.store'), [
        'question_pattern' => 'What can I do?', 'response_template' => 'An answer.',
    ])->assertUnprocessable()->assertJsonValidationErrors('question_pattern');
});

test('FAQs with blank answers cannot be reactivated', function () {
    $record = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?', 'response_template' => ' ',
    ]);
    $this->actingAs(User::factory()->administrator()->create())
        ->patchJson(route('administration.chatbot-knowledge.activation.update', $record), ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    expect($record->refresh()->is_active)->toBeFalse();
});

test('generic product questions request details without introducing Facebook wording', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake()->preventStrayPrompts();
    $result = app(ChatbotOrchestrationService::class)->respond('What products are available?');
    expect($result['source'])->toBe('fallback')->and($result['message'])->toContain('product name or model')->not->toContain('Facebook');
    ChatbotResponseAgent::assertNeverPrompted();
});

test('cleaned retired presets leave pagination correct while inactive FAQs and edited legacy records remain visible', function () {
    config(['inertia.ssr.enabled' => false]);
    $retired = ChatbotKnowledge::factory()->count(18)->inactive()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
        'response_template' => 'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.',
        'priority' => 70,
    ]);
    $inactiveFaq = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How do I search for products?',
    ]);
    $editedLegacy = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'What payment methods can I use?',
        'response_template' => 'Administrator-edited guidance.', 'priority' => 70,
    ]);
    ChatbotKnowledge::factory()->count(15)->create(['category' => ChatbotCategory::Faq]);

    $migration = require database_path('migrations/2026_10_11_032824_delete_unchanged_retired_chatbot_presets.php');
    $migration->up();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.chatbot-knowledge.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('knowledge.total', 17)->where('knowledge.last_page', 2)->has('knowledge.data', 15)
            ->where('knowledge.data.0.id', $inactiveFaq->id)
            ->where('knowledge.data.0.is_active', false)
            ->where('knowledge.data.1.id', $editedLegacy->id));
    $this->get(route('administration.chatbot-knowledge.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('knowledge.data', 2)->where('knowledge.total', 17));
    $this->assertDatabaseCount('chatbot_knowledge', 17);
    foreach ($retired as $record) {
        $this->assertModelMissing($record);
    }
});

test('deleted presets return 404 for detail edit update and reactivation', function () {
    config(['inertia.ssr.enabled' => false]);
    $retired = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Order,
        'question_pattern' => 'Can I choose pickup or delivery?',
        'response_template' => 'Battlefront supports pickup and delivery. Choose an available fulfillment option during checkout; a delivery address is required for delivery.',
        'priority' => 70,
    ]);
    $migration = require database_path('migrations/2026_10_11_032824_delete_unchanged_retired_chatbot_presets.php');
    $migration->up();

    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('administration.chatbot-knowledge.show', $retired->id))->assertNotFound();
    $this->get(route('administration.chatbot-knowledge.edit', $retired->id))->assertNotFound();
    $this->putJson(route('administration.chatbot-knowledge.update', $retired->id), [
        'question_pattern' => 'How do I search for products?', 'response_template' => 'Use catalog search.',
    ])->assertNotFound();
    $this->patchJson(route('administration.chatbot-knowledge.activation.update', $retired->id), [
        'is_active' => true,
    ])->assertNotFound();
    $this->assertModelMissing($retired);
    $this->assertDatabaseCount('chatbot_knowledge', 0);
});

test('admin answer edits are returned verbatim on the next FAQ turn and activation changes take effect immediately', function () {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Gemini must not rewrite this answer.'])->preventStrayPrompts();
    $question = 'How can I check my order status?';
    $record = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => $question,
        'response_template' => 'Original approved guidance.',
    ]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond($question, null, null, 'session-a');
    expect($first['message'])->toBe('Original approved guidance.');

    $answer = "Open your order history.  jj gwapogi\nReview the latest status.";
    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('administration.chatbot-knowledge.update', $record), [
            'question_pattern' => $question, 'response_template' => $answer,
        ])->assertSessionHasNoErrors();
    $second = $chat->respond($question, null, $first['context_token'], 'session-a');
    expect($second['message'])->toBe($answer);
    $this->assertDatabaseHas('chatbot_knowledge', ['id' => $record->id, 'response_template' => $answer]);

    $activationUrl = route('administration.chatbot-knowledge.activation.update', $record);
    $this->patch($activationUrl, ['is_active' => false])->assertSessionHasNoErrors();
    $third = $chat->respond($question, null, $second['context_token'], 'session-a');
    expect($third['message'])->toBe('Approved information for that question is currently unavailable.');

    $this->patch($activationUrl, ['is_active' => true])->assertSessionHasNoErrors();
    $fourth = $chat->respond($question, null, $third['context_token'], 'session-a');
    expect($fourth['message'])->toBe($answer);
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('web and mobile FAQ replies preserve edited approved wording and stop answering after deactivation', function (string $routeName, bool $authenticated) {
    Http::preventStrayRequests();
    ChatbotResponseAgent::fake(['Unwanted generated wording.'])->preventStrayPrompts();
    $isMobile = $routeName === 'api.v1.chatbot.store';
    if ($authenticated) {
        $customer = User::factory()->customer()->create();
        if ($isMobile) {
            $this->withToken($customer->createToken('Phone')->plainTextToken);
        } else {
            $this->actingAs($customer);
        }
    }
    $record = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How can I check my order status?',
        'response_template' => 'Open your order history.',
    ]);
    $prefix = $isMobile ? 'data.' : '';
    $first = $this->postJson(route($routeName), ['message' => $record->question_pattern])
        ->assertOk()->assertJsonPath($prefix.'message', 'Open your order history.');

    $answer = "Open your order history.  Approved note: café.\nReview the latest status.";
    $record->update(['response_template' => $answer]);
    $second = $this->postJson(route($routeName), [
        'message' => $record->question_pattern, 'context_token' => $first->json($prefix.'context_token'),
    ])->assertOk()->assertJsonPath($prefix.'message', $answer)->assertJsonPath($prefix.'source', 'fallback');
    expect($second->json($prefix.'context_token'))->toBeString();

    $record->update(['is_active' => false]);
    $this->postJson(route($routeName), [
        'message' => $record->question_pattern, 'context_token' => $second->json($prefix.'context_token'),
    ])->assertOk()->assertJsonPath($prefix.'message', 'Approved information for that question is currently unavailable.');
    ChatbotResponseAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'web guest' => ['chatbot.store', false],
    'web customer' => ['chatbot.store', true],
    'mobile guest' => ['api.v1.chatbot.store', false],
    'mobile customer' => ['api.v1.chatbot.store', true],
]);
