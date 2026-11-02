<?php

namespace App\Services\Chatbot\Retrieval;

use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiEmbeddingClient
{
    /** @return list<float> */
    public function embed(string $text, bool $document = false): array
    {
        $key = 'chatbot:embedding:v2:'.hash('sha256', json_encode([
            config('ai_integration.embedding.model'), config('ai_integration.embedding.dimensions'), $document, $text,
        ], JSON_THROW_ON_ERROR));
        $cached = $this->cachedVector(Cache::get($key));
        if ($cached !== null) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 10);
        if (! $lock->get()) {
            throw new RuntimeException('Embedding already in progress.');
        }
        try {
            $cached = $this->cachedVector(Cache::get($key));
            if ($cached !== null) {
                return $cached;
            }
            $vector = $this->requestEmbedding($text, $document);
            Cache::put($key, $vector, $document ? 604800 : 86400);

            return $vector;
        } finally {
            $lock->release();
        }
    }

    /** @return list<float>|null */
    private function cachedVector(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== (int) config('ai_integration.embedding.dimensions', 768)) {
            return null;
        }
        $vector = [];
        foreach ($value as $coordinate) {
            if (! is_float($coordinate) || ! is_finite($coordinate)) {
                return null;
            }
            $vector[] = $coordinate;
        }

        return $vector;
    }

    /** @return list<float> */
    private function requestEmbedding(string $text, bool $document): array
    {
        $model = (string) config('ai_integration.embedding.model', 'gemini-embedding-001');
        $dimensions = (int) config('ai_integration.embedding.dimensions', 768);
        $key = (string) config('ai_integration.embedding.key');
        if (! config('ai_integration.qdrant.enabled') || $key === '' || ! in_array($model, ['gemini-embedding-001', 'gemini-embedding-2'], true) || ! in_array($dimensions, [768, 1536, 3072], true) || trim($text) === '' || strlen($text) > 6000) {
            throw new RuntimeException('Embedding unavailable.');
        }
        $budget = app(ChatbotProviderBudget::class);
        if (! $budget->reserveEmbedding($text)) {
            throw new EmbeddingBudgetExhausted($budget->retryAfter());
        }
        $body = ['model' => 'models/'.$model, 'content' => ['parts' => [['text' => $text]]], 'outputDimensionality' => $dimensions];
        if ($model === 'gemini-embedding-001') {
            $body['taskType'] = $document ? 'RETRIEVAL_DOCUMENT' : 'RETRIEVAL_QUERY';
        }
        $timeout = $document ? 5 : $budget->retrievalTimeout();
        if ($timeout === 0) {
            throw new RuntimeException('Retrieval deadline exceeded.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => $key])->connectTimeout(min(2, $timeout))->timeout($timeout)->withoutRedirecting()->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':embedContent', $body);
            $vector = $response->json('embedding.values');
            if (! $response->successful() || ! is_array($vector) || count($vector) !== $dimensions) {
                throw new RuntimeException('Embedding unavailable.');
            }
            $norm = 0.0;
            foreach ($vector as $value) {
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    throw new RuntimeException('Embedding unavailable.');
                }
                $norm += (float) $value ** 2;
            }
            if ($norm <= 0 || ! is_finite($norm)) {
                throw new RuntimeException('Embedding unavailable.');
            }
            $norm = sqrt($norm);

            return array_values(array_map(fn ($value): float => (float) $value / $norm, $vector));
        } catch (\Throwable) {
            throw new RuntimeException('Embedding unavailable.');
        }
    }
}
