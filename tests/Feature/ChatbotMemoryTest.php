<?php

use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Models\User;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\ChatbotMemory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Exceptions\AiException;

beforeEach(function () {
    config()->set('ai_integration.memory.enabled', true);
});

test('durable memory stores encrypted bounded history and reloads live catalog facts', function () {
    $product = Product::factory()->available()->create(['name' => 'Atlas Monitor X240', 'price' => '4200.00', 'discount_price' => null]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $first = app(ChatbotConversation::class)->respond('Atlas Monitor X240', null, null, 'web:test');
    $product->update(['price' => '5000.00']);
    $second = app(ChatbotConversation::class)->respond('How much is it?', null, $first['context_token'], 'web:test');
    $record = DB::table('chatbot_conversations')->sole();

    expect($second['message'])->toContain('PHP 5,000.00');
    expect($record->state)->not->toContain('references', 'Atlas');
    expect($record->messages)->not->toContain('Atlas', 'How much');
    $messages = json_decode(Crypt::decryptString($record->messages), true);
    expect($messages)->toHaveCount(2);
    expect($messages[1]['user'])->toBe('How much is it?');
    expect(json_decode(Crypt::decryptString($second['context_token']), true)['state'])->toHaveKeys(['id', 'version'])->not->toHaveKey('references');
});

test('memory cannot cross identity scopes and old tokens cannot overwrite newer state', function () {
    $tokens = app(ChatbotConversationToken::class);
    $first = $tokens->encode(['active' => 'product', 'references' => ['product' => [12]]], 'web:a:guest');
    $state = $tokens->decode($first, 'web:a:guest');
    expect($tokens->decode($first, 'web:b:guest'))->toBe([]);
    $second = $tokens->encode([...$state, 'references' => ['product' => [15]]], 'web:a:guest');
    $stale = $tokens->encode([...$state, 'references' => ['product' => [99]]], 'web:a:guest');

    expect($tokens->decode($first, 'web:a:guest'))->toBe([]);
    expect($tokens->decode($stale, 'web:a:guest'))->toBe([]);
    expect($tokens->decode($second, 'web:a:guest')['references']['product'])->toBe([15]);
    expect(DB::table('chatbot_conversations')->count())->toBe(1);
});

test('expired context and new chats do not resume an old conversation', function () {
    $tokens = app(ChatbotConversationToken::class);
    $first = $tokens->encode(['active' => 'product'], 'web:a:guest');
    $this->travel(16)->minutes();
    expect($tokens->decode($first, 'web:a:guest'))->toBe([]);
    expect($tokens->decode(null, 'web:a:guest'))->toBe([]);
    $tokens->encode([], 'web:a:guest');
    expect(DB::table('chatbot_conversations')->count())->toBe(2);
});

test('anonymous mobile requests and disabled memory retain legacy token behavior without database history', function () {
    $tokens = app(ChatbotConversationToken::class);
    $token = $tokens->encode(['active' => 'product'], 'api:guest:guest');
    expect($tokens->decode($token, 'api:guest:guest'))->toBe(['active' => 'product']);
    config()->set('ai_integration.memory.enabled', false);
    $token = $tokens->encode(['active' => 'store'], 'web:a:guest');
    expect($tokens->decode($token, 'web:a:guest'))->toBe(['active' => 'store']);
    expect(DB::table('chatbot_conversations')->count())->toBe(0);
});

test('memory history excludes private details and retains only the configured recent turns', function () {
    config()->set('ai_integration.memory.max_messages', 2);
    $tokens = app(ChatbotConversationToken::class);
    $token = $tokens->encode(['active' => 'product'], 'web:a:guest');
    $state = $tokens->decode($token, 'web:a:guest');
    $memory = app(ChatbotMemory::class);
    $memory->recordTurn($state, 'web:a:guest', 'Contact me at private@example.test', 'Okay');
    foreach (['first', 'second', 'third'] as $message) {
        $memory->recordTurn($state, 'web:a:guest', $message, 'Catalog response');
    }
    $messages = json_decode(Crypt::decryptString(DB::table('chatbot_conversations')->sole()->messages), true);
    expect(array_column($messages, 'user'))->toBe(['second', 'third']);
    expect($memory->load($state['_memory'], 'web:a:guest'))->not->toHaveKey('messages');
});

test('pruning deletes only conversations past the hard retention deadline', function () {
    config()->set('ai_integration.memory.retention_days', 1);
    app(ChatbotConversationToken::class)->encode([], 'web:a:guest');
    $this->travel(2)->days();
    app(ChatbotConversationToken::class)->encode([], 'web:b:guest');
    $this->artisan('chatbot:prune-memory')->expectsOutput('Deleted 1 expired conversations.')->assertSuccessful();

    expect(DB::table('chatbot_conversations')->count())->toBe(1);
    expect(DB::table('chatbot_conversations')->sole()->subject_hash)->toBe(hash('sha256', 'web:b:guest'));
});

test('component references survive later product turns without replacing the selected product', function () {
    $board = Product::factory()->available()->create(['name' => 'Atlas Motherboard B650']);
    $gpu = Product::factory()->available()->create(['name' => 'Helios Graphics RTX4060']);
    ProductComponentProfile::factory()->create(['product_id' => $board->id, 'component_type' => 'motherboard']);
    ProductComponentProfile::factory()->create(['product_id' => $gpu->id, 'component_type' => 'graphics_card']);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Atlas Motherboard B650', null, null, 'web:parts');
    $second = $chat->respond('Helios Graphics RTX4060', null, $first['context_token'], 'web:parts');
    $state = app(ChatbotConversationToken::class)->decode($second['context_token'], 'web:parts:guest');

    expect($state['component_references'])->toBe(['motherboard' => [$board->id], 'graphics_card' => [$gpu->id]]);
    expect($state['references']['product'])->toBe([$gpu->id]);
});

test('legacy context upgrades to durable storage and disabling memory rejects durable context', function () {
    config()->set('ai_integration.memory.enabled', false);
    $tokens = app(ChatbotConversationToken::class);
    $legacy = $tokens->encode(['active' => 'product'], 'web:a:guest');
    config()->set('ai_integration.memory.enabled', true);
    $durable = $tokens->encode($tokens->decode($legacy, 'web:a:guest'), 'web:a:guest');
    expect($tokens->decode($durable, 'web:a:guest')['active'])->toBe('product');
    config()->set('ai_integration.memory.enabled', false);
    expect($tokens->decode($durable, 'web:a:guest'))->toBe([]);
});

test('corrupt persisted state fails closed without disclosing ciphertext or history', function () {
    $tokens = app(ChatbotConversationToken::class);
    $token = $tokens->encode(['active' => 'product'], 'web:a:guest');
    DB::table('chatbot_conversations')->update(['state' => 'corrupt']);
    expect($tokens->decode($token, 'web:a:guest'))->toBe([]);
});

test('web chatbot responses use durable memory without exposing stored history', function () {
    Product::factory()->available()->create(['name' => 'Atlas Monitor X240']);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => 'Atlas Monitor X240']);

    $response->assertOk()->assertJsonStructure(['message', 'source', 'context_token'])
        ->assertJsonMissingPath('messages')->assertJsonMissingPath('state');
    expect(DB::table('chatbot_conversations')->count())->toBe(1);
});

test('transcript identity remains available independently of the durable memory feature flag', function () {
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('chatbotMemory.enabled', true)
        ->where('chatbotMemory.identity', fn ($identity): bool => is_string($identity) && strlen($identity) === 64));

    config()->set('ai_integration.memory.enabled', false);
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('chatbotMemory.enabled', false)
        ->where('chatbotMemory.identity', fn ($identity): bool => is_string($identity) && strlen($identity) === 64));
});

test('administrators receive no customer memory resume identity', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('chatbotMemory.enabled', false)
        ->where('chatbotMemory.identity', null));
});
