<?php

namespace App\Services\Chatbot\Retrieval;

use App\Jobs\SyncEmbeddingFallback;
use RuntimeException;

class EmbeddingFallback
{
    /** @return list<array{model:string,collection:string}> */
    public function profiles(bool $faq = false, bool $readyOnly = false): array
    {
        $profiles = [];
        foreach ((array) config('ai_integration.embedding.fallback_profiles', []) as $profile) {
            if ($readyOnly && (! is_array($profile) || ($profile[$faq ? 'faq_ready' : 'ready'] ?? false) !== true)) {
                continue;
            }
            if (! is_array($profile) || ! in_array($profile['model'] ?? null, ['gemini-embedding-001', 'gemini-embedding-2'], true)
                || $profile['model'] === config('ai_integration.embedding.model')) {
                continue;
            }
            $collection = $profile[$faq ? 'faq_collection' : 'collection'] ?? null;
            if (! is_string($collection) || preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $collection) !== 1
                || in_array($collection, [config('ai_integration.qdrant.collection'), config('ai_integration.faq.collection')], true)
                || ($profile['collection'] ?? null) === ($profile['faq_collection'] ?? null)) {
                continue;
            }
            $profiles[$profile['model']] = ['model' => $profile['model'], 'collection' => $collection];
        }

        return array_values($profiles);
    }

    public function enqueue(int $id, bool $faq = false): void
    {
        foreach ($this->profiles($faq) as $profile) {
            SyncEmbeddingFallback::dispatch($id, $faq, $profile['model'], $profile['collection']);
        }
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string,mixed>> */
    public function search(string $query, array $filters = [], bool $faq = false): array
    {
        foreach ($this->profiles($faq, true) as $profile) {
            try {
                $vector = app(GeminiEmbeddingClient::class)->embed($query, model: $profile['model']);
                $points = (new QdrantClient($profile['collection'], ! $faq))->searchDocuments($vector, 20, $filters);
                $rows = [];
                foreach ($points as $point) {
                    $payload = $point['payload'];
                    if (($payload['embedding_model'] ?? null) !== $profile['model']
                        || ($payload['embedding_dimensions'] ?? null) !== (int) config('ai_integration.embedding.dimensions')
                        || ! is_string($payload['source_hash'] ?? null)) {
                        continue;
                    }
                    if ($faq) {
                        $rows[] = $point;
                    } elseif (is_int($payload['revision'] ?? null)) {
                        $rows[] = ['product_id' => $point['id'], 'revision' => $payload['revision'], 'score' => $point['score'],
                            'embedding_model' => $profile['model'], 'source_hash' => $payload['source_hash']];
                    }
                }

                return $rows;
            } catch (EmbeddingBudgetExhausted) {
                continue;
            }
        }

        throw new RuntimeException('No compatible embedding fallback is available.');
    }
}
