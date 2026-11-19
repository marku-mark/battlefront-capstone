<?php

use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotConversation;

beforeEach(function () {
    config(['ai_integration.enabled' => false, 'scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
});

test('computer RAM follow ups resolve to memory stock in Hiligaynon', function (bool $modelLed) {
    config(['ai_integration.enabled' => $modelLed, 'ai_integration.model_led' => $modelLed]);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();
    $ram = Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))
        ->create(['name' => 'Faspeed DDR4 RAM 8GB']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Cooling Components']))
        ->create(['name' => 'CPU Radiator Water-Cooling']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Power Accessories']))
        ->create(['name' => 'CMOS Battery for Laptop']);
    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('suggest ram', null, null, 'computer-memory');
    $availability = $chat->respond('may ara computer ram?', null, $initial['context_token'], 'computer-memory');
    $followUp = $chat->respond('ang computer ram', null, $availability['context_token'], 'computer-memory');

    foreach ([$availability, $followUp] as $reply) {
        expect($reply['language'])->toBe('hil')
            ->and($reply['presentation']['clarification'])->toBeFalse()
            ->and(array_column($reply['presentation']['products'], 'name'))->toBe([$ram->name]);
    }
})->with([false, true]);

test('broad suggestions return eligible catalog products', function (string $category, string $query, string $name) {
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => $category]))->create(['name' => $name]);
    $reply = app(ChatbotConversation::class)->respond('Suggest a '.$query, null, null, 'shopping');

    expect(array_column($reply['presentation']['products'], 'name'))->toContain($product->name);
    if ($category === 'Service') {
        expect($reply['presentation']['products'][0]['stock'])->toBe('Available at Sagay');
    }
})->with([
    ['Processor', 'cpu', 'AMD Ryzen Processor'],
    ['RAM', 'ram', 'DDR4 RAM'],
    ['Motherboard', 'motherboard', 'Biostar Motherboard'],
    ['Graphics Card', 'gpu', 'GeForce Graphics Card'],
    ['Storage', 'ssd', 'Samsung SSD'],
    ['Power Supply', 'psu', 'Corsair Power Supply'],
    ['PC Case', 'pc case', 'ATX PC Case'],
    ['Cooling Components', 'cpu cooler', 'Tower CPU Cooler'],
    ['Monitor', 'monitor', 'Acer Monitor'],
    ['Peripherals', 'keyboard', 'Logitech Keyboard'],
    ['Networking', 'router', 'TP-Link Router'],
    ['Audio Equipment', 'headset', 'JBL Headset'],
    ['Cables & Adapters', 'cables', 'HDMI Cable'],
    ['Printer', 'printer', 'PRINTER EPSON ECOTANK L121'],
    ['Laptops & Desktops', 'laptop', 'ASUS Laptop'],
    ['Bundles & Packages', 'computer package', 'Office Computer Package'],
    ['CCTV & Security', 'security camera', 'CCTV Security Camera'],
    ['Cleaning & Maintenance Supplies', 'blower', 'Electric Blower'],
    ['Consumer Electronics', 'tv stick', 'Android TV Stick'],
    ['DIY Electronics & Microcontrollers', 'microcontroller', 'NodeMCU Microcontroller'],
    ['Furniture & Hardware Fixtures', 'chair', 'Office Chair'],
    ['Laptop Bags & Stands', 'laptop stand', 'Adjustable Laptop Stand'],
    ['Office & Packaging Supplies', 'copy paper', 'A4 Copy Paper'],
    ['Power Accessories', 'ups', 'APC UPS'],
    ['Solar & Lighting Products', 'solar light', 'Outdoor Solar Light'],
    ['Vending & Coin-Op Machine Parts', 'coin slot', 'Coinslot Controller'],
    ['Service', 'checkup', 'Computer Checkup'],
]);

test('alternatives search past the displayed products and retain the budget', function (bool $modelLed) {
    config(['ai_integration.enabled' => $modelLed, 'ai_integration.model_led' => $modelLed]);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();
    $category = Category::factory()->create(['name' => 'Printer']);
    Product::factory()->available()->for($category)->count(21)->sequence(fn ($sequence) => [
        'name' => 'Brother Ink '.$sequence->index, 'brand' => 'Brother', 'price' => 320, 'discount_price' => null,
    ])->create();
    $alternative = Product::factory()->available()->for($category)->create([
        'name' => 'Epson Ink 003', 'brand' => 'Epson', 'price' => 350, 'discount_price' => null,
    ]);
    Product::factory()->available()->for($category)->create([
        'name' => 'Canon Ink', 'brand' => 'Canon', 'price' => 500, 'discount_price' => null,
    ]);
    Product::factory()->available()->for($category)->create([
        'name' => 'Unknown Ink', 'brand' => null, 'price' => 300, 'discount_price' => null,
    ]);

    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('Do you have printer ink under PHP 400?', null, null, 'shopping');
    $reply = $chat->respond('Do you only have Brother ink?', null, $initial['context_token'], 'shopping');

    expect($initial['presentation']['products'])->toHaveCount(6)
        ->and($reply['message'])->toContain('other brands in stock')
        ->and(array_column($reply['presentation']['products'], 'name'))->toBe([$alternative->name]);

    $alternative->inventory()->update(['quantity' => 0]);
    $reply = $chat->respond('Do you only have Brother ink?', null, $initial['context_token'], 'shopping');

    expect($reply['message'])->toContain('no other identifiable brands')->and($reply['presentation']['products'])->toBe([]);
})->with([false, true]);

test('shopping refinements preserve the family and clarify a comparison anchor', function () {
    $category = Category::factory()->create(['name' => 'RAM']);
    $first = Product::factory()->available()->for($category)->create([
        'name' => 'DDR4 RAM Alpha', 'price' => 1900, 'discount_price' => null, 'brand' => 'Alpha',
    ]);
    Product::factory()->available()->for($category)->create([
        'name' => 'DDR4 RAM Beta', 'price' => 2000, 'discount_price' => null, 'brand' => 'Beta',
    ]);
    Branch::factory()->create(['city' => 'Bacolod City', 'contact_number' => '09611764608']);

    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('may ara kamo ram subong?', null, null, 'shopping');
    $clarified = $chat->respond('Something cheaper?', null, $initial['context_token'], 'shopping');
    $compared = $chat->respond('Cheaper than the second one?', null, $clarified['context_token'], 'shopping');
    $budget = $chat->respond('Under PHP 2000', null, $clarified['context_token'], 'shopping');
    $remote = $chat->respond('Do you have it in Bacolod?', null, $budget['context_token'], 'shopping');

    expect($initial['language'])->toBe('hil')
        ->and($initial['presentation']['products'])->toHaveCount(2)
        ->and($clarified['language'])->toBe('en')
        ->and($clarified['presentation']['clarification'])->toBeTrue()
        ->and($clarified['message'])->toContain('Which product')
        ->and(array_column($compared['presentation']['products'], 'name'))->toBe([$first->name])
        ->and(array_column($budget['presentation']['products'], 'name'))->toBe([$first->name])
        ->and($remote['message'])->toContain('Bacolod')->toContain('09611764608')
        ->and($remote['presentation']['products'])->toBe([]);
});

test('product discovery keeps localized suggestions and asks what an adapter must connect', function (string $question, string $language) {
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Peripherals']))
        ->create(['name' => 'Logitech Keyboard']);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond($question, null, null, 'shopping');
    $adapter = $chat->respond('I need an adapter', null, $reply['context_token'], 'shopping');

    expect($reply['language'])->toBe($language)
        ->and(array_column($reply['presentation']['products'], 'name'))->toBe([$product->name])
        ->and($adapter['presentation']['clarification'])->toBeTrue()
        ->and($adapter['message'])->toContain('trying to connect');
})->with([
    ['Irekomenda keyboard po', 'tl'],
    ['Rekomendahi keyboard palihog', 'ceb'],
]);

test('another brand in a multipart conversation asks for the section first', function () {
    $board = Category::factory()->create(['name' => 'Motherboard']);
    Product::factory()->available()->for($board)->create(['name' => 'Biostar Motherboard', 'brand' => 'Biostar']);
    Product::factory()->available()->for($board)->create(['name' => 'Gigabyte Motherboard', 'brand' => 'Gigabyte']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))
        ->create(['name' => 'Kingston RAM', 'brand' => 'Kingston']);
    $chat = app(ChatbotConversation::class);
    $initial = $chat->respond('Biostar motherboard and RAM', null, null, 'shopping');
    $question = $chat->respond('Another brand?', null, $initial['context_token'], 'shopping');
    $reply = $chat->respond('Motherboard', null, $question['context_token'], 'shopping');

    expect($question['presentation']['clarification'])->toBeTrue()
        ->and($reply['message'])->toContain('other brands in stock')
        ->and($reply['presentation']['sections'][0]['products'][0]['name'])->toBe('Gigabyte Motherboard');
});
