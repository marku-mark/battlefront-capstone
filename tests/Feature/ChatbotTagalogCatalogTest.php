<?php

use App\Actions\Chatbot\Context\ResolveProductContext;
use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Services\Chatbot\ChatbotLanguage;
use Illuminate\Support\Facades\Http;

test('Tagalog availability wording resolves PCs and CPU follow ups to their own categories', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.qdrant.enabled' => false, 'scout.driver' => 'null']);
    Http::preventStrayRequests();
    $pc = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Atlas Desktop PC']);
    $cpu = Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'AMD Ryzen 5 5600']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'PC Case']))->create(['name' => 'CPU CASE COOLMAN ROBIN']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Bundles & Packages']))->create(['name' => 'BUNDLE AMD PROCESSOR AND MOTHERBOARD']);

    $resolver = app(ResolveProductContext::class);
    expect(array_column($resolver->execute('meron po ba kayo pc?')['products'], 'id'))->toBe([$pc->id])
        ->and(array_column($resolver->execute('meron po rin ba kayo cpu?')['products'], 'id'))->toBe([$cpu->id])
        ->and(app(ChatbotLanguage::class)->select('meron po rin ba kayo cpu?'))->toBe('tl');
    config(['ai_integration.offline' => true]);
    $first = $this->postJson('/chatbot', ['message' => 'meron po ba kayo pc?'])
        ->assertOk()->assertJsonPath('language', 'tl');
    expect($first->json('message'))->toContain('Atlas Desktop PC')->not->toContain('CPU CASE', 'BUNDLE AMD');
    $second = $this->postJson('/chatbot', ['message' => 'meron po rin ba kayo cpu?', 'context_token' => $first->json('context_token')])
        ->assertOk()->assertJsonPath('language', 'tl');
    expect($second->json('message'))->toContain('AMD Ryzen 5 5600')->not->toContain('Atlas Desktop PC', 'CPU CASE');
    Http::assertNothingSent();
});

test('Tagalog availability particles are normalized before catalog matching', function (string $message) {
    expect(app(ChatbotLanguage::class)->canonical($message))->toBe('available processor?');
})->with(['meron po rin ba kayo cpu?', 'mayroon po din ba kayong cpu?', 'meron po ba kayo cpu?']);

test('PC accessories retain their specific category', function () {
    $intent = app(CatalogSearchIntent::class);
    expect($intent->resolve('pc case')['category'])->toBe('PC Case')
        ->and($intent->resolve('cpu cooler')['category'])->toBe('Cooling Components');
});
