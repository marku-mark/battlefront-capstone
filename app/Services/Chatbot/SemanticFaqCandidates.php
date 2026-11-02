<?php

namespace App\Services\Chatbot;

use App\Actions\Chatbot\ClassifyChatbotInquiry;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SemanticFaqCandidates
{
    public function __construct(private FaqDocumentBuilder $documents, private GeminiEmbeddingClient $embeddings, private ClassifyChatbotInquiry $boundary) {}

    /** @return list<int> */
    public function ids(string $message): array
    {
        if (config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.faq.enabled')
            || ! config('ai_integration.qdrant.enabled') || strlen($message) > 1000 || ! $this->boundary->execute($message)['eligible_for_faq']) {
            return [];
        }
        $query = app(PublicChatbotQuery::class)->minimize($message);
        if ($query === '') {
            return [];
        }
        try {
            if (Cache::has('chatbot:faq:open')) {
                return [];
            }
            $key = 'chatbot:faq:query:'.hash('sha256', json_encode([config('ai_integration.embedding.model'), config('ai_integration.embedding.dimensions'), config('ai_integration.faq.collection'), $query], JSON_THROW_ON_ERROR));
            $rows = Cache::remember($key, 60, fn (): array => (new QdrantClient((string) config('ai_integration.faq.collection')))->searchDocuments($this->embeddings->embed($query)));
            $records = ChatbotKnowledge::query()->active()->whereIn('id', array_column(array_column($rows, 'payload'), 'faq_id'))->get()->keyBy('id');
            $scores = [];
            foreach ($rows as $row) {
                $id = $row['payload']['faq_id'] ?? null;
                $language = $row['payload']['language'] ?? null;
                $record = is_int($id) ? $records->get($id) : null;
                if ($record === null || ! in_array($language, ChatbotLanguage::LANGUAGES, true) || ! is_string($row['payload']['source_hash'] ?? null)
                    || (float) $row['score'] < (float) config('ai_integration.faq.score_threshold', 0.85)
                    || ! isset($this->documents->documents($record)[$language])
                    || ! hash_equals($this->documents->hash($record), $row['payload']['source_hash'])) {
                    continue;
                }
                $scores[$id] = max($scores[$id] ?? 0.0, (float) $row['score']);
            }
            arsort($scores);
            $best = reset($scores);

            return array_slice(array_keys(array_filter($scores, fn (float $score): bool => $best - $score <= (float) config('ai_integration.faq.ambiguity_margin', 0.03))), 0, 3);
        } catch (Throwable) {
            try {
                Cache::put('chatbot:faq:open', true, 60);
            } catch (Throwable) {
            }

            return [];
        }
    }
}
