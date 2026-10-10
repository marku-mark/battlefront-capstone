<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use App\Repositories\Catalog\ProductCatalogRepository;

test('finds arbitrary catalog products through supported attributes', function (string $message) {
    $category = Category::factory()->create(['name' => 'Field Networking']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Aurelius Link Station',
        'description' => 'Outdoor connectivity unit',
        'brand' => 'Helios Labs',
        'price' => '12999.00',
        'discount_price' => '11999.00',
    ]);
    $tags = Tag::factory()->count(2)->sequence(
        ['name' => 'Solar Ready'],
        ['name' => 'Enterprise'],
    )->create();
    $product->tags()->attach($tags);
    Inventory::factory()->for($product)->create(['quantity' => 7, 'reorder_level' => 2]);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context)->toBe([
        'products' => [[
            'name' => 'Aurelius Link Station',
            'description' => 'Outdoor connectivity unit',
            'brand' => 'Helios Labs',
            'category' => 'Field Networking',
            'tags' => ['Enterprise', 'Solar Ready'],
            'price' => '12999.00',
            'discount_price' => '11999.00',
            'is_demo' => false,
            'inventory' => [
                'quantity' => 7,
                'status' => 'in_stock',
            ],
        ]],
    ]);
})->with([
    'name' => ['Do you have the Aurelius Link Station?'],
    'brand' => ['Are Helios Labs products available?'],
    'category' => ['Show Field Networking stock.'],
    'tag' => ['Do you have anything Solar Ready?'],
    'description' => ['I need an outdoor connectivity unit.'],
    'partial name' => ['Looking for Link Station.'],
    'multiple attributes' => ['Do you have Helios Field Networking products?'],
    'case and punctuation' => ['  ARE HELIOS-LABS products available?!  '],
]);

test('preserves demo disclaimers for explicit named product questions', function (string $message) {
    $category = Category::factory()->create(['name' => 'Storage']);
    Product::factory()->for($category)->create([
        'name' => '[DEMO] Samsung Sprint NVMe SSD',
        'brand' => 'Samsung',
        'price' => '4599.00',
        'description' => null,
    ]);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context['products'])->toHaveCount(1)
        ->and($context['products'][0]['name'])->toBe('[DEMO] Samsung Sprint NVMe SSD')
        ->and($context['products'][0]['price'])->toBe('4599.00')
        ->and($context['products'][0]['is_demo'])->toBeTrue()
        ->and($context['products'][0]['demo_notice'])->toBe('Demo item only; listed prices are samples and Battlefront stock is unconfirmed.')
        ->and($context['products'][0]['inventory'])->toBe([
            'quantity' => null,
            'status' => 'unavailable',
        ]);
})->with([
    'name' => ['Do you have Samsung Sprint NVMe SSD?'],
    'demo name' => ['What is the price of [DEMO] Samsung Sprint NVMe SSD?'],
]);

test('prioritizes exact and field phrase matches before weaker description matches', function () {
    $category = Category::factory()->create(['name' => 'Components']);
    Product::factory()->for($category)->create([
        'name' => 'Alpha Device',
        'description' => 'Nova Station accessory',
        'brand' => 'Acme',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Aardvark Nova Station Cable',
        'description' => null,
        'brand' => 'Acme',
    ]);
    Product::factory()->for($category)->create([
        'name' => 'Nova Station',
        'description' => null,
        'brand' => 'Acme',
    ]);

    $products = app(ProductCatalogRepository::class)->contextMatches(['Nova', 'Station']);

    expect($products->pluck('name')->all())->toBe([
        'Nova Station',
        'Aardvark Nova Station Cable',
        'Alpha Device',
    ]);
});

test('matches every meaningful term across fields without returning partial distractors', function () {
    $networking = Category::factory()->create(['name' => 'Field Networking']);
    $peripherals = Category::factory()->create(['name' => 'Peripherals']);
    Product::factory()->available()->for($peripherals)->create([
        'name' => 'Helios Mouse',
        'description' => null,
        'brand' => 'Helios Labs',
    ]);
    Product::factory()->available()->for($networking)->create([
        'name' => 'Aurelius Link Station',
        'description' => null,
        'brand' => 'Helios Labs',
    ]);
    Product::factory()->available()->for($networking)->create([
        'name' => 'Aurelius Router',
        'description' => null,
        'brand' => 'Other Brand',
    ]);

    $context = app(ResolveProductContext::class)->execute('Do you have a Helios Link Station?');

    expect(array_column($context['products'], 'name'))->toBe(['Aurelius Link Station']);
});

test('named product inquiries retain zero and missing stock while discovery hides them', function () {
    $tag = Tag::factory()->create(['name' => 'Remote Kit']);
    $missingInventory = Product::factory()->create(['name' => 'Alpine Remote Kit']);
    $outOfStock = Product::factory()->create(['name' => 'Zephyr Remote Kit']);
    $missingInventory->tags()->attach($tag);
    $outOfStock->tags()->attach($tag);
    Inventory::factory()->for($outOfStock)->create(['quantity' => 0]);

    $context = app(ResolveProductContext::class)->execute('Is a Remote Kit available?');

    expect($context)->toBe(['products' => []]);
    $missing = app(ResolveProductContext::class)->execute('Is the Alpine Remote Kit available?');
    $soldOut = app(ResolveProductContext::class)->execute('Is the ZEPHYR-REMOTE KIT available?');
    expect($missing['products'])->toHaveCount(1);
    expect($missing['products'][0]['inventory'])->toBe([
        'quantity' => null,
        'status' => 'unavailable',
    ]);
    expect($soldOut['products'])->toHaveCount(1);
    expect($soldOut['products'][0]['inventory'])->toBe([
        'quantity' => 0,
        'status' => 'out_of_stock',
    ]);
});

test('reports positive inventory at or below the reorder level as low stock', function (int $quantity) {
    $product = Product::factory()->create(['name' => 'Aurelius Low Stock Router']);
    Inventory::factory()->for($product)->create(['quantity' => $quantity, 'reorder_level' => 2]);

    $context = app(ResolveProductContext::class)->execute('Aurelius Low Stock Router');

    expect($context['products'][0]['inventory'])->toBe([
        'quantity' => $quantity,
        'status' => 'low_stock',
    ]);
})->with(['below' => 1, 'equal' => 2]);

test('chatbot discovery returns available matches across all catalog attributes', function (string $message) {
    $category = Category::factory()->create(['name' => 'Networking']);
    $tag = Tag::factory()->create(['name' => 'Remote Kit']);
    $available = Product::factory()->available()->for($category)->create([
        'name' => 'Available Remote Kit', 'brand' => 'Atlas', 'description' => 'Outdoor connectivity',
    ]);
    $soldOut = Product::factory()->for($category)->create([
        'name' => 'Sold Out Remote Kit', 'brand' => 'Atlas', 'description' => 'Outdoor connectivity',
    ]);
    $missing = Product::factory()->for($category)->create([
        'name' => 'Missing Remote Kit', 'brand' => 'Atlas', 'description' => 'Outdoor connectivity',
    ]);
    Inventory::factory()->for($soldOut)->create(['quantity' => 0]);
    foreach ([$available, $soldOut, $missing] as $product) {
        $product->tags()->attach($tag);
    }

    $context = app(ResolveProductContext::class)->execute($message);

    expect(array_column($context['products'], 'name'))->toBe(['Available Remote Kit']);
})->with([
    'category' => 'Show Networking products.',
    'brand' => 'Do you have Atlas products?',
    'tag and partial name' => 'Show Remote Kit stock.',
    'description' => 'I need outdoor connectivity.',
]);

test('excludes inactive products and products in inactive categories', function () {
    $activeCategory = Category::factory()->create();
    $inactiveCategory = Category::factory()->inactive()->create();
    Product::factory()->inactive()->for($activeCategory)->create(['name' => 'Orchid Router']);
    Product::factory()->for($inactiveCategory)->create(['name' => 'Orchid Switch']);

    $context = app(ResolveProductContext::class)->execute('Is an Orchid product available?');

    expect($context)->toBe(['products' => []]);
});

test('returns explicit empty product context when no catalog term can be resolved', function (string $message) {
    Product::factory()->create(['name' => 'Known Catalog Item']);

    $context = app(ResolveProductContext::class)->execute($message);

    expect($context)->toBe(['products' => []]);
})->with([
    'empty input' => ['   ...   '],
    'only generic query words' => ['What products are available?'],
    'natural generic query words' => ['Do you have products available currently in stock?'],
    'generic named inquiry' => ['Are you selling a product named?'],
    'no catalog match' => ['Do you have Nebula Quantum Parts?'],
]);
