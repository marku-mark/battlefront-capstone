<?php

namespace App\Actions\Chatbot;

use App\Enums\ChatbotCategory;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\ChatbotLanguage;
use Illuminate\Support\Str;

class MatchChatbotKnowledge
{
    /** @var list<string> */
    private const QUERY_WORDS = ['a', 'about', 'an', 'and', 'are', 'at', 'be', 'can', 'could', 'do', 'does', 'for', 'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'or', 'please', 'tell', 'the', 'to', 'use', 'what', 'which', 'with', 'you', 'your'];

    public function __construct(private ClassifyChatbotInquiry $boundary = new ClassifyChatbotInquiry) {}

    /**
     * Match approved question wording only; answer text is never a routing signal.
     *
     * @return list<array{id: int, question_pattern: string, response_template: string, relevance: int, exact: bool}>
     */
    public function execute(string $message, bool $exactOnly = false): array
    {
        $normalized = $this->normalize($message);
        $terms = $this->terms($message);
        $verificationIntent = $this->hasVerificationIntent($terms);
        $builderTopic = (new ChatbotLanguage)->pcBuilderHelpTopic($message);

        if ($normalized === '' || $terms === []) {
            return [];
        }

        return array_values(ChatbotKnowledge::query()->active()
            ->where('category', ChatbotCategory::Faq->value)
            ->select(['id', 'question_pattern', 'response_template'])
            ->get()
            ->filter(fn (ChatbotKnowledge $knowledge): bool => $builderTopic === null || (new ChatbotLanguage)->pcBuilderHelpTopic($knowledge->question_pattern) === $builderTopic)
            ->filter(fn (ChatbotKnowledge $knowledge): bool => $this->boundary->execute($knowledge->question_pattern)['eligible_for_faq'] && trim($knowledge->response_template) !== '')
            ->filter(fn (ChatbotKnowledge $knowledge): bool => $this->hasVerificationIntent($this->terms($knowledge->question_pattern)) === $verificationIntent)
            ->map(function (ChatbotKnowledge $knowledge) use ($normalized, $terms): array {
                return [
                    'id' => $knowledge->id,
                    'question_pattern' => $knowledge->question_pattern,
                    'response_template' => $knowledge->response_template,
                    'relevance' => count(array_intersect($terms, $this->terms($knowledge->question_pattern))),
                    'exact' => $normalized === $this->normalize($knowledge->question_pattern),
                ];
            })
            ->filter(fn (array $match): bool => $match['exact'] || (! $exactOnly && ($builderTopic !== null || $match['relevance'] >= min(2, count($terms)))))
            ->sort(fn (array $left, array $right): int => ($right['exact'] <=> $left['exact'])
                ?: ($right['relevance'] <=> $left['relevance'])
                ?: ($left['id'] <=> $right['id']))
            ->take(3)->all());
    }

    /** @return list<array{id: int, question_pattern: string, response_template: string, relevance: int, exact: bool}> */
    public function bestMatches(string $message): array
    {
        $matches = $this->execute($message);
        if ($matches === []) {
            return [];
        }

        return array_values(array_filter($matches, fn (array $match): bool => $match['exact'] === $matches[0]['exact'] && $match['relevance'] === $matches[0]['relevance']));
    }

    public function hasMeaningfulTerms(string $message): bool
    {
        return $this->terms($message) !== [];
    }

    /** @return list<string> */
    private function terms(string $message): array
    {
        $message = (new ChatbotLanguage)->normalized($message);
        $message = preg_replace('/\b(?:payment methods?|payment options?|ways to pay|how (?:can|do|should) i pay|how to pay|pay by card|card at store|cash payment|gcash|maya|pay cash|paymnt methods?)\b/u', 'payment method', $message);
        $message = preg_replace('/\b(?:accept|accepted|accepts|supported|support|offer|offered)\b/u', '', $message);
        $message = preg_replace('/\b(?:pick up|collection)\b/u', 'pickup', $message);
        $message = preg_replace('/\b(?:deliver|delivry)\b/u', 'delivery', $message);
        $message = preg_replace('/\b(?:verify|verifying|verification)\b/u', 'verified', $message);
        $message = preg_replace('/\bproofs\b/u', 'proof', $message);
        $message = preg_replace('/\b(?:order history|previous orders|past orders)\b/u', 'order status', $message);

        return array_values(array_unique(array_filter(explode(' ', $message), fn (string $term): bool => Str::length($term) >= 2 && ! in_array($term, self::QUERY_WORDS, true))));
    }

    /** @param list<string> $terms */
    private function hasVerificationIntent(array $terms): bool
    {
        return in_array('proof', $terms, true) || in_array('verified', $terms, true);
    }

    public function normalize(string $message): string
    {
        return Str::of($message)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
    }
}
