<?php

namespace App\Models;

use App\Enums\ChatbotCategory;
use Database\Factories\ChatbotKnowledgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ChatbotCategory $category
 * @property string $question_pattern
 * @property string $response_template
 * @property int $priority
 * @property bool $is_active
 * @property array<string, mixed>|null $translations
 */
#[Fillable([
    'category',
    'question_pattern',
    'response_template',
    'priority',
    'is_active',
    'translations',
])]
class ChatbotKnowledge extends Model
{
    /** @use HasFactory<ChatbotKnowledgeFactory> */
    use HasFactory;

    /** @var string */
    protected $table = 'chatbot_knowledge';

    /** @var bool */
    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Scope a query to active chatbot knowledge.
     *
     * @param  Builder<ChatbotKnowledge>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ChatbotCategory::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    /** @return array{question_pattern: string, response_template: string}|null */
    public function reviewedTranslation(string $language): ?array
    {
        $translation = $this->translations[$language] ?? null;
        if (! in_array($language, ['ceb', 'tl', 'hil'], true) || ! is_array($translation)
            || ($translation['reviewed'] ?? false) !== true
            || ($translation['source_question'] ?? null) !== $this->question_pattern
            || ($translation['source_answer'] ?? null) !== $this->response_template
            || ! is_string($translation['question_pattern'] ?? null)
            || ! is_string($translation['response_template'] ?? null)
            || trim($translation['question_pattern']) === '' || trim($translation['response_template']) === '') {
            return null;
        }

        return ['question_pattern' => $translation['question_pattern'], 'response_template' => $translation['response_template']];
    }
}
