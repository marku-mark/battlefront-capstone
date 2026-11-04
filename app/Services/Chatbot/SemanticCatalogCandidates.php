<?php

namespace App\Services\Chatbot;

use App\Models\CatalogProjection;
use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SemanticCatalogCandidates
{
    public function __construct(
        private GeminiEmbeddingClient $embeddings,
        private QdrantClient $vectors,
        private CatalogDocumentBuilder $documents,
    ) {}

    /** @return list<int> */
    public function ids(string $query, array $constraints = []): array
    {
        $started = hrtime(true);
        if (config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.qdrant.enabled')
            || strlen($query) > 1000 || str_contains($query, '@')
            || preg_match('/\b(?:order|password|secret|address|phone|token|api key)\b/iu', $query)) {
            return [];
        }
        try {
            $query = app(PublicChatbotQuery::class)->minimize($query);
            if ($query === '') {
                return [];
            }
            if (Cache::has('chatbot:retrieval:open')) {
                return [];
            }
            $filters = [];
            $intent = app(CatalogSearchIntent::class)->resolve(app(ChatbotLanguage::class)->canonical($query));
            if (isset($intent['category'])) {
                $categoryId = Category::query()->where('name', $intent['category'])->value('id');
                if ($categoryId !== null) {
                    $filters['category_id'] = (int) $categoryId;
                }
            }
            foreach (['product_family', 'product_role'] as $field) {
                if (is_string($constraints[$field] ?? null) && $constraints[$field] !== 'any' && $constraints[$field] !== 'unknown') {
                    $filters[$field] = $constraints[$field];
                }
            }
            $key = 'chatbot:catalog:candidates:v3:'.hash('sha256', json_encode([
                config('ai_integration.embedding.model'), config('ai_integration.embedding.dimensions'),
                config('ai_integration.qdrant.collection'), $query, $filters,
            ], JSON_THROW_ON_ERROR));
            $rows = Cache::remember($key, 60, fn (): array => $this->vectors->search($this->embeddings->embed($query), limit: 20, filters: $filters));
            $ids = [];
            $rows = array_slice($rows, 0, 20);
            $products = Product::query()->customerAvailable()->whereIn('id', array_column($rows, 'product_id'))
                ->with(['category', 'tags', 'componentProfile.currentApprovedRevision'])->get()->keyBy('id');
            $projections = CatalogProjection::query()->whereIn('product_id', array_column($rows, 'product_id'))->get()->keyBy('product_id');
            foreach ($rows as $row) {
                $product = $products->get($row['product_id']);
                $projection = $projections->get($row['product_id']);
                if ($product !== null && $projection !== null
                    && $row['score'] >= (float) config('ai_integration.qdrant.score_threshold', 0.7)
                    && $row['revision'] === $projection->revision && $projection->qdrant_revision === $projection->revision
                    && hash_equals((string) $projection->content_hash, $this->documents->build($product)['hash'])) {
                    $ids[] = $product->id;
                }
            }

            Log::info('Chatbot retrieval completed.', ['provider' => 'qdrant', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'candidates' => count($rows), 'accepted' => count($ids), 'status' => $ids === [] ? 'no_current_match' : 'matched']);

            return array_slice(array_values(array_unique($ids)), 0, 20);
        } catch (\Throwable) {
            Log::warning('Chatbot retrieval failed.', ['provider' => 'qdrant', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'status' => 'unavailable']);
            try {
                Cache::put('chatbot:retrieval:open', true, 60);
            } catch (\Throwable) {
            }

            return [];
        }
    }
}
