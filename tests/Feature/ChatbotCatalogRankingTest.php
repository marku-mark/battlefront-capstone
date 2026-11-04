<?php

use App\Actions\Chatbot\RenderChatbotFallback;
use App\Enums\ChatbotQueryCategory;
use App\Models\CatalogProjection;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotGroundedResponse;
use App\Services\Chatbot\ChatbotProductRanking;
use App\Services\Chatbot\GraphCatalogCandidates;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use Illuminate\Support\Facades\Http;

function rankingIntent(array $changes = []): array
{
    return array_replace(['intent' => 'discovery', 'category_key' => 'storage', 'search_query' => 'Storage',
        'product_mentions' => [], 'reference' => 'new', 'position' => 0, 'maximum_php' => null,
        'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => ''], $changes);
}

test('main catalog products lead their accessories and explicit accessory requests remain supported', function (string $category, string $main, string $accessory, string $request) {
    $group = Category::factory()->create(['name' => $category]);
    $device = Product::factory()->available()->for($group)->create(['name' => $main, 'price' => '9000.00', 'discount_price' => null]);
    $extra = Product::factory()->available()->for($group)->create(['name' => $accessory, 'price' => '100.00', 'discount_price' => null]);
    $selector = app(ChatbotCatalogSelection::class);
    $family = ChatbotProductRanking::FAMILIES[$category];
    $result = $selector->select('cheapest '.$category, rankingIntent(['category_key' => $family, 'search_query' => $category]));
    expect(array_column($result['products'], 'id'))->toBe([$device->id]);
    $result = $selector->select('cheapest '.$request, rankingIntent(['category_key' => 'other', 'search_query' => $request]));
    expect($result['products'][0]['id'])->toBe($extra->id);
})->with([
    ['Printer', 'PRINTER Epson Ink Tank', 'Printer Cable USB', 'printer cable'],
    ['Storage', 'Atlas SSD', 'SSD Enclosure', 'ssd enclosure'],
    ['Graphics Card', 'Atlas GPU', 'GPU Support Holder', 'gpu support holder'],
    ['Monitor', 'Atlas Monitor', 'Monitor Wall Mount', 'monitor wall mount'],
    ['Cooling Components', 'Atlas CPU Cooler', 'Thermal Paste', 'thermal paste'],
]);

test('price constraints and exclusions are applied before candidate limits', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    Product::factory()->available()->for($category)->count(8)->sequence(fn ($sequence) => ['name' => 'SSD Excluded '.$sequence->index])->create(['brand' => 'Excluded', 'price' => '10.00', 'discount_price' => null]);
    $expected = Product::factory()->available()->for($category)->create(['name' => 'SSD Allowed', 'brand' => 'Allowed', 'price' => '9000.00', 'discount_price' => '3000.00']);
    $result = app(ChatbotCatalogSelection::class)->select('cheapest storage under PHP 4k', rankingIntent(['excluded_brands' => ['Excluded']]));
    expect(array_column($result['products'], 'id'))->toBe([$expected->id]);
    expect($result['price_selection']['prices'][$expected->id])->toBe('3000.00');
});

test('follow ups preserve price constraints and positional references while new topics clear them', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    $first = Product::factory()->available()->for($category)->create(['name' => 'Atlas SSD', 'price' => '2000.00', 'discount_price' => null]);
    $second = Product::factory()->available()->for($category)->create(['name' => 'Helios SSD', 'price' => '3000.00', 'discount_price' => null]);
    $selector = app(ChatbotCatalogSelection::class);
    $result = $selector->select('cheapest storage up to PHP 3k', rankingIntent());
    $state = ['references' => ['product' => array_column($result['products'], 'id')], 'model_constraints' => $result['selection_constraints']];
    $follow = $selector->select('the second one', rankingIntent(['reference' => 'previous', 'intent' => 'facts', 'position' => 2, 'category_key' => 'other']), $state);
    expect(array_column($follow['products'], 'id'))->toBe([$second->id]);
    $first->update(['price' => '4000.00']);
    $follow = $selector->select('show those again', rankingIntent(['reference' => 'previous']), $state);
    expect(array_column($follow['products'], 'id'))->toBe([$second->id]);
    $fresh = $selector->select('most expensive storage', rankingIntent(), $state);
    expect($fresh['products'][0]['id'])->toBe($first->id);
});

test('selected product price comparisons do not search the rest of the catalog', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    $outside = Product::factory()->available()->for($category)->create(['price' => '1.00', 'discount_price' => null]);
    $selected = Product::factory()->available()->for($category)->create(['price' => '500.00', 'discount_price' => null]);
    $result = app(ChatbotCatalogSelection::class)->select('Which of these is cheapest?', rankingIntent(['reference' => 'previous', 'price_scope' => 'previous']), ['references' => ['product' => [$selected->id]]]);
    expect(array_column($result['products'], 'id'))->toBe([$selected->id])->not->toContain($outside->id);
    expect($result['price_selection']['scope'])->toBe('selected_products');
});

test('cheaper alternatives retain the anchor family and use its fresh selling price', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    $anchor = Product::factory()->available()->for($category)->create(['name' => 'Atlas SSD', 'price' => '5000.00', 'discount_price' => '2500.00']);
    $cheap = Product::factory()->available()->for($category)->create(['name' => 'Helios SSD', 'price' => '2000.00', 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Atlas SSD Enclosure', 'price' => '100.00']);
    $result = app(ChatbotCatalogSelection::class)->select('cheaper alternatives', rankingIntent(['reference' => 'previous', 'intent' => 'recommendation']), ['references' => ['product' => [$anchor->id]]]);
    expect(array_column($result['products'], 'id'))->toBe([$cheap->id]);
});

test('unreviewed catalog devices can have current graph alternatives without compatibility claims', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.neo4j.enabled' => true, 'ai_integration.neo4j.url' => 'https://graph.example.test', 'ai_integration.neo4j.username' => 'test', 'ai_integration.neo4j.password' => 'test']);
    $category = Category::factory()->create(['name' => 'Printer']);
    $seed = Product::factory()->available()->for($category)->create(['name' => 'PRINTER Atlas']);
    $other = Product::factory()->available()->for($category)->create(['name' => 'PRINTER Helios']);
    foreach ([$seed, $other] as $product) {
        CatalogProjection::query()->create(['product_id' => $product->id, 'revision' => 1, 'neo4j_revision' => 1, 'content_hash' => app(CatalogDocumentBuilder::class)->build($product)['hash']]);
    }
    Http::fake(['https://graph.example.test/*' => Http::response(['data' => ['values' => [[$other->id, 1]]]])]);
    expect(app(GraphCatalogCandidates::class)->ids($seed->id))->toBe([$other->id]);
    Http::assertSentCount(1);
});

test('grounded drafts reject inverted product ordering and false price extrema', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    $cheap = Product::factory()->available()->for($category)->create(['name' => 'Atlas SSD', 'price' => '1000.00', 'discount_price' => null]);
    $costly = Product::factory()->available()->for($category)->create(['name' => 'Helios SSD', 'price' => '2000.00', 'discount_price' => null]);
    $context = app(ChatbotCatalogSelection::class)->select('cheapest storage', rankingIntent());
    $renderer = app(ChatbotGroundedResponse::class);
    $evidence = $renderer->conversationalEvidence($context);
    $intent = ['price_selection' => $context['price_selection']];
    $ids = ['product:'.$cheap->id, 'product:'.$costly->id];
    expect($renderer->draft($evidence, ['answer' => 'Helios SSD is the cheapest.', 'evidence_ids' => $ids], $intent))->toBeNull();
    expect($renderer->draft($evidence, ['answer' => 'Helios SSD then Atlas SSD.', 'evidence_ids' => $ids], $intent))->toBeNull();
    expect($renderer->draft($evidence, ['answer' => 'Atlas SSD is the cheapest.', 'evidence_ids' => ['product:'.$cheap->id]], $intent))->toBe('Atlas SSD is the cheapest.');
});

test('broad discovery diversifies main product families before cheap accessories', function () {
    $storage = Category::factory()->create(['name' => 'Storage']);
    Product::factory()->available()->for($storage)->count(22)->sequence(fn ($sequence) => ['name' => 'Atlas SSD '.$sequence->index])->create(['price' => '2000.00', 'discount_price' => null]);
    $printer = Product::factory()->available()->for(Category::factory()->state(['name' => 'Printer']))->create(['name' => 'PRINTER Atlas', 'price' => '9000.00', 'discount_price' => null]);
    $accessory = Product::factory()->available()->for($storage)->create(['name' => 'SSD Enclosure', 'price' => '10.00', 'discount_price' => null]);
    $selector = app(ChatbotCatalogSelection::class);
    $result = $selector->select('What products do you have?', rankingIntent(['category_key' => 'other', 'search_query' => '']));
    expect(array_column($result['products'], 'id'))->toContain($printer->id)->not->toContain($accessory->id);
    $mixed = $selector->select('cheapest items including accessories', rankingIntent(['category_key' => 'other', 'search_query' => '']));
    expect($mixed['products'][0]['id'])->toBe($accessory->id);
});

test('typed main searches never substitute an accessory when no main product exists', function () {
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Printer']))->create(['name' => 'Printer Cable']);
    $result = app(ChatbotCatalogSelection::class)->select('cheapest printer', rankingIntent(['category_key' => 'printer', 'search_query' => 'Printer']));
    expect($result['products'])->toBeEmpty();
});

test('derived documents carry public family and role metadata without live prices', function () {
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Atlas Laptop']);
    $document = app(CatalogDocumentBuilder::class)->build($product);
    expect($document)->toMatchArray(['schema_version' => 2, 'product_family' => 'laptop', 'product_role' => 'main']);
    expect($document)->not->toHaveKeys(['price', 'discount_price', 'quantity']);
});

test('current catalog ink titles and computer names receive the correct role and family', function () {
    $ranking = app(ChatbotProductRanking::class);
    expect($ranking->classify('Printer', 'Brother Ink BT5000C Cyan'))->toMatchArray(['product_family' => 'printer', 'product_role' => 'accessory']);
    expect($ranking->classify('Printer', 'PRINTER EPSON ECOTANK A4 INK TANK')['product_role'])->toBe('main');
    expect($ranking->classify('Laptops & Desktops', 'PRE-OWNED MACBOOK AIR')['product_family'])->toBe('laptop');
    expect($ranking->classify('Laptops & Desktops', 'CPU SURPLUS HP ELITEDESK')['product_family'])->toBe('desktop');
    expect($ranking->classify('Laptops & Desktops', 'CPU SURPLUS LENOVO NO RAM NO HDD')['product_role'])->toBe('unknown');
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $laptop = Product::factory()->available()->for($category)->create(['name' => 'PRE-OWNED MACBOOK AIR']);
    $desktop = Product::factory()->available()->for($category)->create(['name' => 'CPU SURPLUS HP ELITEDESK']);
    $selector = app(ChatbotCatalogSelection::class);
    expect(array_column($selector->select('cheapest laptop', rankingIntent(['category_key' => 'laptop', 'search_query' => 'laptop']))['products'], 'id'))->toBe([$laptop->id]);
    expect(array_column($selector->select('cheapest desktop', rankingIntent(['category_key' => 'desktop', 'search_query' => 'desktop']))['products'], 'id'))->toBe([$desktop->id]);
});

test('fallback and generated replies leave a blank line between products', function (string $wording, string $separator) {
    $category = Category::factory()->create(['name' => 'Storage']);
    $first = Product::factory()->available()->for($category)->create(['name' => 'Atlas SSD', 'price' => '1000.00', 'discount_price' => null]);
    $second = Product::factory()->available()->for($category)->create(['name' => 'Helios SSD', 'price' => '2000.00', 'discount_price' => null]);
    $context = app(ChatbotCatalogSelection::class)->select('cheapest storage', rankingIntent());
    $fallback = app(RenderChatbotFallback::class)->execute(ChatbotQueryCategory::Product, 'cheapest storage', $context);
    expect($fallback)->toContain("\n\nHelios SSD");
    $renderer = app(ChatbotGroundedResponse::class);
    $draft = $renderer->draft($renderer->conversationalEvidence($context), ['answer' => "- Atlas SSD {$wording} PHP 1,000.00.{$separator}- Helios SSD {$wording} PHP 2,000.00.", 'evidence_ids' => ['product:'.$first->id, 'product:'.$second->id]], ['price_selection' => $context['price_selection']]);
    expect($draft)->toBe("- Atlas SSD {$wording} PHP 1,000.00.\n\n- Helios SSD {$wording} PHP 2,000.00.");
})->with([
    'English lines' => ['costs', "\n"],
    'Tagalog lines' => ['ay', "\n"],
    'Cebuano lines' => ['kay', "\n"],
    'Hiligaynon lines' => ['amo', "\n"],
    'English inline' => ['costs', ' '],
    'Tagalog inline' => ['ay', ' '],
    'Cebuano inline' => ['kay', ' '],
    'Hiligaynon inline' => ['amo', ' '],
    'existing spacing' => ['costs', "\n\n"],
]);
