<?php

use App\Ai\Agents\ChatbotEvidenceAgent;
use App\Ai\Agents\ChatbotUnderstandingAgent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductComponentProfile;
use App\Services\Chatbot\ChatbotAiAdapter;
use App\Services\Chatbot\ChatbotCatalogSelection;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ModelLedChatbot;
use Illuminate\Support\Facades\Crypt;
use Laravel\Ai\Exceptions\AiException;

function catalogParameterIntent(array $overrides = []): array
{
    return array_replace(['outcome' => 'supported', 'intent' => 'branch_availability', 'language' => 'en',
        'category_key' => 'laptop', 'search_query' => 'Asus laptop', 'product_mentions' => [], 'attributes' => ['stock'],
        'maximum_php' => null, 'excluded_brands' => [], 'brand' => 'Asus', 'capacity' => '', 'use_case' => '',
        'branch' => '', 'availability' => 'in_stock', 'reference' => 'new', 'position' => 0, 'clarification' => 'none'], $overrides);
}

/** @param array<string, mixed> $reply
 * @return list<int> */
function catalogParameterProductIds(array $reply): array
{
    return array_values(array_column(array_filter($reply['presentation']['actions'], fn (array $action): bool => $action['kind'] === 'product'), 'id'));
}

test('branch shopping retains brand laptop budget and stock together', function (bool $modelLed, string $question) {
    config(['ai_integration.enabled' => $modelLed, 'ai_integration.model_led' => $modelLed, 'scout.driver' => 'null',
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $match = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Acer Laptop A200', 'brand' => 'Acer', 'price' => 30000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A300', 'brand' => 'Asus', 'price' => 50000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([catalogParameterIntent(['maximum_php' => 40000, 'maximum_inclusive' => false])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();

    $reply = $this->postJson('/chatbot', ['message' => $question])->assertOk()->assertJsonPath('source', 'fallback');

    expect($reply->json('message'))->toContain('Sagay', $match->name)->not->toContain('Acer Laptop A200', 'Asus Laptop A300');
    ChatbotEvidenceAgent::assertNeverPrompted();
    if ($modelLed) {
        ChatbotUnderstandingAgent::assertPromptedTimes(1);
    } else {
        ChatbotUnderstandingAgent::assertNeverPrompted();
    }
})->with([
    [false, 'what branch that i can but asus laptop under PHP 40000'],
    [true, 'Which branch can I buy an Asus laptop under PHP 40000?'],
]);

test('other branch stock stays unknown and supplies published contact information', function (string $question) {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'scout.driver' => 'null']);
    Branch::factory()->create(['city' => 'Escalante City', 'contact_number' => '09123456789']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus']);
    ChatbotUnderstandingAgent::fake([catalogParameterIntent(['branch' => 'Escalante'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();

    $reply = $this->postJson('/chatbot', ['message' => $question])->assertOk();

    expect($reply->json('message'))->toContain('cannot confirm stock', 'Escalante', '09123456789')->not->toContain('out of stock');
    expect($reply->json('presentation.products'))->toBe([]);
    ChatbotEvidenceAgent::assertNeverPrompted();
})->with(['Which Asus laptop is available at Escalante branch?', 'Asus laptop in Escalante']);

test('unknown branch names clarify rather than falling back to Sagay', function () {
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $selection = app(ChatbotCatalogSelection::class)->select('Asus laptop in Cebu', catalogParameterIntent(['branch' => 'Cebu']));
    expect($selection['clarification_code'])->toBe('unknown_branch')->and($selection['products'])->toBe([]);
});

test('a branch clarification resumes the original product and price constraints', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'scout.driver' => 'null',
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $product = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A200', 'brand' => 'Asus', 'price' => 50000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([
        catalogParameterIntent(['branch' => 'Cebu', 'maximum_php' => 40000]),
        catalogParameterIntent(['intent' => 'store', 'branch' => 'Sagay', 'brand' => '', 'category_key' => 'other', 'search_query' => 'Sagay']),
    ])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Asus laptop under PHP 40000 at Cebu branch', null, null, 'branch-clarification');
    $second = $chat->respond('Sagay', null, $first['context_token'], 'branch-clarification');
    expect($first['message'])->toContain('Which Battlefront branch');
    expect($second['message'])->toContain('Sagay', $product->name)->not->toContain('Asus Laptop A200');
    ChatbotUnderstandingAgent::assertPromptedTimes(2);
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('catalog refinements retain branch category and budget while replacing or clearing brands', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $asus = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    $acer = Product::factory()->available()->for($category)->create(['name' => 'Acer Laptop A200', 'brand' => 'Acer', 'price' => 30000, 'discount_price' => null]);
    $selection = app(ChatbotCatalogSelection::class);
    $first = $selection->select('Asus laptop at Sagay branch', catalogParameterIntent(['branch' => 'Sagay']));
    $second = $selection->select('under PHP 40000', catalogParameterIntent(['intent' => 'discovery', 'category_key' => 'other', 'search_query' => '', 'brand' => '', 'reference' => 'previous']), ['model_constraints' => $first['selection_constraints']]);
    $third = $selection->select('Acer instead', catalogParameterIntent(['intent' => 'discovery', 'category_key' => 'other', 'search_query' => 'laptop', 'brand' => 'Acer', 'reference' => 'previous']), ['model_constraints' => $second['selection_constraints']]);
    $fourth = $selection->select('any brand', catalogParameterIntent(['intent' => 'discovery', 'category_key' => 'other', 'search_query' => 'laptop', 'brand' => '', 'reference' => 'previous']), ['model_constraints' => $third['selection_constraints']]);

    expect(array_column($second['products'], 'id'))->toBe([$asus->id]);
    expect(array_column($third['products'], 'id'))->toBe([$acer->id]);
    expect(array_column($fourth['products'], 'id'))->toContain($asus->id, $acer->id);
    expect($third['selection_constraints'])->toMatchArray(['category_key' => 'laptop', 'brand' => 'Acer', 'availability' => 'in_stock', 'intent' => 'branch_availability']);
    expect($third['selection_constraints']['price']['maximum'])->toBe('40000.00');
});

test('invalid or invented understanding parameters cannot reach retrieval', function (array $overrides) {
    config(['ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([catalogParameterIntent($overrides)])->preventStrayPrompts();
    expect(app(ChatbotAiAdapter::class)->understand('Asus laptop', []))->toBeNull();
})->with([
    [['availability' => 'definitely_available_everywhere']],
    [['branch' => 'Escalante']],
    [['branch' => ['Sagay']]],
]);

test('pure store questions retain store routing and private questions bypass understanding', function (string $message) {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'ai_integration.full_catalog_pipeline' => true]);
    ChatbotUnderstandingAgent::fake()->preventStrayPrompts();
    expect(app(ModelLedChatbot::class)->eligible($message))->toBeFalse();
    ChatbotUnderstandingAgent::assertNeverPrompted();
})->with(['What are the Sagay branch opening hours?', 'Where is my order BF000001?', 'My password is secret-value']);

test('a new catalog topic clears previous branch and brand constraints', function () {
    config(['scout.driver' => 'null']);
    $selection = app(ChatbotCatalogSelection::class)->select('monitor', catalogParameterIntent([
        'intent' => 'discovery', 'category_key' => 'monitor', 'search_query' => 'monitor', 'brand' => '', 'availability' => 'any',
    ]), ['model_constraints' => ['brand' => 'Asus', 'branch' => 'Escalante', 'category_key' => 'laptop', 'availability' => 'in_stock']]);
    expect($selection['selection_constraints'])->toMatchArray(['brand' => '', 'branch' => '', 'category_key' => 'monitor', 'availability' => 'any']);
});

test('encrypted conversation state carries branch refinements and requeries current prices', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'scout.driver' => 'null',
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake([
        catalogParameterIntent(['branch' => 'Sagay']),
        catalogParameterIntent(['intent' => 'discovery', 'reference' => 'previous', 'category_key' => 'other', 'search_query' => '', 'brand' => '', 'availability' => 'any']),
    ])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Which Sagay branch sells Asus laptop?', null, null, 'branch-follow-up');
    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    $saved = json_decode(Crypt::decryptString($first['context_token']), true);
    expect($saved['state']['model_constraints'] ?? [])->toMatchArray(['brand' => 'Asus', 'intent' => 'branch_availability']);
    $product->update(['price' => 45000]);
    $second = $chat->respond('under PHP 40000', null, $first['context_token'], 'branch-follow-up');
    expect($first['message'])->toContain($product->name);
    expect($second['message'])->toContain('no available Sagay products', 'Asus', '40000.00')->not->toContain($product->name);
    ChatbotUnderstandingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Sagay') && str_contains($prompt->prompt, 'under PHP 40000'));
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('understanding failure uses local constrained branch facts without generation', function () {
    config(['ai_integration.enabled' => true, 'ai_integration.model_led' => true, 'scout.driver' => 'null',
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus']);
    ChatbotUnderstandingAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $reply = $this->postJson('/chatbot', ['message' => 'Which branch can I buy Asus laptop?'])->assertOk()->assertJsonPath('source', 'fallback');
    expect($reply->json('message'))->toContain('Sagay', $product->name);
    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertNeverPrompted();
});

test('catalog filters reject exact named products outside requested family and availability', function () {
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $desktop = Product::factory()->available()->for($category)->create(['name' => 'Asus Desktop PC', 'brand' => 'Asus']);
    $selection = app(ChatbotCatalogSelection::class)->select($desktop->name, catalogParameterIntent([
        'intent' => 'facts', 'product_mentions' => [$desktop->name], 'search_query' => 'laptop',
    ]));
    expect($selection['products'])->toBe([]);
});

test('branch budget interpretations accept distinct regional languages', function (string $message, string $language) {
    config(['ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 60000]);
    ChatbotUnderstandingAgent::fake([catalogParameterIntent(['language' => $language, 'maximum_php' => 40000])])->preventStrayPrompts();
    $intent = app(ChatbotAiAdapter::class)->understand($message, []);
    expect($intent)->toMatchArray(['language' => $language, 'brand' => 'Asus', 'category_key' => 'laptop', 'maximum_php' => 40000, 'intent' => 'branch_availability']);
    ChatbotUnderstandingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, $message));
})->with([
    ['Which branch sells Asus laptops under 40k?', 'en'],
    ['Saang branch may Asus laptop na wala pang 40k?', 'tl'],
    ['Asa nga branch naay Asus laptop ubos sa 40k?', 'ceb'],
    ['Sa diin nga branch may Asus laptop nga manubo sa 40k?', 'hil'],
]);

test('local branch price sorting considers the full eligible catalog and discounted prices', function (string $direction, bool $failure) {
    config(['scout.driver' => 'null', 'ai_integration.enabled' => $failure, 'ai_integration.model_led' => $failure]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    for ($index = 0; $index < 24; $index++) {
        Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop Model'.$index, 'brand' => 'Asus', 'price' => 40000 + $index, 'discount_price' => null]);
    }
    $discounted = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop Discounted', 'brand' => 'Asus', 'price' => 90000, 'discount_price' => 20000]);
    $expensive = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop Premium', 'brand' => 'Asus', 'price' => 70000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();

    $reply = $this->postJson('/chatbot', ['message' => 'Which Sagay branch has the '.$direction.' Asus laptop?'])->assertOk();

    expect($reply->json('presentation.actions.0.id'))->toBe($direction === 'cheapest' ? $discounted->id : $expensive->id);
    ChatbotUnderstandingAgent::assertPromptedTimes($failure ? 1 : 0);
    ChatbotEvidenceAgent::assertNeverPrompted();
})->with([['cheapest', false], ['most expensive', false], ['cheapest', true], ['most expensive', true]]);

test('branch constraint refinements survive empty results across local and model paths', function (string $mode) {
    config(['scout.driver' => 'null', 'ai_integration.enabled' => $mode !== 'local', 'ai_integration.model_led' => $mode !== 'local',
        'ai_integration.gemini.max_calls' => 3, 'ai_integration.gemini.tokens_per_minute' => 1000000]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    Branch::factory()->create(['city' => 'Escalante City']);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $asus = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    $acer = Product::factory()->available()->for($category)->create(['name' => 'Acer Laptop A200', 'brand' => 'Acer', 'price' => 25000, 'discount_price' => null]);
    $refinement = ['reference' => 'previous', 'intent' => 'discovery', 'category_key' => 'other', 'search_query' => '', 'brand' => '', 'availability' => 'any'];
    if ($mode === 'model') {
        ChatbotUnderstandingAgent::fake([
            catalogParameterIntent(['branch' => 'Sagay']),
            catalogParameterIntent($refinement),
            catalogParameterIntent([...$refinement, 'brand' => 'Acer']),
            catalogParameterIntent($refinement),
            catalogParameterIntent($refinement),
            catalogParameterIntent([...$refinement, 'branch' => 'Escalante']),
            catalogParameterIntent($refinement),
        ])->preventStrayPrompts();
    } else {
        ChatbotUnderstandingAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    }
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Asus laptop at Sagay branch', null, null, 'branch-refinements');
    $empty = $chat->respond('under PHP 30000', null, $first['context_token'], 'branch-refinements');
    $brand = $chat->respond('Acer instead', null, $empty['context_token'], 'branch-refinements');
    $anyBrand = $chat->respond('any brand', null, $brand['context_token'], 'branch-refinements');
    $noBudget = $chat->respond('no budget', null, $anyBrand['context_token'], 'branch-refinements');
    $otherBranch = $chat->respond('Escalante', null, $noBudget['context_token'], 'branch-refinements');
    $anyBranch = $chat->respond('any branch', null, $otherBranch['context_token'], 'branch-refinements');

    expect(catalogParameterProductIds($empty))->toBe([]);
    expect($empty['message'])->toContain('Sagay', 'Asus', '30000.00');
    expect(catalogParameterProductIds($brand))->toBe([$acer->id]);
    expect(catalogParameterProductIds($anyBrand))->toBe([$acer->id]);
    expect(catalogParameterProductIds($noBudget))->toContain($asus->id, $acer->id);
    expect($otherBranch['message'])->toContain('cannot confirm stock', 'Escalante');
    expect(catalogParameterProductIds($anyBranch))->toContain($asus->id, $acer->id);
    ChatbotUnderstandingAgent::assertPromptedTimes(match ($mode) {
        'local' => 0, 'failure' => 3, default => 7
    });
    ChatbotEvidenceAgent::assertNeverPrompted();
})->with(['local', 'failure', 'model']);

test('local and failed understanding branch clarifications retain the original filters', function (bool $failure) {
    config(['scout.driver' => 'null', 'ai_integration.enabled' => $failure, 'ai_integration.model_led' => $failure]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    $match = Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop A200', 'brand' => 'Asus', 'price' => 45000, 'discount_price' => null]);
    ChatbotUnderstandingAgent::fake(fn () => throw new AiException)->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Asus laptop under PHP 40000 at Cebu branch', null, null, 'local-clarification');
    $second = $chat->respond('Sagay', null, $first['context_token'], 'local-clarification');

    expect($first['message'])->toContain('Which Battlefront branch');
    expect(catalogParameterProductIds($second))->toBe([$match->id]);
    ChatbotUnderstandingAgent::assertPromptedTimes($failure ? 2 : 0);
    ChatbotEvidenceAgent::assertNeverPrompted();
})->with([false, true]);

test('local branch filters preserve strict price boundaries exclusions and capacity', function (string $budget, array $expectedNames) {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $category = Category::factory()->create(['name' => 'Laptops & Desktops']);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop 16GB Budget', 'brand' => 'Asus', 'price' => 30000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop 16GB Boundary', 'brand' => 'Asus', 'price' => 40000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Asus Laptop 8GB Budget', 'brand' => 'Asus', 'price' => 20000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Acer Laptop 16GB Budget', 'brand' => 'Acer', 'price' => 25000, 'discount_price' => null]);

    $reply = $this->postJson('/chatbot', ['message' => 'Which branch sells laptop 16GB excluding Acer '.$budget.'?'])->assertOk();

    expect(array_column(array_filter($reply->json('presentation.actions'), fn (array $action): bool => $action['kind'] === 'product'), 'label'))->toBe($expectedNames);
})->with([
    ['under PHP 40000', ['Asus Laptop 16GB Budget']],
    ['up to PHP 40000', ['Asus Laptop 16GB Budget', 'Asus Laptop 16GB Boundary']],
]);

test('local branch follow ups requery changed prices and stock', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $product = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Asus laptop at Sagay branch', null, null, 'local-live-facts');
    $product->update(['price' => 45000]);

    $priceChanged = $chat->respond('under PHP 40000', null, $first['context_token'], 'local-live-facts');
    $product->inventory->update(['quantity' => 0]);
    $stockChanged = $chat->respond('no budget', null, $priceChanged['context_token'], 'local-live-facts');
    $product->inventory->update(['quantity' => 10]);
    $restocked = $chat->respond('any brand', null, $stockChanged['context_token'], 'local-live-facts');

    expect(catalogParameterProductIds($first))->toBe([$product->id]);
    expect(catalogParameterProductIds($priceChanged))->toBe([]);
    expect(catalogParameterProductIds($stockChanged))->toBe([]);
    expect(catalogParameterProductIds($restocked))->toBe([$product->id]);
});

test('new local topics clear branch filters even when the new category has no matches', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Asus laptop at Sagay branch', null, null, 'local-new-topic');

    $next = $chat->respond('monitor', null, $first['context_token'], 'local-new-topic');
    $refined = $chat->respond('under PHP 40000', null, $next['context_token'], 'local-new-topic');

    expect(catalogParameterProductIds($refined))->toBe([]);
    $saved = json_decode(Crypt::decryptString($next['context_token']), true);
    expect($saved['state'])->not->toHaveKey('model_constraints')->not->toHaveKey('pending_catalog');
});

test('local branch refinements do not carry across identity changes or expired context', function (bool $expires) {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus']);
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Asus laptop at Sagay branch', null, null, 'original-identity');
    if ($expires) {
        $this->travel(16)->minutes();
    }

    $next = $chat->respond('under PHP 40000', null, $first['context_token'], $expires ? 'original-identity' : 'new-identity');

    expect(catalogParameterProductIds($next))->toBe([]);
    expect($next['message'])->not->toContain('Asus Laptop A100');
})->with([false, true]);

test('local ambiguous branch clarification keeps category and budget constraints', function () {
    config(['scout.driver' => 'null']);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    Branch::factory()->create(['city' => 'Escalante City']);
    $match = Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus', 'price' => 35000, 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Asus laptop under PHP 40000 at Sagay or Escalante branch', null, null, 'ambiguous-local-branch');
    $second = $chat->respond('Sagay', null, $first['context_token'], 'ambiguous-local-branch');

    expect($first['message'])->toContain('Which branch do you mean', 'Sagay', 'Escalante');
    expect(catalogParameterProductIds($second))->toBe([$match->id]);
});

test('local branch inquiries retain component references for deterministic compatibility follow ups', function () {
    config(['scout.driver' => 'null', 'ai_integration.advice_enabled' => true]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    $cpu = Product::factory()->available()->for(Category::factory()->state(['name' => 'Processor']))->create(['name' => 'Helios CPU Z']);
    $board = Product::factory()->available()->for(Category::factory()->state(['name' => 'Motherboard']))->create(['name' => 'Atlas Board A']);
    ProductComponentProfile::factory()->create(['product_id' => $cpu->id, 'component_type' => 'processor']);
    ProductComponentProfile::factory()->create(['product_id' => $board->id, 'component_type' => 'motherboard']);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('Which Sagay branch sells Helios CPU Z?', null, null, 'branch-compatibility');
    $second = $chat->respond('Which Sagay branch sells Atlas Board A?', null, $first['context_token'], 'branch-compatibility');
    $comparison = $chat->respond('Will it work with the processor mentioned earlier?', null, $second['context_token'], 'branch-compatibility');

    expect($comparison['message'])->toContain('Helios CPU Z', 'Atlas Board A', 'Unknown');
});

test('restoring an excluded legacy brand preserves the current budget', function () {
    config(['scout.driver' => 'null', 'ai_integration.advice_enabled' => true]);
    $category = Category::factory()->create(['name' => 'Processor']);
    $amd = Product::factory()->available()->for($category)->create(['name' => 'AMD Processor A', 'brand' => 'AMD', 'price' => 2000, 'discount_price' => null]);
    $intel = Product::factory()->available()->for($category)->create(['name' => 'Intel Processor B', 'brand' => 'Intel', 'price' => 3000, 'discount_price' => null]);
    Product::factory()->available()->for($category)->create(['name' => 'Intel Processor Expensive', 'brand' => 'Intel', 'price' => 5000, 'discount_price' => null]);
    $chat = app(ChatbotConversation::class);

    $first = $chat->respond('recommend processor not Intel under PHP 4000', null, null, 'legacy-brand-restore');
    $restored = $chat->respond('Intel is okay now', null, $first['context_token'], 'legacy-brand-restore');

    expect($first['message'])->toContain($amd->name)->not->toContain($intel->name);
    expect($restored['message'])->toContain($amd->name, $intel->name)->not->toContain('Intel Processor Expensive');
});

test('private order follow ups after a branch inquiry bypass public understanding', function () {
    config(['scout.driver' => 'null', 'ai_integration.enabled' => true, 'ai_integration.model_led' => true]);
    Branch::factory()->create(['city' => config('battlefront.operational_branch_city')]);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Laptops & Desktops']))
        ->create(['name' => 'Asus Laptop A100', 'brand' => 'Asus']);
    ChatbotUnderstandingAgent::fake([catalogParameterIntent(['branch' => 'Sagay'])])->preventStrayPrompts();
    ChatbotEvidenceAgent::fake()->preventStrayPrompts();
    $chat = app(ChatbotConversation::class);
    $first = $chat->respond('Asus laptop at Sagay branch', null, null, 'branch-private-boundary');

    $private = $chat->respond('Where is my order BF000001?', null, $first['context_token'], 'branch-private-boundary');

    expect($private['message'])->toContain('sign in')->not->toContain('Asus Laptop A100');
    ChatbotUnderstandingAgent::assertPromptedTimes(1);
    ChatbotEvidenceAgent::assertNeverPrompted();
});
