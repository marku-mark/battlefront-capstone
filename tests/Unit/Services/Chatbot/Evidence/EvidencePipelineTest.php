<?php

use App\Services\Chatbot\ChatbotGroundedResponse;

test('grounding exposes current discounted product facts and approved specifications', function () {
    $product = [
        'id' => 1,
        'name' => 'Test Product',
        'price' => '1000.00',
        'discount_price' => '900.00',
        'brand' => 'Test Brand',
        'category' => 'Test Category',
        'description' => '<b>Test description</b>',
        'inventory' => ['quantity' => 10, 'status' => 'in_stock'],
        'specification_review_status' => 'approved',
        'approved_specifications' => ['interface' => 'USB'],
    ];
    $evidence = (new ChatbotGroundedResponse)->conversationalEvidence(['products' => [$product]]);

    expect($evidence)->toHaveKeys(['product:1'])
        ->and($evidence['product:1'])->toContain('Test Product', 'PHP 900.00', 'Available quantity: 10',
            'Test Brand', 'Test Category', 'Test description', 'Approved current specifications: {"interface":"USB"}')
        ->not->toContain('<b>', 'PHP 1,000.00');
});

test('grounding exposes branch, compatibility, FAQ, policy, and selection evidence', function () {
    $evidence = (new ChatbotGroundedResponse)->conversationalEvidence([
        'branches' => [['city' => 'Sagay City']],
        'compatibility' => ['status' => 'compatible'],
        'knowledge' => [['response_template' => 'Approved FAQ wording']],
        'guidance' => 'Approved policy wording',
        'no_match_message' => 'No products found',
        'price_selection' => ['sort' => 'asc'],
    ]);

    expect($evidence['branch:0'])->toContain('Sagay City')
        ->and($evidence['compatibility:summary'])->toContain('PHP CompatibilityEngine', '"status":"compatible"')
        ->and($evidence['faq:0'])->toBe('Approved answer: Approved FAQ wording')
        ->and($evidence['policy:guidance'])->toContain('Approved policy wording')
        ->and($evidence['selection:no_match_message'])->toContain('No products found')
        ->and($evidence['selection:ranking'])->toContain('"sort":"asc"');
});

test('grounding skips malformed products, caps evidence, and supports empty context', function () {
    $grounding = new ChatbotGroundedResponse;
    $products = array_map(fn (int $id): array => [
        'id' => $id, 'name' => "Product {$id}", 'price' => '100.00',
    ], range(1, 9));

    expect($grounding->conversationalEvidence([]))->toBe([])
        ->and(array_keys($grounding->conversationalEvidence(['products' => [['id' => 100], ...$products]])))
        ->toBe(['product:1', 'product:2', 'product:3', 'product:4', 'product:5']);
});
