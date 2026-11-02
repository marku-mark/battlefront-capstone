<?php

namespace App\Actions\Chatbot\Context;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Actions\Chatbot\MatchChatbotKnowledge;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\ChatbotLocalizedResponse;

class ResolveFaqContext
{
    public function __construct(
        private MatchChatbotKnowledge $knowledge = new MatchChatbotKnowledge,
        private ClassifyChatbotInquiry $boundary = new ClassifyChatbotInquiry,
    ) {}

    /** @return array{knowledge: list<array{question_pattern: string, response_template: string}>} */
    public function execute(string $message, string $language = 'en'): array
    {
        if (! $this->boundary->execute($message)['eligible_for_faq']) {
            return ['knowledge' => []];
        }

        $matches = $this->knowledge->bestMatches($message, allowSemantic: true);
        $records = $language !== 'en' && config('ai_integration.localization_enabled')
            ? ChatbotKnowledge::query()->active()->whereIn('id', array_column($matches, 'id'))->get()->keyBy('id') : collect();

        return ['knowledge' => array_map(function (array $match) use ($language, $records): array {
            $answer = $match['response_template'];
            if ($language !== 'en' && config('ai_integration.localization_enabled')) {
                $answer = $records->get($match['id'])?->reviewedTranslation($language)['response_template']
                    ?? app(ChatbotLocalizedResponse::class)->faq($answer, $language);
            }

            return ['question_pattern' => $match['question_pattern'], 'response_template' => $answer];
        }, $matches)];
    }
}
