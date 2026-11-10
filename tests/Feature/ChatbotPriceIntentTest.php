<?php

use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\ProductCatalogRepository;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotPriceIntent;

test('price requests preserve ranges and distinguish strict bounds', function () {
    $resolver = new ChatbotPriceIntent;
    expect($resolver->resolve('cheapest laptop below PHP 30k'))
        ->toMatchArray(['sort' => 'asc', 'maximum' => '30000.00', 'maximum_inclusive' => false]);
    expect($resolver->resolve('most expensive GPU between PHP 20,000 and PHP 30k'))
        ->toMatchArray(['sort' => 'desc', 'minimum' => '20000.00', 'maximum' => '30000.00']);
});

test('catalog price extremes use current selling prices across the matching catalog', function () {
    $category = Category::factory()->create(['name' => 'Storage']);
    Product::factory()->available()->for($category)->count(8)->create(['price' => '5000.00', 'discount_price' => null]);
    $cheap = Product::factory()->available()->for($category)->create(['name' => 'SSD Discounted', 'price' => '10000.00', 'discount_price' => '1000.00']);
    $expensive = Product::factory()->available()->for($category)->create(['name' => 'SSD Premium', 'price' => '20000.00', 'discount_price' => null]);
    $repository = app(ProductCatalogRepository::class);
    expect($repository->chatbotSearch('storage', useProvider: false, priceSort: 'asc')->first()->id)->toBe($cheap->id);
    expect($repository->chatbotSearch('storage', useProvider: false, priceSort: 'desc')->first()->id)->toBe($expensive->id);
    expect($repository->chatbotSearch('storage', useProvider: false, maximum: '1000.00', maximumInclusive: false))->toBeEmpty();
});

test('price parsing preserves regional superlatives without conflating language identities', function (string $message, string $sort) {
    expect(app(ChatbotPriceIntent::class)->resolve($message)['sort'])->toBe($sort);
})->with([
    ['Show the cheapest laptop', 'asc'],
    ['Pinakamura na laptop', 'asc'],
    ['Pinakabarato nga laptop', 'asc'],
    ['Pinakabaratu nga laptop', 'asc'],
    ['Pinakamahal nga laptop', 'desc'],
]);

test('PHP symbols shorthand and decimal amounts normalize to exact bounds', function () {
    $result = app(ChatbotPriceIntent::class)->resolve('Laptop between '."\u{20B1}".'20k and '."\u{20B1}".'30.5k');
    expect($result)->toMatchArray(['minimum' => '20000.00', 'maximum' => '30500.00', 'error' => null]);
    expect(app(ChatbotPriceIntent::class)->resolve('storage at most PHP 1,234.56'))->toMatchArray(['maximum' => '1234.56', 'maximum_inclusive' => true]);
});

test('invalid price requests ask for clarification', function (string $message) {
    expect(app(ChatbotPriceIntent::class)->resolve($message)['error'])->not->toBeNull();
})->with(['under USD 500', 'under $500', 'between PHP 30k and PHP 20k', 'above PHP 20k below PHP 20k', 'cheapest most expensive laptop', 'under PHP -1']);

test('best value does not silently request the cheapest product', function () {
    expect(app(ChatbotPriceIntent::class)->resolve('best value laptop')['sort'])->toBeNull();
});

test('price inquiries tolerate conversational whitespace without losing budget boundaries', function (string $message, array $expected) {
    expect(app(ChatbotPriceIntent::class)->resolve($message))->toMatchArray($expected);
})->with([
    ['laptop at   least PHP 20k', ['minimum' => '20000.00', 'minimum_inclusive' => true, 'query' => 'laptop']],
    ["laptop at\nmost PHP 30k", ['maximum' => '30000.00', 'maximum_inclusive' => true, 'query' => 'laptop']],
    ["lowest\tprice laptop less  than PHP 30k", ['sort' => 'asc', 'maximum' => '30000.00', 'maximum_inclusive' => false, 'query' => 'laptop']],
    ['laptop budget   of PHP 25k', ['maximum' => '25000.00', 'maximum_inclusive' => true, 'query' => 'laptop']],
]);

test('regional budget removal restores products excluded by a prior limit', function (string $message) {
    config(['ai_integration.qdrant.enabled' => false, 'scout.driver' => 'null']);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Atlas Laptop Pro', 'price' => '9000.00']);
    $intent = ['intent' => 'discovery', 'reference' => 'previous', 'category_key' => 'laptop', 'search_query' => 'laptop',
        'product_mentions' => [], 'position' => 0, 'excluded_brands' => [], 'brand' => '', 'capacity' => '', 'use_case' => ''];
    $state = ['model_constraints' => ['category_key' => 'laptop', 'search_query' => 'laptop', 'product_role' => 'main',
        'price' => ['maximum' => '5000.00', 'maximum_inclusive' => true]]];

    $result = app(ChatbotCatalogSelection::class)->select($message, $intent, $state);

    expect(array_column($result['products'], 'id'))->toContain($product->id);
    expect($result['selection_constraints']['price']['maximum'])->toBeNull();
})->with(['walay budget limit laptop', 'wala nang budget limit laptop', 'wala na sang budget limit laptop']);
