<?php

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Ai\Agents\ChatbotResponseAgent;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotGroundedResponse;
use App\Services\Chatbot\ChatbotLanguage;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;

test('ordinary security questions do not count as submitted secrets', function (string $question) {
    expect(app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question))->toBeFalse();
})->with(['How do I reset my password?', 'Where do I enter the OTP?', 'What if my PIN does not work?']);

test('submitted secrets are rejected before any provider call', function (string $question) {
    expect(app(ClassifyChatbotInquiry::class)->containsSensitiveInput($question))->toBeTrue();
    $reply = app(ChatbotConversation::class)->respond($question, null, null, 'private-input');
    expect($reply['presentation']['actions'])->toBe([]);
})->with(['My OTP is 123456', 'password: secret-value', 'My PIN 1234', 'api_key=private-value']);

test('shopping normalization preserves exact models and negation', function () {
    expect(app(ChatbotLanguage::class)->canonical('pls prcie RTX 4060 Ti, not AMD, under 5k'))
        ->toBe('price RTX 4060 Ti, not AMD, under 5000');
    expect(app(ChatbotLanguage::class)->canonical('LG 5K Monitor'))->toBe('LG 5K Monitor');
});

test('CPU inquiries resolve processors and preserve accessory and model names', function () {
    $language = app(ChatbotLanguage::class);
    expect($language->canonical('CPU'))->toBe('processor')
        ->and($language->canonical('price of cpu'))->toBe('price of processor')
        ->and($language->canonical('CPU cooler'))->toBe('CPU cooler')
        ->and($language->canonical('CPU-Z'))->toBe('CPU-Z');
    $category = Category::factory()->create(['name' => 'Processor']);
    $product = Product::factory()->for($category)->available()->create(['name' => 'AMD Ryzen 5 5600', 'price' => '5000.00', 'discount_price' => null]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $result = app(ChatbotConversation::class)->respond('price of CPU', null, null, 'processor-alias');
    expect($result['message'])->toContain('AMD Ryzen 5 5600', 'PHP 5,000.00')
        ->and($result['presentation']['actions'][0]['id'])->toBe($product->id);
});

test('related price and stock requests return both current facts and a grounded link', function () {
    $product = Product::factory()->available()->create(['name' => 'Atlas SSD X100', 'price' => '2500.00', 'discount_price' => null]);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $result = app(ChatbotConversation::class)->respond('price of Atlas SSD X100 and stock availability', null, null, 'related');
    expect($result['message'])->toContain('2,500.00', 'Sagay stock:')
        ->and($result['presentation']['actions'][0])->toMatchArray(['kind' => 'product', 'id' => $product->id]);
});

test('ordinal selection resolves the displayed product order and expiry cannot revive it', function () {
    Product::factory()->available()->create(['name' => 'Atlas Monitor A100', 'price' => '1000.00']);
    $second = Product::factory()->available()->create(['name' => 'Atlas Monitor B100', 'price' => '2000.00']);
    ChatbotResponseAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('monitor', null, null, 'ordinal');
    $reply = $chat->respond('price of the second option', null, $first['context_token'], 'ordinal');
    expect($reply['message'])->toContain($second->name)
        ->and($reply['presentation']['actions'][0]['id'])->toBe($second->id);
    $this->travel(16)->minutes();
    $expired = $chat->respond('price of the second option', null, $first['context_token'], 'ordinal');
    expect($expired['presentation']['actions'][0]['id'] ?? null)->not->toBe($second->id);
});

test('guest transcript identity is shared even when durable AI memory is disabled', function () {
    config(['ai_integration.memory.enabled' => false]);
    $this->get(route('branches.index'))->assertInertia(fn (Assert $page) => $page
        ->where('chatbotMemory.enabled', false)
        ->where('chatbotMemory.identity', fn ($identity): bool => is_string($identity) && strlen($identity) === 64));
});

test('multilingual related product questions keep one intent and both requested attributes', function (string $message) {
    $intent = app(ChatbotLanguage::class)->interpret($message);
    expect($intent['attributes'])->toBe(['price', 'stock']);
})->with([
    'English' => 'price and stock of Atlas SSD',
    'Tagalog' => 'Magkano ang Atlas SSD at may stock ba?',
    'Cebuano' => 'Tagpila ang Atlas SSD ug naa pa ba?',
    'Hiligaynon' => 'Pila ang Atlas SSD kag may ara pa kamo?',
]);

test('constraint corrections reuse category and replace the budget without dropping exclusions', function () {
    config(['ai_integration.advice_enabled' => true, 'ai_integration.offline' => true]);
    $category = Category::factory()->create(['name' => 'Processors']);
    Product::factory()->for($category)->available()->create(['name' => 'AMD Processor A', 'brand' => 'AMD', 'price' => '2500.00', 'discount_price' => null]);
    $intel = Product::factory()->for($category)->available()->create(['name' => 'Intel Processor B', 'brand' => 'Intel', 'price' => '2000.00', 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('recommend Processors under 5k not AMD', null, null, 'constraints');
    $second = $chat->respond('my budget is 3k', null, $first['context_token'], 'constraints');
    expect($second['message'])->toContain($intel->name)->not->toContain('AMD Processor A');
});

test('a price assigned to the wrong product fails grounded validation even when the number exists in evidence', function () {
    $renderer = app(ChatbotGroundedResponse::class);
    $evidence = $renderer->evidence(['products' => [
        ['id' => 1, 'name' => 'Atlas SSD A', 'price' => '1000.00', 'inventory' => ['status' => 'in_stock']],
        ['id' => 2, 'name' => 'Atlas SSD B', 'price' => '2000.00', 'inventory' => ['status' => 'in_stock']],
    ]]);
    expect($renderer->draft($evidence, ['answer' => 'Atlas SSD A has a current price of PHP 2,000.00.', 'evidence_ids' => ['product:1', 'product:2']]))->toBeNull();
    expect($renderer->draft($evidence, ['answer' => 'Atlas SSD A has a current price of PHP 1,000.00.', 'evidence_ids' => ['product:1']]))->not->toBeNull();
});

test('offline evaluation reports labeled routing attribute and clarification accuracy', function () {
    $disk = $this->fakeIsolatedStorage('local');
    $disk->put('understanding.json', json_encode([[
        'id' => 'public-product-typo', 'language' => 'en', 'messages' => ['What is the prcie of Missing SSD X100?'],
        'expected_category' => 'product', 'expected_attributes' => ['price'], 'expected_clarification' => false,
    ]]));
    expect(Artisan::call('chatbot:evaluate', ['--dataset' => $disk->path('understanding.json')]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['summary']['en']['routing_accuracy'])->toBe(1)
        ->and($report['summary']['en']['attributes_accuracy'])->toBe(1)
        ->and($report['summary']['en']['clarification_accuracy'])->toBe(1);
});
