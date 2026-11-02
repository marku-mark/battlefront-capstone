<?php

namespace App\Services\Chatbot\Retrieval;

use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiEmbeddingClient
{
    /** @return list<float> */
    public function embed(string $text, bool $document = false): array
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
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => $key])->connectTimeout(2)->timeout(5)->withoutRedirecting()->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':embedContent', $body);
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
