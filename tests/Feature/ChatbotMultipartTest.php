<?php

use App\Ai\Agents\ChatbotMultipartAnswerAgent;
use App\Ai\Agents\ChatbotMultipartUnderstandingAgent;
use App\Ai\Agents\ChatbotValidationAgent;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotFailureTrace;
use App\Services\Chatbot\ChatbotMultipartResponse;
use App\Services\Chatbot\ChatbotProviderBudget;
use App\Services\Chatbot\ModelLedChatbot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function multipartIntent(array $overrides = []): array
{
    return array_replace(['outcome' => 'supported', 'intent' => 'facts', 'language' => 'en', 'category_key' => 'storage',
        'search_query' => 'Atlas SSD', 'product_mentions' => ['Atlas SSD'], 'attributes' => ['price', 'stock'],
        'maximum_php' => null, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '',
        'reference' => 'new', 'position' => 0, 'clarification' => 'none'], $overrides);
}

beforeEach(function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.multipart' => true,
        'ai_integration.localization_enabled' => true,
        'ai_integration.gemini.max_calls' => 1, 'ai_integration.gemini.tokens_per_minute' => 100000]);
    ChatbotMultipartUnderstandingAgent::fake()->preventStrayPrompts();
    ChatbotMultipartAnswerAgent::fake()->preventStrayPrompts();
    ChatbotValidationAgent::fake()->preventStrayPrompts();
});

test('multipart answers public needs in order using live facts and one understanding call', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD', 'price' => 2000, 'discount_price' => null]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM', 'price' => 3000, 'discount_price' => null]);
    $parts = [multipartIntent(), multipartIntent(['category_key' => 'memory', 'search_query' => 'Atlas RAM', 'product_mentions' => ['Atlas RAM']])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();

    $result = app(ModelLedChatbot::class)->respond('Price and stock of Atlas SSD? Also price and stock of Atlas RAM?', [], null);

    expect($result['message'])->toContain('SSD:', 'Atlas SSD', '2,000.00', 'RAM:', 'Atlas RAM', '3,000.00');
    expect(strpos($result['message'], 'Atlas SSD'))->toBeLessThan(strpos($result['message'], 'Atlas RAM'));
    expect($result['source'])->toBe('fallback');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    expect($result['state'])->not->toHaveKey('model_constraints');
    Http::assertNothingSent();
});

test('model-led discovery treats laptop RAM as one product type even if understanding splits it', function () {
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'ADATA DDR5 16GB SODIMM']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Laptop with RAM']);
    $laptop = multipartIntent(['intent' => 'discovery', 'category_key' => 'laptop', 'search_query' => 'laptop', 'product_mentions' => []]);
    $memory = multipartIntent(['intent' => 'discovery', 'category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []]);
    ChatbotMultipartUnderstandingAgent::fake([[...$laptop, 'branch' => 'Sagay', 'parts' => [$laptop, $memory], 'parts_overflow' => false]])->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => 'laptop ram'])->assertOk();

    expect(array_column($response->json('presentation.products'), 'id'))->toBe([$ram->id]);
    expect($response->json('presentation.sections') ?? [])->toBe([]);
});

test('a standalone brand starts a model-led search across categories even after a specific product search', function () {
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM', 'brand' => 'Atlas']);
    $printer = Product::factory()->available()->for(Category::factory()->state(['name' => 'Printer']))->create(['name' => 'PRINTER Brother DCP', 'brand' => 'Brother']);
    $memory = multipartIntent(['intent' => 'discovery', 'category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []]);
    ChatbotMultipartUnderstandingAgent::fake([
        [...$memory, 'parts' => [], 'parts_overflow' => false],
        [...$memory, 'reference' => 'previous', 'operation' => 'refine', 'brand' => 'Brother', 'parts' => [], 'parts_overflow' => false],
    ])->preventStrayPrompts();
    $first = $this->postJson('/chatbot', ['message' => 'RAM?'])->assertOk();
    expect(array_column($first->json('presentation.products'), 'id'))->toBe([$ram->id]);

    $reply = $this->postJson('/chatbot', ['message' => 'brother?', 'context_token' => $first->json('context_token')])->assertOk();

    expect(array_column($reply->json('presentation.products'), 'id'))->toBe([$printer->id]);
    $reply->assertJsonPath('presentation.choices', []);
});

test('multipart overflow asks to narrow instead of silently dropping questions', function () {
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => [], 'parts_overflow' => true])])->preventStrayPrompts();

    $result = app(ModelLedChatbot::class)->respond('Compare four different product needs for me', [], null);

    expect($result['message'])->toContain('up to two separate requests');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    Http::assertNothingSent();
});

test('brand follow-ups report the category budget and products selected by the requested operation', function (string $question, string $operation, string $category, bool $retainsBudget) {
    $printers = Category::factory()->create(['name' => 'Printer']);
    $affordable = Product::factory()->available()->for($printers)->create(['name' => 'PRINTER Brother DCP', 'brand' => 'Brother', 'price' => 6000, 'discount_price' => null]);
    $expensive = Product::factory()->available()->for($printers)->create(['name' => 'PRINTER Brother MFC', 'brand' => 'Brother', 'price' => 12000, 'discount_price' => null]);
    $initial = multipartIntent(['intent' => 'discovery', 'category_key' => 'printer', 'search_query' => 'printer', 'product_mentions' => []]);
    $followUp = [...$initial, 'category_key' => $operation === 'refine' ? 'printer' : $category, 'search_query' => $operation === 'refine' ? 'printer' : 'Brother',
        'operation' => $operation, 'reference' => $operation === 'refine' ? 'previous' : 'new', 'brand' => 'Brother'];
    ChatbotMultipartUnderstandingAgent::fake([[...$initial, 'maximum_php' => 8000], $followUp])->preventStrayPrompts();

    $first = app(ModelLedChatbot::class)->respond('Printers under PHP 8,000?', [], null);
    expect(array_column($first['presentation']['products'], 'id'))->toBe([$affordable->id]);
    $reply = app(ModelLedChatbot::class)->respond($question, $first['state'], null);

    $products = array_column($reply['presentation']['products'], 'id');
    sort($products);
    expect([
        'category' => $reply['state']['model_constraints']['category_key'],
        'maximum' => (float) ($reply['state']['model_constraints']['price']['maximum'] ?? 0),
        'products' => $products,
    ])->toBe([
        'category' => $category,
        'maximum' => $retainsBudget ? 8000.0 : 0.0,
        'products' => $retainsBudget ? [$affordable->id] : [$affordable->id, $expensive->id],
    ]);
})->with([
    'bare brand within a recognized printer search' => ['brother?', 'refine', 'printer', true],
    'explicit refinement' => ['Only Brother printers within that budget', 'refine', 'printer', true],
    'explicit new search' => ['Start a new search for any Brother products', 'new_search', 'other', false],
]);

test('malformed multipart understanding is rejected', function (mixed $parts, mixed $overflow = false) {
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => $overflow])])->preventStrayPrompts();

    expect(app(ChatbotAiAdapter::class)->understand('Atlas SSD and RAM?', []))->toBeNull();
    expect(app(ChatbotFailureTrace::class)->event)->toBe(['reason' => 'understanding_invalid_output', 'stage' => 'scope']);
    Http::assertNothingSent();
})->with([
    'too many' => [[multipartIntent(), multipartIntent(), multipartIntent(), multipartIntent()]],
    'not a list' => [['first' => multipartIntent()]],
    'invalid part' => [[['intent' => 'arbitrary_sql']]],
    'null parts' => [null],
    'null overflow' => [[], null],
    'sensitive part' => [[multipartIntent(['search_query' => 'password: private-value'])]],
]);

test('private messages never qualify for multipart provider understanding', function () {
    expect(app(ModelLedChatbot::class)->eligible('Where is my BF-123 order? Also recommend RAM.'))->toBeFalse();
    ChatbotMultipartUnderstandingAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('public multiple questions qualify without bypassing private support screening', function () {
    config(['ai_integration.full_catalog_pipeline' => true]);

    expect(app(ModelLedChatbot::class)->eligible('What is the price of Atlas SSD? Where is Sagay branch?'))->toBeTrue();
    expect(app(ModelLedChatbot::class)->eligible('Where is my order? What is the price of Atlas SSD?'))->toBeFalse();
    expect(app(ModelLedChatbot::class)->eligible('What is the price of Atlas SSD? Is my payment verified?'))->toBeFalse();
    Http::assertNothingSent();
});

test('recognized payment FAQs stay deterministic across public pipeline modes', function (string $question, bool $full, bool $multipart) {
    config(['ai_integration.full_catalog_pipeline' => $full, 'ai_integration.multipart' => $multipart]);

    $response = $this->postJson('/chatbot', ['message' => $question]);

    $response->assertOk()->assertJsonPath('source', 'fallback');
    expect($response->json('message'))->toContain('GCash', 'Maya');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(0);
    ChatbotMultipartUnderstandingAgent::assertNeverPrompted();
    ChatbotMultipartAnswerAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    'single question' => 'What payment methods can I use?',
    'conjunction' => 'Do you accept GCash and Maya?',
    'punctuation' => 'Do you accept GCash? And Maya?',
])->with([
    'model led' => [false, false],
    'model led multipart' => [false, true],
    'full multipart' => [true, true],
]);

test('independent support topics clarify before public model understanding', function () {
    config(['ai_integration.full_catalog_pipeline' => true]);

    $response = $this->postJson('/chatbot', ['message' => 'What payment methods can I use? How do I cancel an order?']);

    $response->assertOk()->assertJsonPath('source', 'fallback');
    expect($response->json('message'))->toContain('Which would you like to ask about first');
    ChatbotMultipartUnderstandingAgent::assertNeverPrompted();
    ChatbotMultipartAnswerAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('active custom FAQs and payment follow ups retain direct routing', function () {
    config(['ai_integration.full_catalog_pipeline' => true]);
    ChatbotKnowledge::factory()->create([
        'category' => 'faq', 'is_active' => true,
        'question_pattern' => 'What assistance can I request?',
        'response_template' => 'Ask about Battlefront products and store services.',
    ]);
    $response = $this->postJson('/chatbot', ['message' => 'What assistance can I request?']);
    $response->assertOk()->assertJsonPath('source', 'fallback')
        ->assertJsonPath('message', 'Ask about Battlefront products and store services.');

    $followUp = $this->postJson('/chatbot', ['message' => 'Do you accept GCash and Maya?', 'context_token' => $response->json('context_token')]);
    $followUp->assertOk()->assertJsonPath('source', 'fallback');
    expect($followUp->json('message'))->toContain('GCash', 'Maya');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(0);
    ChatbotMultipartUnderstandingAgent::assertNeverPrompted();
    ChatbotMultipartAnswerAgent::assertNeverPrompted();
    ChatbotValidationAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('multipart public replies reach the customer endpoint with every section intact', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD']);
    $parts = [multipartIntent(), multipartIntent(['intent' => 'support', 'search_query' => 'What payment methods can I use?', 'product_mentions' => []])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();

    $response = $this->postJson('/chatbot', ['message' => 'What is the price of Atlas SSD? What payment methods can I use?']);

    $response->assertOk()->assertJsonPath('source', 'fallback');
    expect($response->json('message'))->toContain('SSD:', 'Atlas SSD', 'Support:');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    Http::assertNothingSent();
});

test('a model supplied private part cannot open order lookup', function () {
    $parts = [multipartIntent(['intent' => 'private', 'search_query' => 'Where is my order?'])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();

    $result = app(ModelLedChatbot::class)->respond('What products do you carry?', [], null);

    expect($result['message'])->toContain('ask about your order separately');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(1);
    Http::assertNothingSent();
});

test('combined multipart generation requires complete scoped evidence and review', function (string $mode) {
    config(['ai_integration.gemini.max_calls' => 3]);
    $ssd = Product::factory()->available()->for(Category::factory()->state(['name' => 'Storage']))->create(['name' => 'Atlas SSD', 'price' => 2000, 'discount_price' => null]);
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM', 'price' => 3000, 'discount_price' => null]);
    $parts = [multipartIntent(), multipartIntent(['category_key' => 'memory', 'search_query' => 'Atlas RAM', 'product_mentions' => ['Atlas RAM']])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $answers = [
        ['part_id' => 'a', 'answer' => "Here are the matching SSD options.\n\nAtlas SSD costs PHP 2,000.00. In stock at Sagay.", 'evidence_ids' => ['a:product:'.$ssd->id]],
        ['part_id' => 'b', 'answer' => 'Atlas RAM costs PHP 3,000.00. In stock at Sagay.', 'evidence_ids' => ['b:product:'.$ram->id]],
    ];
    if ($mode === 'missing_part') {
        array_pop($answers);
    } elseif ($mode === 'foreign_evidence') {
        $answers[0]['evidence_ids'] = ['b:product:'.$ram->id];
    } elseif ($mode === 'wrong_price') {
        $answers[0]['answer'] = 'Atlas SSD costs PHP 3,000.00.';
    }
    ChatbotMultipartAnswerAgent::fake([['answers' => $answers]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => $mode === 'rejected' ? 'reject' : 'approve']])->preventStrayPrompts();
    if ($mode === 'review_unavailable') {
        ChatbotValidationAgent::fake(fn () => throw new RuntimeException('private-provider-detail'))->preventStrayPrompts();
    }

    $result = app(ModelLedChatbot::class)->respond('Price and stock of Atlas SSD? Also Atlas RAM?', [], null);

    expect($result['source'])->toBe($mode === 'approved' ? 'gemini' : 'fallback');
    if ($mode === 'approved') {
        expect($result['presentation']['sections'][0]['intro'])->toBe('Here are the matching SSD options.');
        expect($result['presentation']['sections'][0]['products'][0]['price'])->toBe('2000.00');
        expect($result['presentation']['sections'][0]['products'][0]['labels'])->toMatchArray([
            'name' => 'Product name', 'price' => 'Price', 'stock' => 'Availability', 'view' => 'View Product',
        ]);
    }
    expect($result['message'])->toContain('SSD:', 'Atlas SSD', '2,000.00', 'RAM:', 'Atlas RAM', '3,000.00');
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(in_array($mode, ['approved', 'rejected', 'review_unavailable'], true) ? 3 : 2);
    expect(Cache::get('chatbot:role:'.now()->format('Ymd').':scope:responses'))->toBe(1);
    expect(Cache::get('chatbot:role:'.now()->format('Ymd').':response:responses'))->toBe(1);
    expect($result['message'])->not->toContain('private-provider-detail');
    expect($result['presentation']['sections'][0]['intro'])->not->toBe('');
    if (! in_array($mode, ['approved', 'rejected', 'review_unavailable'], true)) {
        ChatbotValidationAgent::assertNeverPrompted();
    }
    Http::assertNothingSent();
})->with(['approved', 'rejected', 'missing_part', 'foreign_evidence', 'wrong_price', 'review_unavailable']);

test('one section follow ups retain reviewed model wording and authoritative product cards', function () {
    config(['ai_integration.gemini.max_calls' => 3]);
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM', 'price' => 3000, 'discount_price' => null]);
    $part = multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => [], 'intent' => 'discovery']);
    ChatbotMultipartAnswerAgent::fake([['answers' => [
        ['part_id' => 'a', 'answer' => "Here is the matching RAM option.\n\nAtlas RAM costs PHP 3,000.00. In stock at Sagay.", 'evidence_ids' => ['a:product:'.$ram->id]],
    ]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();
    app(ChatbotProviderBudget::class)->reset();
    $reply = app(ChatbotMultipartResponse::class)->respond([$part], false, [], null, 'en');
    expect($reply['source'])->toBe('gemini');
    expect($reply['message'])->toContain('Here is the matching RAM option.', 'Product name:', 'Price:', 'Availability:', 'Link: View Product')
        ->not->toContain('Atlas RAM costs PHP 3,000.00');
    expect($reply['presentation']['sections'][0]['products'][0]['id'])->toBe($ram->id);
    Http::assertNothingSent();
});

test('a rejected model reply retains a natural fallback opening in the users language', function (string $language, string $opening) {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM']);
    $part = multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => [], 'intent' => 'discovery']);
    config(['ai_integration.offline' => true]);
    app(ChatbotProviderBudget::class)->reset();
    $reply = app(ChatbotMultipartResponse::class)->respond([$part], false, [], null, $language);
    expect($reply['source'])->toBe('fallback');
    expect($reply['presentation']['sections'][0]['intro'])->toContain($opening);
    expect($reply['presentation']['sections'][0]['products'][0]['name'])->toBe('Atlas RAM');
    Http::assertNothingSent();
})->with([['hil', 'Ari ang'], ['ceb', 'Ania ang'], ['tl', 'Narito po'], ['en', 'Here are']]);

test('reviewed multipart fields are not duplicated beside authoritative product cards', function (bool $hasOpening) {
    config(['ai_integration.gemini.max_calls' => 3]);
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM', 'price' => 3000, 'discount_price' => null]);
    $part = multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => [], 'intent' => 'discovery']);
    $answer = ($hasOpening ? "Here are the matching RAM options.\n\n" : '')
        ."Product name: Atlas RAM\nPrice: PHP 3,000.00\nAvailability: In stock at Sagay\nLink: View Product";
    ChatbotMultipartAnswerAgent::fake([['answers' => [
        ['part_id' => 'a', 'answer' => $answer, 'evidence_ids' => ['a:product:'.$ram->id]],
    ]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve', 'reason' => 'none']])->preventStrayPrompts();
    app(ChatbotProviderBudget::class)->reset();

    $reply = app(ChatbotMultipartResponse::class)->respond([$part], false, [], null, 'en');

    expect($reply['source'])->toBe('gemini');
    expect(substr_count($reply['message'], 'Product name: Atlas RAM'))->toBe(1);
    expect($reply['presentation']['sections'][0]['intro'])->not->toBe('')->not->toContain('Product name:', 'Link:');
    expect($reply['presentation']['sections'][0]['products'][0]['id'])->toBe($ram->id);
    ChatbotValidationAgent::assertPromptedTimes(1);
    Http::assertNothingSent();
})->with([true, false]);

test('verified empty sections can receive reviewed model explanations without fabricated cards', function () {
    config(['ai_integration.gemini.max_calls' => 3]);
    $part = multipartIntent(['category_key' => 'motherboard', 'search_query' => 'motherboard', 'product_mentions' => [], 'intent' => 'discovery']);
    ChatbotMultipartAnswerAgent::fake([['answers' => [
        ['part_id' => 'a', 'answer' => 'No available motherboard products match your constraints.', 'evidence_ids' => ['a:selection:no_match_message']],
    ]]])->preventStrayPrompts();
    ChatbotValidationAgent::fake([['verdict' => 'approve']])->preventStrayPrompts();
    app(ChatbotProviderBudget::class)->reset();
    $reply = app(ChatbotMultipartResponse::class)->respond([$part], false, [], null, 'en');
    expect($reply['source'])->toBe('gemini');
    expect($reply['presentation']['sections'][0]['intro'])->toContain('No available motherboard');
    expect($reply['presentation']['sections'][0]['products'])->toBe([]);
    Http::assertNothingSent();
});

test('role usage aggregates are bounded to known roles and never negative', function () {
    $budget = app(ChatbotProviderBudget::class);
    $budget->recordRoleUsage('scope', 10, 4);
    $budget->recordRoleUsage('scope', -5, 6);
    $budget->recordRoleUsage('secret-value', 100, 100);
    $prefix = 'chatbot:role:'.now()->format('Ymd').':';

    expect(Cache::get($prefix.'scope:responses'))->toBe(2);
    expect(Cache::get($prefix.'scope:input_tokens'))->toBe(10);
    expect(Cache::get($prefix.'scope:output_tokens'))->toBe(10);
    expect(Cache::has($prefix.'secret-value:responses'))->toBeFalse();
});

test('conjunction and comma product inquiries reach multipart understanding', function (string $message) {
    config(['ai_integration.full_catalog_pipeline' => false]);
    expect(app(ModelLedChatbot::class)->eligible($message))->toBeTrue();
})->with(['Can I have motherboard and RAM?', 'motherboard, RAM, SSD', 'May ara kamo motherboard kag RAM?', 'Naa moy motherboard ug RAM?', 'Mayroon ba kayong motherboard at RAM?']);

test('multipart overflow responds in the current language at the customer endpoint', function (string $message, string $language, string $expected) {
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['language' => $language, 'parts' => [], 'parts_overflow' => true])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => $message]);
    $response->assertOk()->assertJsonPath('source', 'fallback');
    expect($response->json('message'))->toContain($expected)->not->toContain('A)');
    ChatbotMultipartAnswerAgent::assertNeverPrompted();
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(0);
    Http::assertNothingSent();
})->with([
    ['Can I have motherboard, RAM, SSD and keyboard?', 'en', 'up to two separate requests'],
    ['Mayroon ba kayong motherboard, RAM, SSD at keyboard?', 'tl', 'Hanggang dalawang'],
    ['Naa moy motherboard, RAM, SSD ug keyboard?', 'ceb', 'Hangtod duha'],
    ['May ara kamo motherboard, RAM, SSD kag keyboard?', 'hil', 'Hasta duha'],
]);

test('a product list receives every category through the customer endpoint', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Motherboard']))->create(['name' => 'Atlas Motherboard']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM']);
    $parts = [multipartIntent(['category_key' => 'motherboard', 'search_query' => 'motherboard', 'product_mentions' => []]), multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'Can I have motherboard and RAM?']);
    $response->assertOk();
    expect($response->json('message'))->toContain('Motherboards:', 'Atlas Motherboard', 'RAM:', 'Atlas RAM');
    Http::assertNothingSent();
});

test('three independent requests ask to narrow even when products are missing', function () {
    $parts = [multipartIntent(), multipartIntent(['category_key' => 'memory', 'search_query' => 'Unknown RAM']), multipartIntent(['category_key' => 'motherboard', 'search_query' => 'Unknown Motherboard'])];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $result = app(ModelLedChatbot::class)->respond('SSD, RAM and motherboard', [], null);
    expect($result['message'])->toContain('up to two separate requests')->not->toContain('SSD:', 'RAM:', 'Motherboards:');
    Http::assertNothingSent();
});

test('three category list is rejected before provider interpretation', function () {
    foreach (['RAM', 'Motherboard', 'Monitor'] as $category) {
        Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => 'Verified '.$category]);
    }
    $parts = array_map(fn (array $part): array => multipartIntent($part), [
        ['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []],
        ['category_key' => 'motherboard', 'search_query' => 'motherboard', 'product_mentions' => []],
        ['category_key' => 'monitor', 'search_query' => 'monitor', 'product_mentions' => []],
    ]);
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'can i have ram, motherboard and monitor?']);
    $response->assertOk();
    expect($response->json('message'))->toContain('up to two separate requests')->not->toContain('Verified RAM', 'Verified Motherboard', 'Verified Monitor');
    ChatbotMultipartUnderstandingAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

test('oversized availability lists do not silently return partial categories', function (string $message) {
    foreach (['RAM', 'Motherboard', 'Monitor'] as $category) {
        Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => 'Recovery '.$category]);
    }
    ChatbotMultipartUnderstandingAgent::fake(fn () => throw new RuntimeException('provider unavailable'))->preventStrayPrompts();

    $result = app(ModelLedChatbot::class)->respond($message, [], null);

    expect($result['message'])->toContain('up to two separate requests')->not->toContain('Recovery RAM', 'Recovery Motherboard', 'Recovery Monitor');
    Http::assertNothingSent();
})->with(['do you have ram, motherboard, monitor?', 'do you have ram, motherboard and monitor?', 'can i have ram, motherboard, and monitor?']);

test('multipart sections limit products and retain empty categories', function () {
    $category = Category::factory()->create(['name' => 'RAM']);
    Product::factory()->available()->count(5)->for($category)->create();
    ChatbotMultipartUnderstandingAgent::fake(fn () => throw new RuntimeException('unavailable'))->preventStrayPrompts();
    $result = app(ModelLedChatbot::class)->respond('do you have RAM and monitor?', [], null);
    expect(substr_count($result['message'], 'PHP '))->toBe(3);
    expect($result['message'])->toContain('RAM:', 'Monitors:', 'no available products');
    Http::assertNothingSent();
});

test('multipart requests use labeled product sections rather than plain product paragraphs', function () {
    config(['ai_integration.localization_enabled' => true]);
    foreach (['RAM', 'Peripherals'] as $category) {
        Product::factory()->available()->for(Category::factory()->state(['name' => $category]))
            ->create(['name' => $category === 'RAM' ? 'Example RAM' : 'Example Keyboard', 'price' => 900, 'discount_price' => null]);
    }
    $parts = [
        multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []]),
        multipartIntent(['category_key' => 'other', 'search_query' => 'wifi', 'product_mentions' => []]),
    ];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['language' => 'tl', 'parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'meron kayo ram, wifi?']);
    $response->assertOk()->assertJsonPath('presentation.intro', '');
    $sections = $response->json('presentation.sections');
    expect(array_column($sections, 'heading'))->toBe(['RAM', 'Wi-Fi']);
    expect($sections[0]['products'][0])->toMatchArray(['name' => 'Example RAM', 'price' => '900.00']);
    expect($sections[0]['products'][0]['labels'])->toMatchArray(['name' => 'Pangalan ng produkto', 'price' => 'Presyo', 'stock' => 'Katayuan ng stock']);
    expect($sections[1]['products'])->toBe([]);
    expect($sections[1]['intro'])->toContain('Wi-Fi adapter');
    expect(array_column($response->json('presentation.actions'), 'kind'))->toBe(['catalog']);
    expect($response->json('message'))->not->toContain('Discovery:', 'Peripherals:');
    Http::assertNothingSent();
});

test('multipart headings describe requested products instead of internal intents', function (string $query, string $heading) {
    expect(app(ChatbotMultipartResponse::class)->heading([
        'search_query' => $query, 'intent' => 'discovery',
    ]))->toBe($heading);
})->with([
    ['wifi', 'Wi-Fi'], ['keyboard', 'Keyboards'], ['mouse', 'Mouse'],
    ['SSD', 'SSD'], ['headset', 'Headsets'], ['unrecognized item', 'Products'],
]);

test('local list recovery retains distinct product types and punctuation variants', function (string $message, array $queries) {
    $parts = app(ChatbotMultipartResponse::class)->categoryList($message);
    expect(array_map(fn (array $part): string => mb_strtolower($part['search_query']), $parts))->toBe($queries);
})->with([
    ['keyboard, mouse, monitor', ['keyboard', 'mouse', 'monitor']],
    ['do you have SSD and HDD?', ['ssd', 'hdd']],
    ['RAM; motherboard; monitor', ['ram', 'motherboard', 'monitor']],
    ['RAM
motherboard
monitor', ['ram', 'motherboard', 'monitor']],
    ['RAM / monitor', ['ram', 'monitor']],
    ['RAM & monitor', ['ram', 'monitor']],
    ['meron kayo keyboard, mouse?', ['keyboard', 'mouse']],
    ['may ara kamo keyboard kag mouse?', ['keyboard', 'mouse']],
    ['naa moy keyboard ug mouse?', ['keyboard', 'mouse']],
    ['mayroon ba kayong keyboard at mouse?', ['keyboard', 'mouse']],
    ['RAM, memory, monitor', ['memory', 'monitor']],
]);

test('local list recovery preserves per-product constraints and guards reasoning and private questions', function (string $message) {
    $parts = app(ChatbotMultipartResponse::class)->categoryList($message);
    if ($message === 'RAM under PHP 2,000 and monitor') {
        expect($parts)->toHaveCount(2);
        expect($parts[0]['search_query'])->toBe('RAM under PHP 2,000');
        expect($parts[1]['search_query'])->toBe('monitor');
    } else {
        expect($parts)->toBe([]);
    }
})->with([
    'compare RAM and motherboard', 'RAM compatible with motherboard',
    'RAM under PHP 2,000 and monitor', 'RAM and my order status',
    'RAM and delivery fee', 'RAM and unknown item', 'keyboard with mouse',
]);

test('incomplete model interpretation recovers every standalone category with labeled cards', function () {
    $category = Category::factory()->create(['name' => 'Peripherals']);
    foreach (['Keyboard', 'Mouse'] as $name) {
        Product::factory()->available()->for($category)->create(['name' => 'Recovery '.$name]);
    }
    $partial = multipartIntent(['category_key' => 'other', 'search_query' => 'keyboard', 'product_mentions' => []]);
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => [$partial], 'parts_overflow' => false])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'keyboard and mouse']);
    $response->assertOk();
    expect(array_column($response->json('presentation.sections'), 'heading'))->toBe(['Keyboards', 'Mouse']);
    expect($response->json('presentation.sections.0.products.0.name'))->toBe('Recovery Keyboard');
    expect($response->json('presentation.sections.1.products.0.name'))->toBe('Recovery Mouse');
    Http::assertNothingSent();
});

test('mixed support sections retain their navigation action beside catalog browsing', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Example RAM']);
    $parts = [
        multipartIntent(['category_key' => 'memory', 'search_query' => 'RAM', 'product_mentions' => []]),
        multipartIntent(['intent' => 'store', 'search_query' => 'branches', 'product_mentions' => []]),
    ];
    ChatbotMultipartUnderstandingAgent::fake([multipartIntent(['parts' => $parts, 'parts_overflow' => false])])->preventStrayPrompts();
    $response = $this->postJson('/chatbot', ['message' => 'RAM? Where are your branches?']);
    $response->assertOk();
    expect(array_column($response->json('presentation.actions'), 'kind'))->toBe(['catalog', 'branches']);
    expect($response->json('presentation.sections.0.products.0.name'))->toBe('Example RAM');
    Http::assertNothingSent();
});
