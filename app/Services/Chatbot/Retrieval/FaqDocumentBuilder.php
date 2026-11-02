<?php

namespace App\Services\Chatbot\Retrieval;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;

class FaqDocumentBuilder
{
    public function __construct(private ClassifyChatbotInquiry $boundary) {}

    /** @return array<string, array{question_pattern: string, response_template: string}> */
    public function documents(ChatbotKnowledge $record): array
    {
        if (! $record->is_active || $record->category !== ChatbotCategory::Faq
            || ! $this->boundary->execute($record->question_pattern)['eligible_for_faq']
            || $this->boundary->containsSensitiveInput($record->response_template) || trim($record->response_template) === '') {
            return [];
        }
        $documents = ['en' => ['question_pattern' => $record->question_pattern, 'response_template' => $record->response_template]];
        foreach (['ceb', 'tl', 'hil'] as $language) {
            $translation = $record->reviewedTranslation($language);
            if ($translation !== null && ! $this->boundary->containsSensitiveInput(implode(' ', $translation))) {
                $documents[$language] = $translation;
            }
        }

        return $documents;
    }

    public function hash(ChatbotKnowledge $record): string
    {
        return hash('sha256', json_encode($this->documents($record), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
