<?php

use App\Models\Category;
use App\Models\Product;

test('hiligaynon compound query maintains language consistency', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);
    $monitorCategory = Category::factory()->create(['name' => 'Monitor']);

    Product::factory()->available()->for($ramCategory)->create(['name' => 'Samsung RAM 8GB', 'price' => '900.00']);
    Product::factory()->available()->for($monitorCategory)->create(['name' => 'Lenovo Monitor 22"', 'price' => '2000.00']);

    $response = $this->postJson('/chatbot', ['message' => 'may ara kamo ram kag monitor?'])
        ->assertOk()
        ->assertJsonPath('language', 'hil');

    expect($response->json('message'))
        ->toContain('RAM')
        ->toContain('Monitor');
});

test('cebuano query with namo mo pattern detects cebuano', function () {
    $keyboardCategory = Category::factory()->create(['name' => 'Peripherals']);

    Product::factory()->available()->for($keyboardCategory)->create(['name' => 'AOC Gaming Keyboard', 'price' => '1500.00']);

    $response = $this->postJson('/chatbot', ['message' => 'namo mo keyboard?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    expect($response->json('message'))->toContain('Keyboard');
});

test('cebuano compound query with naa mo maintains language', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);
    $monitorCategory = Category::factory()->create(['name' => 'Monitor']);

    Product::factory()->available()->for($ramCategory)->create(['name' => 'Faspeed RAM DDR4 8GB', 'price' => '900.00']);
    Product::factory()->available()->for($monitorCategory)->create(['name' => 'AOC Monitor 22"', 'price' => '2000.00']);

    $response = $this->postJson('/chatbot', ['message' => 'naa mo ram ug monitor?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    expect($response->json('message'))
        ->toContain('RAM')
        ->toContain('Monitor');
});

test('ram search works in cebuano', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);

    $ram1 = Product::factory()->available()->for($ramCategory)->create(['name' => 'Samsung RAM DDR4 8GB', 'price' => '900.00']);
    $ram2 = Product::factory()->available()->for($ramCategory)->create(['name' => 'Faspeed RAM DDR3 8GB', 'price' => '750.00']);

    $response = $this->postJson('/chatbot', ['message' => 'naa mo ram?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    $message = $response->json('message');
    expect($message)->toContain('RAM');
    $hasProduct = str_contains($message, 'Samsung') || str_contains($message, 'Faspeed');
    expect($hasProduct)->toBeTrue();
});

test('mixed language query with may naa resolves to cebuano', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);

    Product::factory()->available()->for($ramCategory)->create(['name' => 'Adata RAM DDR5 16GB', 'price' => '2600.00']);

    $response = $this->postJson('/chatbot', ['message' => 'may naa mo ram?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    expect($response->json('message'))->toContain('RAM');
});

test('language persists across conversation turns', function () {
    $this->withCredentials()->withSession(['chat_test' => true]);
    $ramCategory = Category::factory()->create(['name' => 'RAM']);

    $product = Product::factory()->available()->for($ramCategory)->create(['name' => 'Samsung RAM DDR4 8GB', 'price' => '900.00']);

    // First message in Cebuano
    $firstResponse = $this->postJson('/chatbot', ['message' => 'naa mo ram?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    $token = $firstResponse->json('context_token');
    expect($token)->not->toBeNull();

    // Follow-up question - simple word that shouldn't override language
    // The previous language (ceb) should be maintained from context
    $secondResponse = $this->withCookie(config('session.cookie'), session()->getId())->postJson('/chatbot', [
        'message' => 'available',
        'context_token' => $token,
    ])
        ->assertOk();

    // Check that language is preserved from previous turn
    $responseLanguage = $secondResponse->json('language');
    expect($responseLanguage)->toBe('ceb', "Expected language to persist as 'ceb' but got '$responseLanguage'");
});

test('hiligaynon query with may ara kamo detects hiligaynon', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);

    Product::factory()->available()->for($ramCategory)->create(['name' => 'Samsung RAM DDR4 8GB', 'price' => '900.00']);

    $response = $this->postJson('/chatbot', ['message' => 'may ara kamo ram?'])
        ->assertOk()
        ->assertJsonPath('language', 'hil');

    expect($response->json('message'))->toContain('RAM');
});

test('compound query splits correctly and finds products for both categories', function () {
    $ramCategory = Category::factory()->create(['name' => 'RAM']);
    $keyboardCategory = Category::factory()->create(['name' => 'Peripherals']);

    Product::factory()->available()->for($ramCategory)->create(['name' => 'Samsung RAM DDR4 8GB', 'price' => '900.00']);
    Product::factory()->available()->for($keyboardCategory)->create(['name' => 'Bosston Keyboard K820', 'price' => '650.00']);

    $response = $this->postJson('/chatbot', ['message' => 'naa mo ram ug keyboard?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    $message = $response->json('message');
    expect($message)->toContain('RAM');
    expect($message)->toContain('Keyboard');
});

test('monitor search works in cebuano', function () {
    $monitorCategory = Category::factory()->create(['name' => 'Monitor']);

    Product::factory()->available()->for($monitorCategory)->create(['name' => 'AOC Monitor 22"', 'price' => '2000.00']);
    Product::factory()->available()->for($monitorCategory)->create(['name' => 'Lenovo Monitor 22"', 'price' => '2000.00']);

    $response = $this->postJson('/chatbot', ['message' => 'naa mo monitor?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    $message = $response->json('message');
    expect($message)->toContain('Monitor');
});

test('namo pattern with available triggers cebuano detection', function () {
    $keyboardCategory = Category::factory()->create(['name' => 'Peripherals']);

    Product::factory()->available()->for($keyboardCategory)->create(['name' => 'Gaming Keyboard', 'price' => '1200.00']);

    $response = $this->postJson('/chatbot', ['message' => 'naa mo keyboard?'])
        ->assertOk()
        ->assertJsonPath('language', 'ceb');

    expect($response->json('message'))->toContain('Keyboard');
});
