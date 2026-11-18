<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Actions\Chatbot\RenderChatbotFallback;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearch;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotPresentation;
use App\Services\Chatbot\PublicChatbotQuery;
use App\Services\Chatbot\SemanticCatalogCandidates;

test('conversational stock summaries leave one natural opening above authoritative cards', function (string $summary, string $opening) {
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product, 'source' => 'gemini',
        'message' => $summary."\n\nCheck your connector before buying.",
    ], ['products' => [
        ['id' => 7, 'name' => 'AOC Gaming Keyboard GK410"', 'price' => '1500.00', 'inventory' => ['status' => 'low_stock', 'quantity' => 2]],
        ['id' => 8, 'name' => 'Atlas Keyboard', 'price' => '650.00', 'inventory' => ['status' => 'low_stock', 'quantity' => 1]],
    ]], 'may keyboard kamo?', null, 'hil');

    expect($presentation['intro'])->toBe($opening."\n\nCheck your connector before buying.");
    expect(array_column($presentation['products'], 'id'))->toBe([7, 8]);
})->with([
    ['May ara kami sang mga keyboard sa Sagay branch: AOC Gaming Keyboard GK410 (PHP 1,500.00, 2 left), and Atlas Keyboard (PHP 650.00, 1 left).', 'May ara kami sang mga keyboard sa Sagay branch:'],
    ['May ara kami sang mga keyboard sa Sagay branch. AOC Gaming Keyboard GK410 (PHP 1,500.00, 2 left), and Atlas Keyboard (PHP 650.00, 1 left).', 'May ara kami sang mga keyboard sa Sagay branch.'],
]);

test('interpreted filters offer explicit removals without being counted as clarification', function () {
    $presentation = app(ChatbotPresentation::class)->build(['category' => ChatbotQueryCategory::Product, 'message' => 'Matching products.'], [
        'selection_constraints' => ['brand' => 'Atlas', 'excluded_brands' => ['Beacon'], 'price' => ['maximum' => '3000.00', 'maximum_inclusive' => false]],
    ], 'RAM under PHP 3000', null, 'tl');
    expect($presentation['clarification'])->toBeFalse();
    expect($presentation['facts'][0]['value'])->toContain('Atlas', '3,000.00', 'Beacon');
    expect($presentation['choices'])->toHaveCount(2);
    foreach ($presentation['choices'] as $index => $choice) {
        expect(mb_strtolower(app(ChatbotLanguage::class)->canonical($choice['question'])))->toBe($index === 0 ? 'any brand' : 'any price');
    }
});

beforeEach(function () {
    Product::disableSearchSyncing();
    config(['scout.driver' => 'algolia']);
});

test('availability replies present catalog price and stock below the introduction', function () {
    config(['ai_integration.localization_enabled' => true]);
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product,
        'message' => "Oo, meron po.\n\nExample Laptop.\nOo. Stock sa Sagay: 1 ang available.",
    ], ['products' => [[
        'id' => 7, 'name' => 'Example Laptop', 'price' => '2200.00',
        'inventory' => ['status' => 'in_stock', 'quantity' => 5],
    ]]], 'meron po kayo laptop?', null, 'tl');

    expect($presentation['intro'])->toBe('Oo, meron po.')
        ->and($presentation['products'][0]['name'])->toBe('Example Laptop')
        ->and($presentation['products'][0]['price'])->toBe('2200.00')
        ->and($presentation['products'][0]['labels']['stock'])->toBe('Katayuan ng stock')
        ->and($presentation['products'][0]['stock'])->toBe('May stock sa Sagay — 5 ang available')
        ->and($presentation['facts'])->toBe([]);
});

test('product introductions and quantities follow the response language', function (string $language, string $intro, string $stock) {
    config(['ai_integration.localization_enabled' => true]);
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product,
        'message' => 'Example Laptop. Stock details.',
    ], ['products' => [[
        'id' => 7, 'name' => 'Example Laptop', 'price' => '2200.00',
        'inventory' => ['status' => 'out_of_stock', 'quantity' => 0],
    ]]], 'laptop', null, $language);

    expect($presentation['intro'])->toBe($intro)
        ->and($presentation['products'][0]['stock'])->toBe($stock);
})->with([
    ['en', 'Here are the products matching your request.', 'Out of stock at Sagay — 0 available'],
    ['tl', 'Narito po ang mga produktong tugma sa hinahanap ninyo.', 'Walang stock sa Sagay — 0 ang available'],
]);

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

test('presentation preserves complete reasoning and clarification instead of cutting at product names', function (string $question, array $extra, string $message) {
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product, 'message' => $message,
    ], [...$extra, 'products' => [[
        'id' => 7, 'name' => 'Example Laptop', 'price' => '2200.00',
        'inventory' => ['status' => 'in_stock', 'quantity' => 5],
    ]]], $question, null, 'en');

    expect($presentation['intro'])->toBe($message)
        ->and($presentation['products'])->toBe([]);
})->with([
    'comparison' => ['Compare Example Laptop with another laptop', [], 'Example Laptop costs less. Performance evidence is unavailable.'],
    'recommendation' => ['Recommend a laptop', [], 'Consider Example Laptop. Verified performance specifications are unavailable.'],
    'compatibility' => ['Will these parts work?', ['compatibility' => ['status' => 'unknown']], 'Example Laptop: [unknown] Missing reviewed evidence.'],
    'clarification' => ['laptop', ['clarification' => 'Which product do you mean?'], 'Which product do you mean? Example Laptop or another model?'],
]);

test('listing intros never leave a dangling bullet or number before product cards', function (string $marker) {
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product,
        'message' => 'Here are the matching products.

'.$marker.' Example Laptop - PHP 2,200.00.',
    ], ['products' => [[
        'id' => 7, 'name' => 'Example Laptop', 'price' => '2200.00',
        'inventory' => ['status' => 'in_stock'],
    ]]], 'laptop', null, 'en');

    expect($presentation['intro'])->toBe('Here are the matching products.')
        ->and($presentation['products'])->toHaveCount(1);
})->with(['-', '*', '1.', '1)']);

test('reviewed labeled product lists are rendered once as authoritative cards', function (string $language, string $nameLabel, string $priceLabel, string $stockLabel) {
    $message = "Matching RAM options:\n\n{$nameLabel}: Atlas RAM\n{$priceLabel}: PHP 900.00\n{$stockLabel}: 5 available\nLink: View Product\n\n"
        ."{$nameLabel}: Orion RAM\n{$priceLabel}: PHP 1,600.00\n{$stockLabel}: 3 available\nLink: View Product\n\nMemory generations differ; check your motherboard specifications.";
    $products = [
        ['id' => 7, 'name' => 'Atlas RAM', 'price' => '900.00', 'inventory' => ['status' => 'in_stock', 'quantity' => 5]],
        ['id' => 8, 'name' => 'Orion RAM', 'price' => '1600.00', 'inventory' => ['status' => 'in_stock', 'quantity' => 3]],
    ];

    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product, 'message' => $message, 'source' => 'gemini',
    ], ['products' => $products], 'do you have RAM?', null, $language);

    expect($presentation['intro'])->toContain('Matching RAM options:', 'Memory generations differ')
        ->not->toContain($nameLabel.':', 'View Product', '900.00', '1,600.00');
    expect(array_column($presentation['products'], 'name'))->toBe(['Atlas RAM', 'Orion RAM']);
})->with([
    ['en', 'Product name', 'Price', 'Availability'],
    ['tl', 'Pangalan ng produkto', 'Presyo', 'Katayuan ng stock'],
    ['ceb', 'Ngalan sa produkto', 'Presyo', 'Kahimtang sa stock'],
    ['hil', 'Ngalan sang produkto', 'Presyo', 'Kahimtangan sang stock'],
]);

test('plain factual model listings do not repeat cards while explanations remain', function (string $listing) {
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product, 'source' => 'gemini',
        'message' => "Yes, we have RAM in stock at Sagay:\n\n".$listing."\n\nAtlas RAM uses DDR4; check your motherboard specifications.",
    ], ['products' => [[
        'id' => 7, 'name' => 'Atlas RAM', 'price' => '900.00', 'inventory' => ['status' => 'in_stock', 'quantity' => 5],
    ]]], 'do you have RAM?', null, 'en');

    expect($presentation['intro'])->toBe("Yes, we have RAM in stock at Sagay:\n\nAtlas RAM uses DDR4; check your motherboard specifications.");
    expect($presentation['products'][0]['price'])->toBe('900.00');
})->with([
    'Atlas RAM is priced at PHP 900.00 with 5 units available.',
    'Atlas RAM — PHP 900.00. In stock at Sagay.',
    'PHP 900.00 ang presyo sang Atlas RAM.',
]);

test('router stock prose and labeled fields tolerate spacing without duplicating authoritative cards', function (string $listing) {
    config(['ai_integration.localization_enabled' => true]);
    $name = 'NETIS WF2409E 300Mbps Wireless Router 300mbps 3*5dBi ANTENNA';
    $intro = 'Ari ang mga available nga router kag ang ila stock sa Sagay:';
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => ChatbotQueryCategory::Product, 'source' => 'gemini', 'message' => $intro."\n\n".$listing,
    ], ['products' => [[
        'id' => 7, 'name' => $name, 'price' => '450.00', 'inventory' => ['status' => 'in_stock', 'quantity' => 20],
    ]]], 'router', null, 'hil');

    expect($presentation['intro'])->toBe($intro);
    expect($presentation['products'][0])->toMatchArray(['name' => $name, 'price' => '450.00', 'stock' => 'May stock sa Sagay — 20 ang available']);
})->with([
    'NETIS WF2409E  300Mbps Wireless Router 300mbps 3*5dBi ANTENNA — PHP 450.00, may yara 20 ka stock.',
    "Product name: NETIS WF2409E  300Mbps Wireless Router 300mbps 3*5dBi ANTENNA\nPrice: PHP 450.00\nAvailability: , may yara 20 ka stock\nLink: View Product",
    "- **Product name:** NETIS WF2409E  300Mbps Wireless Router 300mbps 3*5dBi ANTENNA\n- **Price:** PHP 450.00\n- **Availability:** , may yara 20 ka stock\n- **Link:** View Product",
]);

test('support and error presentation preserves approved reply text', function (ChatbotQueryCategory $category, string $message, string $kind) {
    $presentation = app(ChatbotPresentation::class)->build([
        'category' => $category, 'message' => $message,
    ], [], '', null, 'en');

    expect($presentation['intro'])->toBe($message)
        ->and($presentation['products'])->toBe([])
        ->and($presentation['facts'])->toBe([]);
    if ($kind !== '') {
        expect(array_column($presentation['actions'], 'kind'))->toBe([$kind]);
    } else {
        expect($presentation['actions'])->toBe([]);
    }
})->with([
    'faq' => [ChatbotQueryCategory::Faq, 'Pickup is available.

Follow the checkout instructions.', ''],
    'store' => [ChatbotQueryCategory::Store, 'Sagay branch.

Published hours are unavailable.', 'branches'],
    'private' => [ChatbotQueryCategory::Order, 'Please sign in to check your order.', 'login'],
    'unsupported' => [ChatbotQueryCategory::Unsupported, 'Please ask a Battlefront question.', 'catalog'],
    'timeout' => [ChatbotQueryCategory::Product, 'The chatbot took too long to respond. Please try again.', 'catalog'],
    'empty catalog' => [ChatbotQueryCategory::Product, 'No matching products were found.', 'catalog'],
]);
