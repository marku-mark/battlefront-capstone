<?php

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the chatbot knowledge schema follows the approved ERD decisions', function () {
    expect(Schema::getColumnListing('chatbot_knowledge'))->toEqualCanonicalizing([
        'id',
        'category',
        'question_pattern',
        'response_template',
        'priority',
        'is_active',
        'translations',
    ]);
});

test('chatbot knowledge persists with approved defaults and casts', function () {
    $knowledge = ChatbotKnowledge::factory()->create([
        'category' => ChatbotCategory::Faq,
        'question_pattern' => 'What are your store hours?',
        'response_template' => 'Battlefront is open from 8:00 AM to 6:00 PM.',
        'priority' => 10,
    ]);

    $this->assertModelExists($knowledge);
    expect($knowledge->category)->toBe(ChatbotCategory::Faq)
        ->and($knowledge->question_pattern)->toBe('What are your store hours?')
        ->and($knowledge->response_template)->toBe('Battlefront is open from 8:00 AM to 6:00 PM.')
        ->and($knowledge->priority)->toBe(10)
        ->and($knowledge->is_active)->toBeTrue()
        ->and($knowledge->is_active)->toBeBool();
});

test('the database rejects chatbot categories outside the approved enum', function () {
    expect(fn () => DB::table('chatbot_knowledge')->insert([
        'category' => 'support',
        'question_pattern' => 'Can somebody help me?',
        'response_template' => 'Contact Battlefront support.',
        'priority' => 1,
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

test('the active scope excludes inactive chatbot knowledge', function () {
    $activeKnowledge = ChatbotKnowledge::factory()->create();
    ChatbotKnowledge::factory()->inactive()->create();

    $activeRecords = ChatbotKnowledge::active()->get();

    expect($activeRecords)->toHaveCount(1)
        ->and($activeRecords->sole()->is($activeKnowledge))->toBeTrue();
});
