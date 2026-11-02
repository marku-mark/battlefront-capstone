<?php

use App\Models\ComponentSpecificationRevision;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\Chatbot\ChatbotCatalogAdvice;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotConversationToken;

beforeEach(function () {
    config()->set('ai_integration.advice_enabled', true);
});

test('recommendations preserve model numbers while applying explicit price limits', function () {
    Product::factory()->available()->create(['name' => 'Atlas RTX 4060', 'price' => 15000, 'discount_price' => null]);
    Product::factory()->available()->create(['name' => 'Atlas RTX 4090', 'price' => 10000, 'discount_price' => null]);

    $response = app(ChatbotCatalogAdvice::class)->answer('Recommend RTX 4060 under PHP 16000');

    expect($response['message'])->toContain('Atlas RTX 4060')->not->toContain('Atlas RTX 4090');
    expect($response['context']['products'])->toHaveCount(1);
    expect(app(ChatbotCatalogAdvice::class)->answer('Recommend RTX 4060 under PHP 14000')['context']['products'])->toBe([]);
});

test('compatibility clarifies multiple remembered models of the same component type', function () {
    $first = Product::factory()->available()->create(['name' => 'Atlas Board A']);
    $second = Product::factory()->available()->create(['name' => 'Atlas Board B']);
    $cpu = Product::factory()->available()->create(['name' => 'Helios CPU Z']);
    foreach ([$first, $second] as $board) {
        ProductComponentProfile::factory()->create(['product_id' => $board->id, 'component_type' => 'motherboard']);
    }
    ProductComponentProfile::factory()->create(['product_id' => $cpu->id, 'component_type' => 'processor']);

    $response = app(ChatbotCatalogAdvice::class)->answer('Will Helios CPU Z work with the motherboard mentioned earlier?', ['motherboard' => [$first->id, $second->id]]);

    expect($response['message'])->toContain('More than one part matches');
    expect($response['context'])->toBe([]);
});

test('compatibility uses the current approved revision and preserves missing evidence', function () {
    $cpu = Product::factory()->available()->create(['name' => 'Helios CPU Z']);
    $board = Product::factory()->available()->create(['name' => 'Atlas Board A']);
    foreach ([[$cpu, 'processor', 'AM5'], [$board, 'motherboard', 'AM4']] as [$product, $type, $socket]) {
        $profile = ProductComponentProfile::factory()->create(['product_id' => $product->id, 'component_type' => $type, 'review_status' => 'approved', 'current_revision' => 2]);
        ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => 1, 'review_status' => 'approved', 'specifications' => ['socket' => 'AM5']]);
        ComponentSpecificationRevision::factory()->create(['product_component_profile_id' => $profile->id, 'revision' => 2, 'review_status' => 'approved', 'specifications' => ['socket' => $socket]]);
    }

    $response = app(ChatbotCatalogAdvice::class)->answer('Is Helios CPU Z compatible with Atlas Board A?');
    $finding = collect($response['context']['compatibility']['findings'])->firstWhere('rule', 'cpu_motherboard.socket');

    expect($finding['status'])->toBe('incompatible');
    expect($response['message'])->toContain('AM5', 'AM4', 'complete-build guarantee');
    expect($response['context']['compatibility']['complete'])->toBeFalse();
});

test('web compatibility followups retain earlier component references through conversation routing', function () {
    $cpu = Product::factory()->available()->create(['name' => 'Helios CPU Z']);
    $board = Product::factory()->available()->create(['name' => 'Atlas Board A']);
    ProductComponentProfile::factory()->create(['product_id' => $cpu->id, 'component_type' => 'processor']);
    ProductComponentProfile::factory()->create(['product_id' => $board->id, 'component_type' => 'motherboard']);
    $state = ['active' => 'product', 'references' => ['product' => [$cpu->id]], 'component_references' => ['processor' => [$cpu->id], 'motherboard' => [$board->id]], 'language' => 'en'];
    $token = app(ChatbotConversationToken::class)->encode($state, 'web:advice:guest');

    $response = app(ChatbotConversation::class)->respond('Will it work with the motherboard mentioned earlier?', null, $token, 'web:advice');

    expect($response['message'])->toContain('Helios CPU Z', 'Atlas Board A', 'Unknown');
    expect($response['context_token'])->not->toBeNull();
});

test('inactive remembered products cannot appear in compatibility answers', function () {
    $cpu = Product::factory()->available()->create(['name' => 'Helios CPU Z']);
    $board = Product::factory()->available()->create(['name' => 'Atlas Board A', 'is_active' => false]);
    ProductComponentProfile::factory()->create(['product_id' => $cpu->id, 'component_type' => 'processor']);
    ProductComponentProfile::factory()->create(['product_id' => $board->id, 'component_type' => 'motherboard']);
    $response = app(ChatbotCatalogAdvice::class)->answer('Is Helios CPU Z compatible with the earlier board?', ['motherboard' => [$board->id]]);

    expect($response['context'])->toBe([]);
    expect($response['message'])->not->toContain('Atlas Board A');
});

test('catalog recommendation phrases retrieve the same factual options across evaluated language examples', function (string $message) {
    Product::factory()->available()->create(['name' => 'Atlas Monitor X240', 'price' => 4000, 'discount_price' => null]);

    $advice = app(ChatbotCatalogAdvice::class);
    expect($advice->supports($message))->toBeTrue();
    expect($advice->answer($message)['message'])->toContain('Atlas Monitor X240', 'PHP 4,000.00');
})->with([
    'English' => 'Recommend an affordable monitor',
    'Tagalog' => 'Meron ba kayong affordable monitor?',
    'Cebuano' => 'Naa moy barato nga monitor?',
    'Hiligaynon' => 'May ara kamo nga affordable nga monitor?',
    'Mixed language' => 'May ara kamo budget monitor under PHP 5000?',
]);
