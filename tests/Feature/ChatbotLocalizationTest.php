<?php

use App\Actions\Chatbot\RenderChatbotFallback;
use App\Ai\Agents\ChatbotIntentAgent;
use App\Enums\ChatbotQueryCategory;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\Chatbot\ChatbotConversation;
use App\Services\Chatbot\ChatbotLanguage;
use App\Services\Chatbot\ChatbotLocalizedResponse;
use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;

test('short Hiligaynon availability detection does not classify every kamo phrase as Hiligaynon', function (string $question, string $language) {
    expect(app(ChatbotLanguage::class)->detect($question))->toBe($language);
})->with([
    ['may keyboard kamo?', 'hil'],
    ['may keyboard kamo sa sagay?', 'hil'],
    ['naa kamo keyboard?', 'ceb'],
]);

test('empty constrained catalog replies translate application wording and preserve catalog identifiers', function (string $language) {
    config(['ai_integration.localization_enabled' => true]);
    $message = 'I found no available Sagay products matching these constraints: Atlas, the requested product, under PHP 500.00, excluding Beacon. Try changing the brand, model or budget.';

    $localized = app(ChatbotLocalizedResponse::class)->text($message, $language, ['Atlas', 'Beacon']);

    expect($localized)->toContain('Atlas', 'Beacon', 'PHP 500.00')
        ->not->toContain('matching these constraints', 'the requested product', 'under ', 'excluding ', 'Try changing');
})->with(['hil', 'tl', 'ceb']);

test('Wi-Fi clarification and router follow up retain the selected language', function (string $question, string $language, string $clarification) {
    config(['ai_integration.localization_enabled' => true, 'scout.driver' => 'null']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'Monitor']))->create(['name' => 'Atlas Monitor']);
    $router = Product::factory()->available()->for(Category::factory()->state(['name' => 'Networking']))->create(['name' => 'Atlas Router']);
    $chat = app(ChatbotConversation::class);

    $reply = $chat->respond($question, null, null, 'wifi-language');
    $followUp = $chat->respond('ang router', null, $reply['context_token'], 'wifi-language');

    expect($reply['language'])->toBe($language);
    expect($reply['message'])->toContain($clarification);
    expect($reply['presentation']['sections'][1]['intro'])->toBe($clarification);
    expect($followUp['language'])->toBe($language);
    expect($followUp['message'])->toContain($router->name)->not->toContain('Which product section', 'Do you mean');
    Http::assertNothingSent();
})->with([
    ['may ara kamo monitor kag wifi?', 'hil', 'Wi-Fi adapter, router, ukon iban nga networking product ang imo ginatumod?'],
    ['naa moy monitor ug wifi?', 'ceb', 'Wi-Fi adapter, router, o laing networking product ba imong pasabot?'],
    ['meron kayong monitor at wifi?', 'tl', 'Wi-Fi adapter, router, o ibang networking product ba ang ibig mong sabihin?'],
    ['do you have monitor and wifi?', 'en', 'Do you mean a Wi-Fi adapter, a router, or another networking product?'],
]);

test('early compound clarification uses the current language and preserves it', function (string $question) {
    config(['ai_integration.localization_enabled' => true]);
    $chat = app(ChatbotConversation::class);
    $reply = $chat->respond('RAM and motherboard', null, null, 'early-language');

    $reply = $chat->respond($question, null, $reply['context_token'], 'early-language');

    expect($reply['language'])->toBe('hil');
    expect($reply['message'])->toContain('Ano nga seksyon sang produkto', 'RAM ukon Motherboards')->not->toContain('Which product section');
    $next = $chat->respond('RAM', null, $reply['context_token'], 'early-language');
    expect($next['language'])->toBe('hil');
    Http::assertNothingSent();
})->with(['Under PHP 2000. Reply in Hiligaynon.', 'may ara kamo mas manubo sa PHP 2000?']);

test('application clarifications have translations for each supported regional language', function (string $language) {
    config(['ai_integration.localization_enabled' => true]);
    foreach ([
        'Do you mean a Wi-Fi adapter, a router, or another networking product?',
        'Which product section should I refine: Printer or RAM?',
        'Does that budget apply to both product types, or only RAM?',
        'Which branch do you mean: Sagay City or Bacolod City?',
        'Which product do you mean? Please provide its catalog name.',
        'Which Battlefront branch do you mean? Please provide its city.',
        'Please identify one exact product for alternatives.',
        'The referenced product is no longer available in the catalog.',
        'Please choose either lowest or highest price first.',
        'Battlefront prices are in PHP. Please provide your budget in Philippine pesos.',
        'Please provide a non-negative price range in PHP.',
        'Please provide a price range whose minimum is below its maximum.',
        'Please provide a smaller price amount in PHP.',
        'Available quantity: 12.',
    ] as $source) {
        $translated = app(ChatbotLocalizedResponse::class)->text($source, $language);
        expect($translated)->not->toBe($source);
        expect(app(ChatbotLocalizedResponse::class)->text($source, 'en'))->toBe($source);
    }
})->with(['ceb', 'tl', 'hil']);

test('other branch stock replies politely explain the Sagay scope in the selected language', function (string $question, string $language, string $scope, string $contact) {
    config(['ai_integration.localization_enabled' => true, 'scout.driver' => 'null']);
    Branch::factory()->create(['city' => 'Sagay City']);
    Branch::factory()->create(['city' => 'Bacolod City', 'contact_number' => '09123456789']);
    Product::factory()->available()->for(Category::factory()->state(['name' => 'RAM']))->create(['name' => 'Atlas RAM']);
    $chat = app(ChatbotConversation::class);

    $reply = $chat->respond($question, null, null, 'branch-language');

    expect($reply['language'])->toBe($language);
    expect($reply['message'])->toContain($scope)->not->toContain('out of stock', 'Walang stock', 'Wala sing stock', 'Walay stock');
    expect(mb_strtolower($reply['message']))->toContain(mb_strtolower($contact));
    expect($reply['presentation']['products'])->toBe([]);
    expect(app(ChatbotProviderBudget::class)->usage()['calls'])->toBe(0);
    Http::assertNothingSent();
})->with([
    ['Do you have RAM in Bacolod?', 'en', 'Stock information in this chat covers the Sagay branch only.', 'Please contact'],
    ['Stock at Bacolod branch?', 'en', 'Stock information in this chat covers the Sagay branch only.', 'please contact'],
    ['meron kayong RAM sa Bacolod?', 'tl', 'Para lamang sa Sagay branch', 'makipag-ugnayan'],
    ['naa moy RAM sa Bacolod?', 'ceb', 'Para lamang sa Sagay branch', 'kontaka'],
    ['may ara kamo RAM sa Bacolod?', 'hil', 'Para lamang sa Sagay branch', 'kontaka'],
    ['May stock sa Bacolod? Reply in Tagalog.', 'tl', 'Para lamang sa Sagay branch', 'makipag-ugnayan'],
    ['Stock at other branches? Reply in Cebuano.', 'ceb', 'Para lamang sa Sagay branch', 'kontaka'],
    ['Stock at Bacolod branch? Reply in Hiligaynon.', 'hil', 'Para lamang sa Sagay branch', 'kontaka'],
    ['may ara kamo stock sa iban nga branch?', 'hil', 'Para lamang sa Sagay branch', 'kontaka'],
    ['naa moy stock sa ubang branch?', 'ceb', 'Para lamang sa Sagay branch', 'kontaka'],
    ['meron kayong stock sa ibang branch?', 'tl', 'Para lamang sa Sagay branch', 'makipag-ugnayan'],
]);

test('other branch existence questions retain published branch information', function (string $question) {
    config(['ai_integration.localization_enabled' => true]);
    Branch::factory()->create(['city' => 'Bacolod City', 'address' => 'Published Bacolod address']);

    $reply = app(ChatbotConversation::class)->respond($question, null, null, 'branch-existence');

    expect($reply['message'])->toContain('Published Bacolod address')->not->toContain('Stock information in this chat', 'impormasyon parte sa stock', 'impormasyon tungkol sa stock', 'impormasyon sa stock');
    Http::assertNothingSent();
})->with(['may ara kamo branch sa Bacolod?', 'meron kayong branch sa Bacolod?', 'naa moy branch sa Bacolod?', 'Do you have a branch in Bacolod?']);

test('branch availability introductions and fallbacks are localized', function (string $language) {
    config(['ai_integration.localization_enabled' => true]);
    $localizer = app(ChatbotLocalizedResponse::class);
    foreach ([
        'These matching products are available at the Sagay branch. I cannot confirm stock at other branches.',
        'I cannot confirm stock at Escalante. Live inventory information covers Sagay only. Please contact the branch before visiting.',
        'Published branch information is currently unavailable. Live inventory information covers Sagay only.',
        'I found no available Sagay products matching all of these constraints. Try changing the brand, model or budget. I cannot confirm stock at other branches.',
        'Please provide a product name or model, or clarify your Battlefront question.',
        'By CPU, do you mean a processor or a complete desktop system unit?',
        'Please sign in with your customer account to check your order status.',
    ] as $text) {
        expect($localizer->text($text, $language))->not->toContain('These matching', 'I cannot confirm', 'Live inventory', 'Please contact', 'Published branch', 'I found no', 'matching all', 'Try changing', 'Please provide', 'By CPU', 'Please sign in');
    }
    expect($localizer->text('I cannot confirm stock at Escalante.', $language))->toContain('Escalante');
})->with(['ceb', 'tl', 'hil']);

test('private support refusals match the customer language without sending provider requests', function (string $message, string $expected) {
    config(['ai_integration.localization_enabled' => true]);
    $result = app(ChatbotConversation::class)->respond($message, null, null, 'web:localized');
    expect($result['message'])->toContain($expected);
    expect($result['source'])->toBe('fallback');
    ChatbotIntentAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with([
    ['Where is my order? Reply in English.', 'Please sign in'],
    ['Asa na ang akong order?', 'Palihog sign in'],
    ['Nasaan ang aking order?', 'Mag-sign in'],
    ['Diin na ang akon order?', 'Palihog mag-sign in'],
]);

test('localized facts preserve exact monetary values and references', function (string $language) {
    config(['ai_integration.localization_enabled' => true]);
    $text = app(ChatbotLocalizedResponse::class)->text('BF000041: Order status: Pending. Atlas SSD. Listed price: PHP 2,500.00. Currently out of stock at Sagay.', $language);
    expect($text)->toContain('BF000041', 'Pending', 'Atlas SSD', 'PHP 2,500.00')->not->toContain('Currently out of stock');
})->with(['ceb', 'tl', 'hil']);

test('catalog introductions follow the question language and preserve product facts', function (string $question, string $expected) {
    config(['ai_integration.localization_enabled' => true]);
    $language = app(ChatbotLanguage::class)->select($question);
    foreach (['selected_products', 'catalog'] as $scope) {
        foreach ([null, 'asc', 'desc'] as $sort) {
            $context = [
                'price_selection' => ['scope' => $scope, 'sort' => $sort],
                'products' => [['id' => 7, 'name' => 'BIOSTAR H610M Motherboard', 'price' => '3600.00', 'inventory' => ['status' => 'low_stock']]],
            ];
            $fallback = app(RenderChatbotFallback::class)->execute(ChatbotQueryCategory::Product, $question, $context);

            $reply = app(ChatbotLocalizedResponse::class)->text($fallback, $language, ['BIOSTAR H610M Motherboard']);

            expect($reply)->toStartWith($expected)->toContain("\n\nBIOSTAR H610M Motherboard — PHP 3,600.00.");
            if ($language !== 'en') {
                expect($reply)->not->toContain('Here are', 'ordered by', 'relevance', 'current selling price');
            }
        }
    }
})->with([
    'English' => ['What products do you have? Reply in English.', 'Here are'],
    'Tagalog' => ['Anong mga produkto ang meron kayo? Sagot sa Tagalog.', 'Narito'],
    'Cebuano' => ['Unsa inyong mga produkto? Reply in Cebuano.', 'Ania'],
    'Hiligaynon' => ['Ano nga mga produkto ang ara? Sabat sa Hiligaynon.', 'Ari'],
    'detected Tagalog' => ['Meron ba kayong motherboard?', 'Narito'],
    'detected Cebuano' => ['Naa moy motherboard?', 'Ania'],
    'detected Hiligaynon' => ['May ara kamo motherboard?', 'Ari'],
]);

test('web responses expose the selected language without changing the response source contract', function () {
    config(['ai_integration.localization_enabled' => true]);
    $this->postJson('/chatbot', ['message' => 'Diin na ang akon order?'])
        ->assertOk()->assertJsonPath('language', 'hil')->assertJsonPath('source', 'fallback')
        ->assertJsonPath('message', 'Palihog mag-sign in gamit ang customer account agod makita ang status sang order.');
});

test('mixed language detection follows the dominant regional phrasing and preserves ties', function () {
    $language = app(ChatbotLanguage::class);
    expect($language->select('Diin ang akon order kag meron stock?', 'en'))->toBe('hil');
    expect($language->select('kag meron', 'ceb'))->toBe('ceb');
});

test('custom translations are bound to the exact current approved answer', function () {
    config(['ai_integration.localization_enabled' => true, 'ai_integration.localized_answers.'.hash('sha256', 'Approved answer').'.hil' => 'Aprobado nga sabat']);
    expect(app(ChatbotLocalizedResponse::class)->text('Approved answer', 'hil'))->toBe('Aprobado nga sabat');
    expect(app(ChatbotLocalizedResponse::class)->text('Changed approved answer', 'hil'))->not->toBe('Aprobado nga sabat');
});

test('provider circuit opens after repeated failures and recovers after the cooldown', function () {
    config(['ai_integration.gemini.project_budget_key' => 'circuit-test']);
    $budget = app(ChatbotProviderBudget::class);
    $budget->recordFailure();
    $budget->recordFailure();
    $budget->recordFailure();
    expect($budget->reserve('catalog'))->toBeFalse();
    $this->travel(61)->seconds();
    $budget->reset();
    expect($budget->reserve('catalog'))->toBeTrue();
});

test('concurrent turns share capacity and release it for subsequent requests', function () {
    config(['ai_integration.gemini.max_concurrent_turns' => 1, 'ai_integration.gemini.project_budget_key' => 'concurrency-test']);
    $first = new ChatbotProviderBudget;
    $second = new ChatbotProviderBudget;
    expect($first->reserve('catalog'))->toBeTrue();
    expect($second->reserve('catalog'))->toBeFalse();
    $first->releaseTurn();
    expect($second->reserve('catalog'))->toBeTrue();
    $second->releaseTurn();
});

test('regional catalog intent survives natural phrasing and punctuation', function (string $message, string $expected) {
    expect(app(ChatbotLanguage::class)->canonical($message))->toContain($expected);
})->with(function () {
    $phrases = [
        'en' => ['recommend GPU' => 'recommend', 'GPU under 15000' => 'under', 'cheaper GPU' => 'cheaper', 'GPU not Intel' => 'not Intel', 'GPU for editing' => 'for'],
        'ceb' => ['rekomendahi GPU' => 'recommend', 'GPU dili molapas sa 15000' => 'under', 'mas barato GPU' => 'cheaper', 'GPU ayaw ug Intel' => 'not Intel', 'GPU alang sa editing' => 'for'],
        'tl' => ['irekomenda GPU' => 'recommend', 'GPU hindi lalampas sa 15000' => 'under', 'mas mura GPU' => 'cheaper', 'GPU huwag ng Intel' => 'not Intel', 'GPU para sa editing' => 'for'],
        'hil' => ['ma suggest GPU' => 'recommend', 'GPU indi maglapaw sa 15000' => 'under', 'mas barato GPU' => 'cheaper', 'GPU indi sang Intel' => 'not Intel', 'GPU para sa editing' => 'for'],
    ];
    foreach ($phrases as $language => $cases) {
        foreach ($cases as $message => $expected) {
            foreach (['', 'Please ', 'Battlefront ', '  ', 'Hello, '] as $prefix) {
                foreach (['?', '!'] as $suffix) {
                    yield $language.' '.json_encode([$prefix, $message, $suffix]) => [$prefix.$message.$suffix, $expected];
                }
            }
        }
    }
});
