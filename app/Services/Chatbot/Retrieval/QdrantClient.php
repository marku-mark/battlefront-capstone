<?php

namespace App\Services\Chatbot\Retrieval;

use App\Services\Chatbot\ChatbotProviderBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class QdrantClient
{
    public function __construct(private ?string $collection = null, private bool $productCollection = false) {}

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
        foreach ($this->indexFields() as $field => $type) {
            $this->request('put', '/index?wait=true', ['field_name' => $field, 'field_schema' => $type]);
        }
    }

    /** @return array{ready: bool, dimensions_match: bool, distance_match: bool, missing_indexes: list<string>, mismatched_indexes: list<string>} */
    public function indexReadiness(): array
    {
        $info = $this->request('get', '', [])['result'] ?? [];
        $dimensionsMatch = ($info['config']['params']['vectors']['size'] ?? null) === (int) config('ai_integration.embedding.dimensions', 768);
        $distanceMatch = ($info['config']['params']['vectors']['distance'] ?? null) === 'Cosine';
        $missing = $mismatched = [];
        foreach ($this->indexFields() as $field => $type) {
            if (! isset($info['payload_schema'][$field])) {
                $missing[] = $field;
            } elseif (($info['payload_schema'][$field]['data_type'] ?? null) !== $type) {
                $mismatched[] = $field;
            }
        }

        return ['ready' => $dimensionsMatch && $distanceMatch && $missing === [] && $mismatched === [],
            'dimensions_match' => $dimensionsMatch, 'distance_match' => $distanceMatch,
            'missing_indexes' => $missing, 'mismatched_indexes' => $mismatched];
    }

    /** @return array<string, string> */
    private function indexFields(): array
    {
        return $this->collection === null || $this->productCollection
            ? ['category_id' => 'integer', 'brand' => 'keyword', 'component_type' => 'keyword', 'product_family' => 'keyword', 'product_role' => 'keyword']
            : ['faq_id' => 'integer', 'language' => 'keyword', 'source_hash' => 'keyword'];
    }

    /** @return array{created_indexes: list<string>, readiness: array<string, mixed>} */
    public function addMissingIndexes(): array
    {
        $readiness = $this->indexReadiness();
        if (! $readiness['dimensions_match'] || ! $readiness['distance_match'] || $readiness['mismatched_indexes'] !== []) {
            throw new RetrievalFailure('incompatible_index_configuration');
        }
        $fields = $this->indexFields();
        foreach ($readiness['missing_indexes'] as $field) {
            $this->request('put', '/index?wait=true', ['field_name' => $field, 'field_schema' => $fields[$field]]);
        }

        return ['created_indexes' => $readiness['missing_indexes'],
            'readiness' => $readiness['missing_indexes'] === [] ? $readiness : $this->indexReadiness()];
    }

    /** Verify filter acceptance with a synthetic vector; this does not measure semantic relevance. */
    public function probeProductFilters(): void
    {
        $dimensions = (int) config('ai_integration.embedding.dimensions', 768);
        if (! in_array($dimensions, [768, 1536, 3072], true)) {
            throw new RetrievalFailure('invalid_configuration');
        }
        $vector = array_fill(0, $dimensions, 0.0);
        $vector[0] = 1.0;
        $this->search($vector, limit: 1, filters: ['product_family' => 'laptop', 'product_role' => 'main']);
    }

    /** @param list<float> $vector
     * @param array<string, mixed> $filters
     * @return list<array{product_id:int,revision:int,score:float}> */
    public function search(array $vector, int $limit = 10, array $filters = []): array
    {
        $rows = [];
        foreach ($this->searchDocuments($vector, $limit, $filters) as $point) {
            if (is_numeric($point['payload']['revision'] ?? null)) {
                $rows[] = ['product_id' => (int) $point['id'], 'revision' => (int) $point['payload']['revision'], 'score' => (float) $point['score']];
            }
        }

        return $rows;
    }

    /** @param list<float> $vector
     * @param array<string, mixed> $filters
     * @return list<array{id: int, payload: array<string, mixed>, score: float}> */
    public function searchDocuments(array $vector, int $limit = 10, array $filters = []): array
    {
        $must = [];
        foreach (['category_id', 'brand', 'component_type', 'product_family', 'product_role'] as $field) {
            if (isset($filters[$field]) && (is_string($filters[$field]) || is_int($filters[$field]))) {
                $must[] = ['key' => $field, 'match' => ['value' => $filters[$field]]];
            }
        }
        $data = $this->request('post', '/points/query', [
            'query' => $vector, 'limit' => max(1, min(20, $limit)), 'with_payload' => true,
            ...($must !== [] ? ['filter' => ['must' => $must]] : []),
        ]);
        if (! is_array($data['result'] ?? null) || ! is_array($data['result']['points'] ?? null)
            || ! array_is_list($data['result']['points'])) {
            throw new RetrievalFailure('invalid_response');
        }
        $documents = [];
        foreach (array_slice($data['result']['points'], 0, 20) as $point) {
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

    /** @param list<int> $ids
     * @return array<int,array<string,mixed>> */
    public function payloads(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (Validator::make(['ids' => $ids], [
            'ids' => ['array', 'list', 'max:100'],
            'ids.*' => ['bail', 'required', 'integer:strict', 'min:1'],
        ])->fails()) {
            throw new RuntimeException('Invalid point identifiers.');
        }
        $response = $this->request('post', '/points', ['ids' => $ids, 'with_payload' => true, 'with_vector' => false]);
        if (! is_array($response['result'] ?? null) || ! array_is_list($response['result'])) {
            throw new RetrievalFailure('invalid_response');
        }
        $payloads = [];
        foreach ($response['result'] as $point) {
            if (is_int($point['id'] ?? null) && in_array($point['id'], $ids, true) && is_array($point['payload'] ?? null)) {
                $payloads[$point['id']] = $point['payload'];
            }
        }

        return $payloads;
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function request(string $method, string $path, array $body, bool $allowMissing = false): array
    {
        $url = rtrim((string) config('ai_integration.qdrant.url'), '/');
        $key = (string) config('ai_integration.qdrant.key');
        $collection = $this->collection ?? (string) config('ai_integration.qdrant.collection');
        if (! config('ai_integration.qdrant.enabled') || ! preg_match('~^https://[a-zA-Z0-9.-]+(?::6333)?$~D', $url) || $key === '' || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $collection)) {
            throw new RetrievalFailure('invalid_configuration');
        }
        try {
            $timeout = $path === '/points/query' ? app(ChatbotProviderBudget::class)->retrievalTimeout() : 5;
            if ($timeout === 0) {
                throw new RetrievalFailure('deadline_exceeded');
            }
            $response = Http::acceptJson()->withHeaders(['api-key' => $key])->connectTimeout(min(2, $timeout))->timeout($timeout)->withoutRedirecting()->send(strtoupper($method), $url.'/collections/'.$collection.$path, $method === 'get' ? [] : ['json' => $body]);
            if ($allowMissing && $response->status() === 404) {
                return [];
            }
            if (! $response->successful()) {
                throw new RetrievalFailure('provider_http', $response->status());
            }
            if (! is_array($response->json())) {
                throw new RetrievalFailure('invalid_response');
            }

            return $response->json();
        } catch (RetrievalFailure $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new RetrievalFailure('transport');
        }
    }
}
