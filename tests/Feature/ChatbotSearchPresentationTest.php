<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearch;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\PublicChatbotQuery;
use App\Services\Chatbot\SemanticCatalogCandidates;

beforeEach(function () {
    Product::disableSearchSyncing();
    config(['scout.driver' => 'algolia']);
});

test('branch discovery answers all published matches without a follow-up question', function () {
    $branches = array_map(fn (string $city): array => [
        'city' => $city, 'address' => $city.' address', 'contact_number' => '123',
        'email' => null, 'operating_hours' => '9 AM to 5 PM',
    ], ['Sagay', 'Bacolod']);
    $message = app(RenderChatbotFallback::class)->execute(
        ChatbotQueryCategory::Store, 'branches', ['branches' => $branches],
    );
    expect($message)->toContain('Sagay address', 'Bacolod address', '9 AM to 5 PM')
        ->not->toContain('Which branch', 'Please specify');
});

afterEach(function () {
    Product::enableSearchSyncing();
});

test('recognized device searches use local products before external search', function (string $query, string $category, string $name, string $accessory) {
    $category = Category::factory()->create(['name' => $category]);
    $product = Product::factory()->for($category)->available()->create(['name' => $name]);
    Product::factory()->for($category)->available()->create(['name' => $accessory, 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');
    $result = app(ResolveProductContext::class)->execute($query);
    expect(array_column($result['products'], 'id'))->toBe([$product->id]);
})->with([
    ['mouse', 'Peripherals', 'MOUSE LOGITECH M100', 'MOUSEPAD GAMING'],
    ['mice', 'Peripherals', 'MOUSE LOGITECH M100', 'MOUSEPAD GAMING'],
    ['CPU', 'Processor', 'AMD RYZEN 5 5600 PROCESSOR', 'CPU COOLER FAN'],
]);

test('short searches return live catalog facts for provider candidates', function (string $query) {
    config(['ai_integration.enabled' => false]);
    $product = Product::factory()->for(Category::factory()->create(['name' => 'Storage']))->available()->create(['name' => 'Kingston SSD 1TB Gaming Drive', 'price' => '2500.00']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([$product->id]);
    $result = app(ResolveProductContext::class)->execute($query);
    expect($result['products'][0]['id'])->toBe($product->id)
        ->and($result['products'][0]['price'])->toBe('2500.00');
})->with(['Kingston', 'Kingston drive', 'Kingston gaming drive', 'Kingston gaming solid drive', 'Kingston gaming solid state drive']);

test('empty indexed results fall back to current database products in the chatbot', function () {
    $product = Product::factory()->available()->create(['name' => 'Kingston Drive']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([]);
    expect(app(ResolveProductContext::class)->execute('Kingston')['products'][0]['id'])->toBe($product->id);
});

test('database fallback provides readable cards without generation', function () {
    config(['ai_integration.enabled' => false]);
    $product = Product::factory()->available()->create(['name' => 'Unique Kingston Drive', 'price' => '2500.00']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn(null);
    $result = app(ChatbotConversation::class)->respond('Kingston', null, null, 'search-test');
    expect($result['source'])->toBe('fallback')
        ->and($result['presentation']['products'][0]['id'])->toBe($product->id)
        ->and($result['message'])->toContain("\n\nUnique Kingston Drive\nPHP 2,500.00");
});

test('search candidates are bounded and current eligibility overrides indexed ids', function () {
    $products = Product::factory()->available()->count(5)->create(['brand' => 'Kingston']);
    $inactive = Product::factory()->available()->inactive()->create(['brand' => 'Kingston']);
    $unavailable = Product::factory()->create(['brand' => 'Kingston']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([...$products->modelKeys(), $inactive->id, $unavailable->id]);
    $products->first()->update(['price' => '1234.00', 'discount_price' => null, 'is_featured' => true]);
    $result = app(ResolveProductContext::class)->execute('Kingston');
    expect($result['products'])->toHaveCount(3);
    expect(array_column($result['products'], 'id'))->not->toContain($inactive->id, $unavailable->id);
    expect($result['products'][0]['price'])->toBe('1234.00');
});

test('web search response exposes cards and retains the text contract', function () {
    $product = Product::factory()->available()->create(['name' => 'Kingston Drive']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([$product->id]);
    $this->postJson('/chatbot', ['message' => 'Kingston'])->assertOk()
        ->assertJsonPath('source', 'fallback')
        ->assertJsonPath('presentation.products.0.id', $product->id)
        ->assertJsonStructure(['message', 'source', 'context_token', 'language', 'presentation']);
});

test('capacity replies refine the current category and expired context cannot supply it', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    $product = Product::factory()->for($category)->available()->create(['name' => 'Kingston SSD 1TB']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([$product->id]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Kingston', null, null, 'capacity-test');
    $next = $chat->respond('1TB', null, $first['context_token'], 'capacity-test');
    expect($next['presentation']['query'])->toBe('Storage 1TB');
    $this->travel(16)->minutes();
    $expired = $chat->respond('1TB', null, $first['context_token'], 'capacity-test');
    expect($expired['presentation']['query'] ?? null)->not->toBe('Storage 1TB');
});

test('printer questions use shared catalog intent and return machines rather than ink', function (string $question) {
    $category = Category::factory()->create(['name' => 'Printer']);
    $printer = Product::factory()->for($category)->available()->create(['name' => 'PRINTER EPSON L3210 INK TANK', 'price' => '9000.00', 'discount_price' => null]);
    foreach (['BLACK', 'CYAN', 'MAGENTA', 'YELLOW'] as $color) {
        Product::factory()->for($category)->available()->create(['name' => 'INK EPSON T664 '.$color, 'is_featured' => true]);
    }
    Product::factory()->for($category)->available()->create(['name' => 'Printer Cable USB', 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn(null);

    $result = app(ResolveProductContext::class)->execute($question);
    expect(array_column($result['products'], 'id'))->toBe([$printer->id])
        ->and($result['products'][0]['price'])->toBe('9000.00');
})->with(['printer', 'What printers do you have?', 'What is the price of a printer?', 'May ara kamo printer?', 'Do you have a printre?']);

test('chatbot device searches follow shared category and primary-product rules with indexed ids or database fallback', function (string $question, string $categoryName, string $deviceName, string $accessoryName, bool $indexed) {
    $category = Category::factory()->create(['name' => $categoryName]);
    $device = Product::factory()->for($category)->available()->create(['name' => $deviceName]);
    $accessory = Product::factory()->for($category)->available()->create(['name' => $accessoryName, 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn($indexed ? [$accessory->id, $device->id] : null);
    $result = app(ResolveProductContext::class)->execute($question);
    expect(array_column($result['products'], 'id'))->toBe([$device->id]);
})->with([
    ['Show me available monitors', 'Monitor', 'MONITOR ACER 24 INCH', 'Monitor Wall Mount Bracket'],
    ['Do you have a router?', 'Networking', 'ROUTER TP-LINK AX10', 'Router Cable Adapter'],
    ['What SSD do you have?', 'Storage', 'SSD KINGSTON 1TB', 'SSD Enclosure USB'],
    ['What keyboard is available?', 'Peripherals', 'KEYBOARD RAPOO K100', 'Keyboard Cover'],
    ['Show me a CPU cooler', 'Cooling Components', 'CPU COOLER DEEPCOOL AK400', 'CPU Thermal Paste'],
    ['Do you have a CPU coler?', 'Cooling Components', 'CPU COOLER DEEPCOOL AK400', 'CPU Thermal Paste'],
    ['Do you have UPS?', 'Power Accessories', 'UPS APC 650VA', 'UPS Battery Replacement'],
])->with([true, false]);

test('explicit consumable and accessory requests still return the requested products', function (string $query, string $categoryName, string $name) {
    $product = Product::factory()->for(Category::factory()->create(['name' => $categoryName]))->available()->create(['name' => $name]);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn(null);
    $result = app(ResolveProductContext::class)->execute($query);
    expect(array_column($result['products'], 'id'))->toBe([$product->id]);
})->with([
    ['Do you have printer ink?', 'Printer', 'INK EPSON T664'],
    ['What printer cable do you have?', 'Printer', 'Printer Cable USB'],
    ['Do you have an SSD enclosure?', 'Storage', 'SSD Enclosure USB'],
]);

test('a printer request never substitutes ink when no printer machine is available', function () {
    Product::factory()->for(Category::factory()->create(['name' => 'Printer']))->available()->create(['name' => 'INK EPSON T664']);
    $result = app(ResolveProductContext::class)->execute('What printers do you have?');
    expect($result['resolution'])->toBe('not_found')->and($result['products'])->toBe([]);
});

test('web chatbot answers printer questions with live product cards', function () {
    $printer = Product::factory()->for(Category::factory()->create(['name' => 'Printer']))->available()->create(['name' => 'PRINTER EPSON L3210']);
    $this->postJson(route('chatbot.store'), ['message' => 'What printers do you have?'])
        ->assertOk()->assertJsonPath('presentation.products.0.id', $printer->id);
});

test('shared chatbot search preserves low stock boundaries', function () {
    $product = Product::factory()->for(Category::factory()->create(['name' => 'Printer']))->available()->create(['name' => 'PRINTER EPSON L3210']);
    $product->inventory->update(['quantity' => 2, 'reorder_level' => 3]);
    $result = app(ResolveProductContext::class)->execute('What printers do you have?');
    expect($result['products'][0]['inventory'])->toBe(['quantity' => 2, 'status' => 'low_stock']);
});

test('AI-enabled printer discovery uses authoritative catalog intent without scope or generation calls', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.gemini.max_calls' => 3]);
    $printer = Product::factory()->for(Category::factory()->create(['name' => 'Printer']))->available()->create(['name' => 'PRINTER EPSON L3210']);
    $reply = app(ChatbotConversation::class)->respond('What printers do you have?', null, null, 'ai-printer');
    expect($reply['presentation']['products'][0]['id'])->toBe($printer->id);
    ChatbotIntentAgent::assertNeverPrompted();
});

test('public classifier vocabulary includes shared catalog terms without customer identity text', function () {
    expect(app(PublicChatbotQuery::class)->minimize('printer ink UPS Maria Santos'))->toBe('printer ink ups');
});

test('category understanding and device filtering work without Algolia', function () {
    config(['scout.driver' => 'collection']);
    $category = Category::factory()->create(['name' => 'Printer']);
    $printer = Product::factory()->for($category)->available()->create(['name' => 'PRINTER EPSON L3210']);
    Product::factory()->for($category)->available()->create(['name' => 'INK EPSON T664', 'is_featured' => true]);
    $this->mock(SemanticCatalogCandidates::class)->shouldNotReceive('ids');
    $result = app(ResolveProductContext::class)->execute('What printers do you have?');
    expect(array_column($result['products'], 'id'))->toBe([$printer->id]);
});

test('a usable indexed answer returns without invoking semantic retrieval', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $product = Product::factory()->available()->create(['name' => 'Kingston Drive']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([$product->id]);
    $this->mock(SemanticCatalogCandidates::class)->shouldNotReceive('ids');
    expect(app(ResolveProductContext::class)->execute('Kingston')['products'][0]['id'])->toBe($product->id);
});

test('unresolved indexed and database searches can use enabled semantic retrieval', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $product = Product::factory()->for(Category::factory()->create(['name' => 'Laptops & Desktops']))->available()->create(['name' => 'LAPTOP LENOVO IDEAPAD']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([]);
    $this->mock(SemanticCatalogCandidates::class)->shouldReceive('ids')->once()->with('portable study companion')->andReturn([$product->id]);
    $result = app(ResolveProductContext::class)->execute('portable study companion');
    expect(array_column($result['products'], 'id'))->toBe([$product->id]);
});

test('semantic fallback preserves requested brand and device type rather than substituting ink', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $category = Category::factory()->create(['name' => 'Printer']);
    $ink = Product::factory()->for($category)->available()->create(['name' => 'INK EPSON T664']);
    $wrongBrand = Product::factory()->for($category)->available()->create(['name' => 'PRINTER CANON G3010']);
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([]);
    $this->mock(SemanticCatalogCandidates::class)->shouldReceive('ids')->once()->andReturn([$ink->id, $wrongBrand->id]);
    expect(app(ResolveProductContext::class)->execute('Epson printer')['products'])->toBe([]);
});

test('web chatbot reaches semantic capabilities for a screened descriptive product request', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => true]);
    $product = Product::factory()->available()->create(['name' => 'LAPTOP LENOVO IDEAPAD']);
    ChatbotIntentAgent::fake([['outcome' => 'supported', 'category' => 'product', 'intent' => 'product_details', 'language' => 'en']])->preventStrayPrompts();
    $this->mock(CatalogSearch::class)->shouldReceive('matchingIds')->andReturn([]);
    $this->mock(SemanticCatalogCandidates::class)->shouldReceive('ids')->once()->andReturn([$product->id]);
    $this->postJson(route('chatbot.store'), ['message' => 'portable study companion'])
        ->assertOk()->assertJsonPath('presentation.actions.0.id', $product->id);
});

test('a recognized product category stays a product inquiry when only supplies exist', function () {
    Product::factory()->for(Category::factory()->create(['name' => 'Printer']))->available()->create(['name' => 'INK EPSON T664']);
    $this->postJson(route('chatbot.store'), ['message' => 'What printers do you have?'])
        ->assertOk()->assertJsonPath('message', "I couldn't find a matching product in the current Battlefront catalog. Please try the product name, brand, category, or tag.");
});

test('comparisons resolve named products through chatbot context without indexed discovery', function () {
    $first = Product::factory()->available()->create(['name' => 'Alpha']);
    $second = Product::factory()->available()->create(['name' => 'Beta']);
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');
    $result = app(ResolveProductContext::class)->execute('compare Alpha vs Beta');
    expect(array_column($result['products'], 'id'))->toBe([$first->id, $second->id])
        ->and($result)->not->toHaveKey('search_query');
});

test('recommendation retrieval uses chatbot facts while preserving the requested device type', function () {
    $category = Category::factory()->create(['name' => 'Printer']);
    $printer = Product::factory()->for($category)->available()->create(['name' => 'PRINTER EPSON L3210']);
    Product::factory()->for($category)->available()->create(['name' => 'INK EPSON T664', 'is_featured' => true]);
    $this->mock(CatalogSearch::class)->shouldNotReceive('matchingIds');
    $result = app(ResolveProductContext::class)->execute('printer', semanticMessage: 'recommend a printer under PHP 10000');
    expect(array_column($result['products'], 'id'))->toBe([$printer->id])
        ->and($result)->not->toHaveKey('search_query');
});
