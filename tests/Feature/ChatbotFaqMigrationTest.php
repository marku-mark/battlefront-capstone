<?php

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Database\Seeders\DevelopmentChatbotKnowledgeSeeder;

test('FAQ migration retires untouched presets and converts order navigation without restoring activation', function () {
    $product = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product,
        'question_pattern' => "Do you have the product I saw on Battlefront's Facebook page?",
        'response_template' => "Please provide the product name or Facebook post details. Availability and current pricing must be checked against Battlefront's catalog and Sagay inventory.",
        'priority' => 80,
    ]);
    $inactive = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Store, 'question_pattern' => 'How can I contact the Sagay store?',
        'response_template' => 'You can contact the Sagay City branch at 0938 647 6046 or battlefrontcomputertrading@gmail.com.', 'priority' => 100,
    ]);
    $navigation = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Order, 'question_pattern' => 'How can I check my order status?',
        'response_template' => 'Sign in and open your order history to view the latest status for your order.', 'priority' => 70,
    ]);
    $migration = require database_path('migrations/2026_10_10_164240_refine_chatbot_knowledge_into_faqs.php');
    $migration->up();
    $migration->up();
    expect($product->refresh()->is_active)->toBeFalse()
        ->and($inactive->refresh()->is_active)->toBeFalse()
        ->and($navigation->refresh()->category)->toBe(ChatbotCategory::Faq)
        ->and($navigation->is_active)->toBeFalse()->and($navigation->priority)->toBe(70);
    $this->assertDatabaseCount('chatbot_knowledge', 3);
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    expect($navigation->refresh()->is_active)->toBeFalse()->and($product->refresh()->is_active)->toBeFalse();
    $this->assertDatabaseCount('chatbot_knowledge', 11);
});

test('FAQ migration preserves edited and custom records', function (array $changes) {
    $record = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Product, 'question_pattern' => 'Do you have Piso WiFi products available?',
        'response_template' => "Piso WiFi product availability must be checked against Battlefront's current catalog and Sagay inventory. You may also contact the Sagay City branch.",
        'priority' => 80, ...$changes,
    ]);
    $before = $record->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_10_10_164240_refine_chatbot_knowledge_into_faqs.php');
    $migration->up();
    expect($record->refresh()->getAttributes())->toBe($before);
})->with([
    'edited answer' => [['response_template' => 'Administrator guidance.']],
    'edited question' => [['question_pattern' => 'Do you have custom products?']],
    'edited category' => [['category' => ChatbotCategory::Faq]],
    'edited priority' => [['priority' => 0]],
    'case edit' => [['question_pattern' => 'DO YOU HAVE PISO WIFI PRODUCTS AVAILABLE?']],
]);

test('FAQ migration refuses rollback rather than guessing previous activation states', function () {
    $migration = require database_path('migrations/2026_10_10_164240_refine_chatbot_knowledge_into_faqs.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward-only');
});

test('retired preset cleanup deletes every unchanged inactive preset and preserves current FAQs and custom knowledge', function () {
    $presets = [
        ['store', 'What time does the Sagay store open and close?', 'The Sagay City branch\'s confirmed operating hours are 8:00 AM–6:00 PM.', 100],
        ['store', 'Where is the Sagay City branch located?', 'The Sagay City branch is located at A, E Marañon St., Brgy. Poblacion II, Sagay City, Negros Occidental (beside LBC Sagay City), Sagay, Philippines 6122.', 100],
        ['store', 'How can I contact the Sagay store?', 'You can contact the Sagay City branch at 0938 647 6046 or battlefrontcomputertrading@gmail.com.', 100],
        ['product', 'Is this product currently available?', 'Product availability changes with current inventory. Please provide the product name so availability can be checked against Battlefront\'s current catalog and Sagay inventory.', 80],
        ['product', 'Do you have the product I saw on Battlefront\'s Facebook page?', 'Please provide the product name or Facebook post details. Availability and current pricing must be checked against Battlefront\'s catalog and Sagay inventory.', 80],
        ['product', 'Do you have Piso WiFi products available?', 'Piso WiFi product availability must be checked against Battlefront\'s current catalog and Sagay inventory. You may also contact the Sagay City branch.', 80],
        ['order', 'Can I choose pickup or delivery?', 'Battlefront supports pickup and delivery. Choose an available fulfillment option during checkout; a delivery address is required for delivery.', 70],
        ['order', 'What payment methods can I use?', 'Pickup orders support Cash, Card at store, GCash, and Maya. Delivery orders support GCash and Maya.', 70],
        ['faq', 'How is payment proof verified for each method?', 'GCash and Maya require uploaded payment proof, which Battlefront staff review manually. Cash and Card at store are pickup payment methods and do not require an upload; staff confirm those payments manually. Check your order history for the latest payment status.', 60],
        ['faq', 'How is GCash payment verified?', 'GCash payment proof is reviewed manually by Battlefront staff. Check your order history for the updated payment status.', 60],
    ];
    $retired = [];
    foreach ($presets as [$category, $question, $answer, $priority]) {
        $retired[] = ChatbotKnowledge::factory()->inactive()->create([
            'category' => $category, 'question_pattern' => $question,
            'response_template' => $answer, 'priority' => $priority,
        ]);
    }
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);
    ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq, 'question_pattern' => 'How can I navigate my account?',
    ]);
    $before = ChatbotKnowledge::query()->where('is_active', true)->orderBy('id')->get()
        ->map(fn (ChatbotKnowledge $record): array => $record->getAttributes())->all();
    $migration = require database_path('migrations/2026_10_11_032824_delete_unchanged_retired_chatbot_presets.php');

    $migration->up();
    $migration->up();
    $this->seed(DevelopmentChatbotKnowledgeSeeder::class);

    foreach ($retired as $record) {
        $this->assertModelMissing($record);
    }
    $this->assertDatabaseCount('chatbot_knowledge', 10);
    expect(ChatbotKnowledge::query()->orderBy('id')->get()
        ->map(fn (ChatbotKnowledge $record): array => $record->getAttributes())->all())->toBe($before);
});

test('retired preset cleanup preserves edited presets and active records', function (array $changes) {
    $record = ChatbotKnowledge::factory()->inactive()->create([
        'category' => ChatbotCategory::Product, 'question_pattern' => 'Do you have Piso WiFi products available?',
        'response_template' => "Piso WiFi product availability must be checked against Battlefront's current catalog and Sagay inventory. You may also contact the Sagay City branch.",
        'priority' => 80, ...$changes,
    ]);
    $before = $record->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_10_11_032824_delete_unchanged_retired_chatbot_presets.php');

    $migration->up();

    $this->assertModelExists($record);
    expect($record->refresh()->getAttributes())->toBe($before);
})->with([
    'edited answer' => [['response_template' => 'Administrator guidance.']],
    'edited question' => [['question_pattern' => 'Do you have custom products?']],
    'edited category' => [['category' => ChatbotCategory::Faq]],
    'edited priority' => [['priority' => 0]],
    'question case edit' => [['question_pattern' => 'DO YOU HAVE PISO WIFI PRODUCTS AVAILABLE?']],
    'answer case edit' => [['response_template' => "PISO WIFI product availability must be checked against Battlefront's current catalog and Sagay inventory. You may also contact the Sagay City branch."]],
    'active preset' => [['is_active' => true]],
]);

test('retired preset cleanup refuses rollback rather than recreating deleted records', function () {
    $migration = require database_path('migrations/2026_10_11_032824_delete_unchanged_retired_chatbot_presets.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward-only');
});
