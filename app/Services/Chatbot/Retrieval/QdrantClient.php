<?php

namespace App\Services\Chatbot\Retrieval;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class QdrantClient
{
    public function __construct(private ?string $collection = null) {}

    public function initialize(): void
    {
        $dimensions = (int) config('ai_integration.embedding.dimensions', 768);
        if (! in_array($dimensions, [768, 1536, 3072], true)) {
            throw new RuntimeException('Invalid embedding dimensions.');
        }
        $existing = $this->request('get', '', [], true);
        if ($existing === []) {
            $this->request('put', '', ['vectors' => ['size' => $dimensions, 'distance' => 'Cosine', 'on_disk' => true]]);
        } elseif (($existing['result']['config']['params']['vectors']['size'] ?? null) !== $dimensions || ($existing['result']['config']['params']['vectors']['distance'] ?? null) !== 'Cosine') {
            throw new RuntimeException('Existing collection does not match embedding configuration.');
        }
        $fields = $this->collection === null ? ['category_id' => 'integer', 'brand' => 'keyword', 'component_type' => 'keyword']
            : ['faq_id' => 'integer', 'language' => 'keyword', 'source_hash' => 'keyword'];
        foreach ($fields as $field => $type) {
            $this->request('put', '/index?wait=true', ['field_name' => $field, 'field_schema' => $type]);
        }
    }

    /** @param list<float> $vector
     * @return list<array{product_id:int,revision:int,score:float}> */
    public function search(array $vector, int $limit = 10): array
    {
        $rows = [];
        foreach ($this->searchDocuments($vector, $limit) as $point) {
            if (is_numeric($point['payload']['revision'] ?? null)) {
                $rows[] = ['product_id' => (int) $point['id'], 'revision' => (int) $point['payload']['revision'], 'score' => (float) $point['score']];
            }
        }

        return $rows;
    }

    /** @param list<float> $vector
     * @return list<array{id: int, payload: array<string, mixed>, score: float}> */
    public function searchDocuments(array $vector, int $limit = 10): array
    {
        $data = $this->request('post', '/points/query', ['query' => $vector, 'limit' => max(1, min(20, $limit)), 'with_payload' => true]);
        $documents = [];
        foreach (array_slice($data['result']['points'] ?? [], 0, 20) as $point) {
            if (! is_array($point) || ! is_array($point['payload'] ?? null) || ! is_numeric($point['score'] ?? null)) {
                continue;
            }
            $id = filter_var($point['id'] ?? null, FILTER_VALIDATE_INT);
            $score = (float) $point['score'];
            if ($id === false || $id <= 0 || ! is_finite($score)) {
                continue;
            }
            $payload = [];
            foreach ($point['payload'] as $key => $value) {
                if (is_string($key)) {
                    $payload[$key] = $value;
                }
            }
            $documents[] = ['id' => $id, 'payload' => $payload, 'score' => $score];
        }

        return $documents;
    }

    /** @param list<float> $vector
     * @param array<string,mixed> $payload */
    public function upsert(int $id, array $vector, array $payload): void
    {
        $this->request('put', '/points?wait=true', ['points' => [['id' => $id, 'vector' => $vector, 'payload' => $payload]]]);
    }

    public function delete(int $id): void
    {
        $this->request('post', '/points/delete?wait=true', ['points' => [$id]]);
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function request(string $method, string $path, array $body, bool $allowMissing = false): array
    {
        $url = rtrim((string) config('ai_integration.qdrant.url'), '/');
        $key = (string) config('ai_integration.qdrant.key');
        $collection = $this->collection ?? (string) config('ai_integration.qdrant.collection');
        if (! config('ai_integration.qdrant.enabled') || ! preg_match('~^https://[a-zA-Z0-9.-]+(?::6333)?$~D', $url) || $key === '' || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $collection)) {
            throw new RuntimeException('Qdrant unavailable.');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['api-key' => $key])->connectTimeout(2)->timeout(5)->withoutRedirecting()->send(strtoupper($method), $url.'/collections/'.$collection.$path, $method === 'get' ? [] : ['json' => $body]);
            if ($allowMissing && $response->status() === 404) {
                return [];
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('Qdrant unavailable.');
            }

            return $response->json();
        } catch (\Throwable) {
            throw new RuntimeException('Qdrant unavailable.');
        }
    }
}
