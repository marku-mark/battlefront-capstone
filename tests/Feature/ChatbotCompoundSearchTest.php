<?php

use App\Ai\Agents\ChatbotMultipartUnderstandingAgent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;
use App\Services\Chatbot\ChatbotMultipartResponse;
use App\Services\Chatbot\ModelLedChatbot;
use Illuminate\Support\Facades\Http;

test('short Hiligaynon availability wording starts a fresh keyboard search after compound requests', function (string $question) {
    config(['scout.driver' => 'null', 'ai_integration.localization_enabled' => true]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    foreach (['RAM' => 'Atlas RAM', 'Printer' => 'PRINTER Atlas A100'] as $category => $name) {
        Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => $name]);
    }
    $keyboard = Product::factory()->available()->for(Category::factory()->state(['name' => 'Peripherals']))->create(['name' => 'Atlas Keyboard', 'price' => '900.00', 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);
    $previous = $chat->respond('may ara kamo ram kag printer sa sagay?', null, null, 'keyboard-wording');

    $reply = $chat->respond($question, null, $previous['context_token'], 'keyboard-wording');

    expect(array_column($reply['presentation']['products'], 'id'))->toBe([$keyboard->id]);
    expect($reply['language'])->toBe('hil');
    expect($reply['presentation']['query'] ?? '')->not->toContain('kamo', 'may ');
    Http::assertNothingSent();
})->with(['may keyboard kamo sa sagay?', 'may keyboard kamo?', 'may ara kamo keyboard sa sagay?']);

test('printer and motherboard discovery shares Sagay without searching the location as a product term', function () {
    config(['battlefront.operational_branch_city' => 'Sagay']);
    Branch::factory()->create(['city' => 'Sagay']);
    $printer = Product::factory()->available()->for(Category::factory()->state(['name' => 'Printer']))->create(['name' => 'PRINTER CANON PIXMA E410']);
    $board = Product::factory()->available()->for(Category::factory()->state(['name' => 'Motherboard']))->create(['name' => 'MSI B550 Motherboard']);
    $parts = app(ChatbotMultipartResponse::class)->categoryList('do you have printer and motherboard in sagay?');
    expect($parts)->toHaveCount(2);
    foreach ($parts as $part) {
        expect($part['branch'])->toBe('Sagay');
        expect($part['search_query'])->not->toContain('sagay');
    }
    $reply = app(ChatbotConversation::class)->respond('do you have printer and motherboard in sagay?', null, null, 'sagay-compound');
    expect($reply['message'])->toContain($printer->name, $board->name, 'Product name:', 'Price:', 'Availability:');
    expect($reply['presentation']['sections'])->toHaveCount(2);
    Http::assertNothingSent();
});

test('shared branch locations do not turn any requested product section into an empty search', function (string $question, array $families) {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $products = [];
    foreach (['RAM' => 'Atlas RAM', 'Monitor' => 'Atlas Monitor', 'Printer' => 'PRINTER Atlas A100', 'Networking' => 'Atlas Router'] as $category => $name) {
        $products[$category] = Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => $name, 'price' => 1500, 'discount_price' => null]);
    }

    $reply = app(ChatbotConversation::class)->respond($question, null, null, 'shared-location');

    expect($reply['presentation']['sections'])->toHaveCount(2);
    foreach ($families as $index => $family) {
        expect(array_column($reply['presentation']['sections'][$index]['products'], 'id'))->toContain($products[$family]->id);
    }
    Http::assertNothingSent();
})->with([
    ['may ara kamo ram kag monitor sa sagay?', ['RAM', 'Monitor']],
    ['may ara kamo monitor kag ram sa sagay?', ['Monitor', 'RAM']],
    ['may ara kamo printer kag monitor sa sagay?', ['Printer', 'Monitor']],
    ['may ara kamo router kag monitor sa sagay?', ['Networking', 'Monitor']],
    ['meron kayong RAM at monitor sa Sagay?', ['RAM', 'Monitor']],
    ['naa moy RAM ug monitor sa Sagay?', ['RAM', 'Monitor']],
    ['Do you have RAM and monitor in Sagay?', ['RAM', 'Monitor']],
    ['Do you have RAM and monitor at the Sagay City branch?', ['RAM', 'Monitor']],
    ['may ara kamo RAM kag monitor diri sa Sagay City nga branch?', ['RAM', 'Monitor']],
    ['sa Sagay, may ara kamo RAM kag monitor?', ['RAM', 'Monitor']],
    ['RAM under PHP 2000 kag monitor under PHP 2000 sa Sagay', ['RAM', 'Monitor']],
    ['RAM kag monitor Sagay', ['RAM', 'Monitor']],
    ['Sagay RAM kag monitor', ['RAM', 'Monitor']],
]);

test('simple public catalog questions remain eligible for model led understanding', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.full_catalog_pipeline' => false]);
    expect(app(ModelLedChatbot::class)->eligible('Do you have motherboards?'))->toBeTrue();
});

test('current availability wording does not hide products in a branchless compound search', function (string $question, array $families) {
    config(['scout.driver' => 'null', 'ai_integration.localization_enabled' => true]);
    $names = ['Monitor' => 'Acer Monitor', 'Motherboard' => 'Biostar H610M Motherboard', 'RAM' => 'Kingston Fury RAM', 'Printer' => 'PRINTER CANON PIXMA E410'];
    $products = [];
    foreach ($families as $family) {
        $products[] = Product::factory()->available()->for(Category::factory()->state(['name' => $family]))->create(['name' => $names[$family]]);
    }

    $reply = app(ChatbotConversation::class)->respond($question, null, null, 'current-stock');

    expect($reply['presentation']['sections'])->toHaveCount(2);
    foreach ($products as $index => $product) {
        expect(array_column($reply['presentation']['sections'][$index]['products'], 'id'))->toBe([$product->id]);
    }
    Http::assertNothingSent();
})->with([
    'reported Tagalog wording' => ['meron kayo monitor at motherboard ngayon?', ['Monitor', 'Motherboard']],
    'reversed Tagalog categories' => ['meron kayo motherboard at monitor ngayon?', ['Motherboard', 'Monitor']],
    'Hiligaynon' => ['may ara kamo RAM kag printer subong?', ['RAM', 'Printer']],
    'Cebuano' => ['naa moy printer ug RAM karon?', ['Printer', 'RAM']],
    'English' => ['Do you have motherboard and monitor right now?', ['Motherboard', 'Monitor']],
    'English timing with a conjunction' => ['Do you have a motherboard and monitor at the moment?', ['Motherboard', 'Monitor']],
]);

test('model-led compound search retains a model filter when the interpreted query includes stock timing', function () {
    config(['scout.driver' => 'null', 'ai_integration.enabled' => true, 'ai_integration.model_led' => true,
        'ai_integration.multipart' => true, 'ai_integration.gemini.max_calls' => 1]);
    $monitor = Product::factory()->available()->for(Category::factory()->state(['name' => 'Monitor']))->create(['name' => 'Acer Monitor']);
    $category = Category::factory()->create(['name' => 'Motherboard']);
    $board = Product::factory()->available()->for($category)->create(['name' => 'Biostar H610M Motherboard']);
    Product::factory()->available()->for($category)->create(['name' => 'Biostar B450M Motherboard']);
    $parts = app(ChatbotMultipartResponse::class)->categoryList('monitor and motherboard');
    $parts[1]['search_query'] = 'H610M motherboard ngayon';
    ChatbotMultipartUnderstandingAgent::fake([[...$parts[0], 'parts' => $parts, 'parts_overflow' => false]])->preventStrayPrompts();

    $reply = app(ChatbotConversation::class)->respond('meron kayo monitor at H610M motherboard ngayon?', null, null, 'model-current-stock');

    expect($reply['presentation']['sections'])->toHaveCount(2);
    expect(array_column($reply['presentation']['sections'][0]['products'], 'id'))->toBe([$monitor->id]);
    expect(array_column($reply['presentation']['sections'][1]['products'], 'id'))->toBe([$board->id]);
    ChatbotMultipartUnderstandingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'H610M'));
    Http::assertNothingSent();
});

test('local compound catalog requests return both categories without providers', function (string $question) {
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Kingston Fury RAM 16GB']);
    $board = Product::factory()->available()->for(Category::factory()->state(['name' => 'Motherboard']))->create(['name' => 'MSI B550 Motherboard']);
    $reply = app(ChatbotConversation::class)->respond($question, null, null, 'compound-test');
    expect($reply['message'])->toContain($ram->name, $board->name);
    expect($reply['presentation']['sections'])->toHaveCount(2);
    Http::assertNothingSent();
})->with(['may ara kamo ram kag motherboard?', 'Do you have RAM and motherboard?', 'price of RAM and motherboard']);

test('specific consumable keywords retain brand and product type in catalog selection', function () {
    $category = Category::factory()->create(['name' => 'Printer']);
    $ink = Product::factory()->available()->for($category)->create(['name' => 'Brother Ink Black Bottle', 'brand' => 'Brother']);
    Product::factory()->available()->for($category)->create(['name' => 'Epson Ink Bottle', 'brand' => 'Epson']);
    Product::factory()->available()->for($category)->create(['name' => 'Brother Printer DCP', 'brand' => 'Brother']);
    $result = app(ChatbotCatalogSelection::class)->select('Brother ink', ['intent' => 'discovery', 'category_key' => 'printer', 'search_query' => 'Brother ink', 'product_mentions' => [], 'attributes' => [], 'maximum_php' => null, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => '', 'reference' => 'new', 'position' => 0]);
    expect(array_column($result['products'], 'id'))->toBe([$ink->id]);
    $reply = app(ChatbotConversation::class)->respond('may ara kamo Brother ink?', null, null, 'ink-test');
    expect($reply['message'])->toContain($ink->name)->not->toContain('Epson Ink Bottle', 'Brother Printer DCP');
    Http::assertNothingSent();
});

test('shared local branch cleanup preserves each sections brand budget and live stock filters', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $ramCategory = Category::factory()->create(['name' => 'RAM']);
    $monitorCategory = Category::factory()->create(['name' => 'Monitor']);
    $ram = Product::factory()->available()->for($ramCategory)->create(['name' => 'Kingston RAM A100', 'brand' => 'Kingston', 'price' => 1500, 'discount_price' => null]);
    $monitor = Product::factory()->available()->for($monitorCategory)->create(['name' => 'Acer Monitor A100', 'brand' => 'Acer', 'price' => 2500, 'discount_price' => null]);
    Product::factory()->available()->for($ramCategory)->create(['name' => 'Kingston RAM A200', 'brand' => 'Kingston', 'price' => 2000, 'discount_price' => null]);
    Product::factory()->available()->for($ramCategory)->create(['name' => 'Corsair RAM A100', 'brand' => 'Corsair', 'price' => 1000, 'discount_price' => null]);
    Product::factory()->available()->for($monitorCategory)->create(['name' => 'Acer Monitor A200', 'brand' => 'Acer', 'price' => 4000, 'discount_price' => null]);
    Product::factory()->available()->for($monitorCategory)->create(['name' => 'Asus Monitor A100', 'brand' => 'Asus', 'price' => 2000, 'discount_price' => null]);
    $unavailable = Product::factory()->available()->for($monitorCategory)->create(['name' => 'Acer Monitor A300', 'brand' => 'Acer', 'price' => 1500, 'discount_price' => null]);
    $unavailable->inventory()->update(['quantity' => 0]);

    $reply = app(ChatbotConversation::class)->respond('Kingston RAM under PHP 2000 kag Acer monitor under PHP 3000 sa Sagay', null, null, 'branch-filters');

    expect(array_column($reply['presentation']['sections'][0]['products'], 'id'))->toBe([$ram->id]);
    expect(array_column($reply['presentation']['sections'][1]['products'], 'id'))->toBe([$monitor->id]);
    Http::assertNothingSent();
});

test('structured branch requests do not put exclusions and price words back into catalog matching', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Monitor']);
    $match = Product::factory()->available()->for($category)->create(['name' => 'Acer Monitor A100', 'brand' => 'Acer', 'price' => 2500, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Monitor A100', 'brand' => 'Asus', 'price' => 2000, 'discount_price' => null]);
    $intent = app(ChatbotMultipartResponse::class)->categoryList('RAM and monitor')[1];
    $intent = [...$intent, 'search_query' => 'monitor not Asus under PHP 3000 sa Sagay', 'excluded_brands' => ['Asus'], 'branch' => 'Sagay'];

    $context = app(ChatbotCatalogSelection::class)->select($intent['search_query'], $intent);

    expect(array_column($context['products'], 'id'))->toBe([$match->id]);
    Http::assertNothingSent();
});

function compoundCatalogFixture(): array
{
    $memory = Category::factory()->create(['name' => 'RAM']);
    $board = Category::factory()->create(['name' => 'Motherboard']);

    return [
        Product::factory()->available()->for($memory)->create(['name' => 'Kingston Fury RAM 16GB', 'brand' => 'Kingston', 'price' => 1500, 'discount_price' => null]),
        Product::factory()->available()->for($memory)->create(['name' => 'Kingston Fury RAM 32GB', 'brand' => 'Kingston', 'price' => 4500, 'discount_price' => null]),
        Product::factory()->available()->for($board)->create(['name' => 'MSI B550 Motherboard', 'brand' => 'MSI', 'price' => 3000, 'discount_price' => null]),
        Product::factory()->available()->for($board)->create(['name' => 'MSI X870 Motherboard', 'brand' => 'MSI', 'price' => 7000, 'discount_price' => null]),
    ];
}

test('an ordinal follow up resolves within its section and refreshes the selected product', function () {
    $products = compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RAM and motherboard', null, null, 'ordinal-section');
    $sections = $first['presentation']['sections'];
    $selected = $sections[0]['products'][1]['id'];
    $product = Product::findOrFail($selected);
    $product->update(['price' => 4321, 'discount_price' => null]);
    $reply = $chat->respond('price of the second RAM', null, $first['context_token'], 'ordinal-section');
    expect($reply['presentation']['sections'][0]['products'])->toHaveCount(1);
    expect($reply['presentation']['sections'][0]['products'][0]['id'])->toBe($selected);
    expect($reply['message'])->toContain('4,321.00')->not->toContain($products[2]->name);
    Http::assertNothingSent();
});

test('compound section follow ups preserve separate constraints and requery current stock', function (bool $modelLed) {
    config(['ai_integration.enabled' => $modelLed, 'ai_integration.model_led' => $modelLed, 'ai_integration.multipart' => $modelLed]);
    $products = compoundCatalogFixture();
    $parts = app(ChatbotMultipartResponse::class)->categoryList('RAM under PHP 2000 and motherboard under PHP 5000');
    ChatbotMultipartUnderstandingAgent::fake([[...$parts[0], 'parts' => $parts, 'parts_overflow' => false]])->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RAM under PHP 2000 and motherboard under PHP 5000', null, null, 'compound-state');
    expect($first['message'])->toContain($products[0]->name, $products[2]->name)->not->toContain($products[1]->name, $products[3]->name);
    $second = $chat->respond('RAM under PHP 5000', null, $first['context_token'], 'compound-state');
    expect($second['message'])->toContain($products[0]->name, $products[1]->name)->not->toContain($products[2]->name);
    $products[2]->inventory()->update(['quantity' => 0]);
    $third = $chat->respond('motherboard under PHP 8000', null, $second['context_token'], 'compound-state');
    expect($third['message'])->toContain($products[3]->name)->not->toContain($products[2]->name, $products[1]->name);
    Http::assertNothingSent();
})->with([false, true]);

test('ambiguous trailing budgets clarify and resume for both or one section', function (string $answer, bool $both) {
    $products = compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RAM and motherboard under PHP 2000', null, null, 'shared-budget');
    expect($first['message'])->toContain('both product types');
    $second = $chat->respond($answer, null, $first['context_token'], 'shared-budget');
    expect($second['message'])->toContain($products[0]->name)->not->toContain($products[3]->name);
    if ($both) {
        expect($second['message'])->not->toContain($products[2]->name, $products[1]->name);
    } else {
        expect($second['message'])->toContain($products[1]->name)->not->toContain($products[2]->name);
    }
})->with([['both', true], ['motherboard only', false]]);

test('explicit shared budgets apply to both sections without clarification', function () {
    $products = compoundCatalogFixture();
    $reply = app(ChatbotConversation::class)->respond('RAM and motherboard both under PHP 5000', null, null, 'shared-explicit');
    expect($reply['message'])->toContain($products[0]->name, $products[1]->name, $products[2]->name)->not->toContain($products[3]->name);
});

test('anonymous compound refinements ask for a section and retain the pending budget', function () {
    $products = compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RAM and motherboard', null, null, 'anonymous-refine');
    $second = $chat->respond('under PHP 2000', null, $first['context_token'], 'anonymous-refine');
    expect($second['message'])->toContain('Which product section');
    $third = $chat->respond('RAM', null, $second['context_token'], 'anonymous-refine');
    expect($third['message'])->toContain($products[0]->name)->not->toContain($products[1]->name, $products[2]->name);
});

test('three local requests ask to narrow without returning partial results', function (bool $modelLed) {
    config(['ai_integration.enabled' => $modelLed, 'ai_integration.model_led' => $modelLed, 'ai_integration.multipart' => $modelLed]);
    compoundCatalogFixture();
    $reply = app(ChatbotConversation::class)->respond('RAM and motherboard and monitor', null, null, 'overflow');
    expect($reply['message'])->toContain('up to two separate requests');
    expect($reply['presentation']['products'])->toBe([]);
    Http::assertNothingSent();
})->with([false, true]);

test('explicit shared brands filter both product sections', function () {
    $products = compoundCatalogFixture();
    $products[2]->update(['brand' => 'Kingston', 'name' => 'Kingston Motherboard']);
    $reply = app(ChatbotConversation::class)->respond('RAM and motherboard both Kingston', null, null, 'brand-shared');
    expect($reply['message'])->toContain($products[0]->name, $products[2]->name)->not->toContain($products[3]->name);
});

test('price ranges inside a compound product request are not split into extra products', function () {
    $products = compoundCatalogFixture();
    $reply = app(ChatbotConversation::class)->respond('RAM between PHP 1000 and PHP 2000 and motherboard', null, null, 'range-compound');
    expect($reply['message'])->toContain($products[0]->name, $products[2]->name)->not->toContain($products[1]->name);
    expect($reply['presentation']['sections'])->toHaveCount(2);
});

test('compound context is rejected for another identity or after expiry', function () {
    compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'identity-a');
    $tokens = app(ChatbotConversationToken::class);
    expect($tokens->decode($reply['context_token'], 'identity-b:guest'))->toBe([]);
    $this->travel(16)->minutes();
    expect($tokens->decode($reply['context_token'], 'identity-a:guest'))->toBe([]);
});

test('repeated refinements of one section preserve the other section budget', function () {
    $products = compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM under PHP 2000 and motherboard under PHP 5000', null, null, 'repeat-section');
    foreach (['RAM under PHP 5000', 'RAM under PHP 2000'] as $message) {
        $reply = $chat->respond($message, null, $reply['context_token'], 'repeat-section');
    }
    $reply = $chat->respond('motherboard price', null, $reply['context_token'], 'repeat-section');
    expect($reply['message'])->toContain($products[2]->name)->not->toContain($products[3]->name);
});

test('anonymous capacity and budget removal refinements survive section clarification', function () {
    $products = compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM under PHP 2000 and motherboard under PHP 5000', null, null, 'pending-modifiers');
    $reply = $chat->respond('no budget', null, $reply['context_token'], 'pending-modifiers');
    expect($reply['message'])->toContain('Which product section');
    $reply = $chat->respond('RAM', null, $reply['context_token'], 'pending-modifiers');
    expect($reply['message'])->toContain($products[0]->name, $products[1]->name);
    $reply = $chat->respond('32GB', null, $reply['context_token'], 'pending-modifiers');
    expect($reply['message'])->toContain('Which product section');
    $reply = $chat->respond('RAM', null, $reply['context_token'], 'pending-modifiers');
    expect($reply['message'])->toContain($products[1]->name)->not->toContain($products[0]->name);
});

test('a new product topic clears old compound refinements', function () {
    compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'new-topic');
    $reply = $chat->respond('monitor', null, $reply['context_token'], 'new-topic');
    $state = app(ChatbotConversationToken::class)->decode($reply['context_token'], 'new-topic:guest');
    expect($state)->not->toHaveKey('multipart_sections');
});

test('named section brand changes preserve its budget and do not reuse old brand keywords', function () {
    $products = compoundCatalogFixture();
    $category = $products[0]->category;
    $corsair = Product::factory()->available()->for($category)->create(['name' => 'Corsair RAM 16GB', 'brand' => 'Corsair', 'price' => 1800, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Corsair RAM Expensive', 'brand' => 'Corsair', 'price' => 6000, 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('Kingston RAM under PHP 2000 and MSI motherboard under PHP 5000', null, null, 'brand-refine');
    $reply = $chat->respond('Corsair RAM instead', null, $reply['context_token'], 'brand-refine');
    expect($reply['message'])->toContain($corsair->name)->not->toContain($products[0]->name, 'Corsair RAM Expensive');
    $reply = $chat->respond('RAM any brand', null, $reply['context_token'], 'brand-refine');
    expect($reply['message'])->toContain($corsair->name, $products[0]->name)->not->toContain($products[1]->name);
});

test('branch questions escape compound product state and pending section clarification', function (string $question, bool $pending) {
    compoundCatalogFixture();
    Branch::factory()->create(['name' => 'Battlefront Sagay', 'city' => 'Sagay City', 'address' => 'Published Sagay address']);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'branch-topic');
    if ($pending) {
        $reply = $chat->respond('under PHP 2000', null, $reply['context_token'], 'branch-topic');
        expect($reply['message'])->toContain('Which product section');
    }

    $reply = $chat->respond($question, null, $reply['context_token'], 'branch-topic');

    expect($reply['message'])->toContain('Sagay', str_contains($question, 'hours') ? '8:00 AM' : 'Published Sagay address')->not->toContain('Which product section');
    expect($reply['presentation']['products'])->toBe([]);
    expect(array_column($reply['presentation']['actions'], 'kind'))->toContain('branches');
    $state = app(ChatbotConversationToken::class)->decode($reply['context_token'], 'branch-topic:guest');
    expect($state)->not->toHaveKeys(['multipart_sections', 'pending_section', 'pending_compound']);
    $again = $chat->respond('branch in sagay?', null, $reply['context_token'], 'branch-topic');
    expect($again['message'])->toContain('Published Sagay address')->not->toContain('Which product section');
    Http::assertNothingSent();
})->with(['may ara branch sa sagay?', 'branch in sagay?', 'Do you have a branch in Sagay?', 'What are your Sagay opening hours?'])->with([false, true]);

test('new support topics do not inherit a pending product section', function (string $question, array $actions) {
    compoundCatalogFixture();
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'support-topic');
    $reply = $chat->respond('under PHP 2000', null, $reply['context_token'], 'support-topic');

    $reply = $chat->respond($question, null, $reply['context_token'], 'support-topic');

    expect($reply['message'])->not->toContain('Which product section');
    expect(array_column($reply['presentation']['actions'], 'kind'))->toBe($actions);
    Http::assertNothingSent();
})->with([
    ['Where is my order?', ['login']],
    ['What payment methods do you accept?', []],
    ['Tell me a joke', ['catalog']],
]);

test('a priced new product family does not refine an old section', function () {
    compoundCatalogFixture();
    $monitor = Product::factory()->available()->for(Category::factory()->state(['name' => 'Monitor']))->create(['name' => 'Atlas Monitor']);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'new-family');

    $reply = $chat->respond('price of monitor', null, $reply['context_token'], 'new-family');

    expect($reply['message'])->toContain($monitor->name)->not->toContain('Which product section');
    Http::assertNothingSent();
});

test('independent keyword and brand searches end old compound prompts', function (string $question, string $category, string $name, string $brand) {
    compoundCatalogFixture();
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => $category]))
        ->create(['name' => $name, 'brand' => $brand]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('RAM and motherboard', null, null, 'new-keyword');
    $pending = $chat->respond('under PHP 2000', null, $first['context_token'], 'new-keyword');
    expect($pending['message'])->toContain('Which product section');

    $reply = $chat->respond($question, null, $pending['context_token'], 'new-keyword');

    expect(array_column($reply['presentation']['products'], 'id'))->toBe([$product->id]);
    expect($reply['presentation']['choices'])->toBe([]);
    expect(app(ChatbotConversationToken::class)->decode($reply['context_token'], 'new-keyword:guest'))
        ->not->toHaveKeys(['multipart_sections', 'pending_section', 'pending_compound']);
    Http::assertNothingSent();
})->with([
    'bare brand' => ['brother?', 'Printer', 'BROTHER INK BTD100BK', 'Brother'],
    'keyword outside the saved families' => ['pad', 'Cooling Components', 'Laptop Cooler Pad Mikuso NCP-078', 'Mikuso'],
    'Hiligaynon availability' => ['may ara kamo blower?', 'Cleaning & Maintenance Supplies', 'Electric Blower Upspirit', 'Upspirit'],
    'Cebuano availability' => ['naa moy charger?', 'Power Accessories', 'Charger for Laptop Acer', 'Acer'],
    'Tagalog availability' => ['meron kayo coin?', 'Vending & Coin-Op Machine Parts', 'COINSLOT ALLAN 1239', 'Allan'],
]);

test('a subtype from an active compound search selects only its section', function () {
    $storage = Category::factory()->create(['name' => 'Storage']);
    $ssd = Product::factory()->available()->for($storage)->create(['name' => 'Atlas SSD 512GB']);
    $hdd = Product::factory()->available()->for($storage)->create(['name' => 'Atlas HDD 1TB']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('SSD and HDD', null, null, 'storage-sections');

    $reply = $chat->respond('HDD price', null, $first['context_token'], 'storage-sections');

    expect($reply['message'])->toContain($hdd->name)->not->toContain($ssd->name, 'Which product section');
});

test('compound requests distinguish a mouse from a mouse pad', function () {
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    $mouse = Product::factory()->available()->for($peripherals)->create(['name' => 'Atlas Gaming Mouse']);
    $pad = Product::factory()->available()->for($peripherals)->create(['name' => 'Atlas Mouse-Pad']);

    $reply = app(ChatbotConversation::class)->respond('mouse and mouse pad', null, null, 'mouse-types');

    expect(array_column($reply['presentation']['sections'], 'heading'))->toBe(['Mouse', 'Mouse pads']);
    expect(array_column($reply['presentation']['sections'][0]['products'], 'id'))->toBe([$mouse->id]);
    expect(array_column($reply['presentation']['sections'][1]['products'], 'id'))->toBe([$pad->id]);
});

test('pad coin and blower stay independent over consecutive turns after a compound search', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Atlas Laptop']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas Laptop RAM']);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('laptop and RAM', null, null, 'keyword-sequence');
    expect($reply['presentation']['sections'])->toHaveCount(2);

    foreach ([
        ['pad', 'Cooling Components', 'Laptop Cooler Pad Mikuso NCP-078'],
        ['coin', 'Vending & Coin-Op Machine Parts', 'COINSLOT ALLAN 1239'],
        ['may ara kamo blower?', 'Cleaning & Maintenance Supplies', 'Electric Blower Upspirit'],
    ] as [$question, $category, $name]) {
        $product = Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => $name]);
        $reply = $chat->respond($question, null, $reply['context_token'], 'keyword-sequence');

        expect(array_column($reply['presentation']['products'], 'id'))->toBe([$product->id]);
        expect($reply['presentation']['choices'])->toBe([]);
    }
});
