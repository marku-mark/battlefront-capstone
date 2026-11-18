<?php

namespace App\Services\Chatbot;

use App\Models\CatalogProjection;
use App\Models\Category;
use App\Models\Product;
use App\Repositories\Catalog\CatalogSearchIntent;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use App\Services\Chatbot\Retrieval\RetrievalFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SemanticCatalogCandidates
{
    public function __construct(
        private GeminiEmbeddingClient $embeddings,
        private QdrantClient $vectors,
        private CatalogDocumentBuilder $documents,
    ) {}

    /** @param array{product_family?: string, product_role?: string} $constraints
     * @return list<int> */
    public function ids(string $query, array $constraints = []): array
    {
        $started = hrtime(true);
        if (config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.qdrant.enabled')
            || strlen($query) > 1000 || str_contains($query, '@')
            || preg_match('/\b(?:order|password|secret|address|phone|token|api key)\b/iu', $query)) {
            app(ChatbotProviderBudget::class)->recordStage('qdrant', config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.qdrant.enabled') ? 'disabled' : 'unsafe_query');

            return [];
        }
        try {
            $query = app(PublicChatbotQuery::class)->minimize($query);
            if ($query === '') {
                app(ChatbotProviderBudget::class)->recordStage('qdrant', 'empty_query');

                return [];
            }
            if (Cache::has('chatbot:retrieval:open')) {
                app(ChatbotProviderBudget::class)->recordStage('qdrant', 'circuit_open');

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
                config('ai_integration.qdrant.collection'), config('ai_integration.embedding.fallback_profiles'), $query, $filters,
            ], JSON_THROW_ON_ERROR));
            app(ChatbotProviderBudget::class)->recordStage('qdrant', Cache::has($key) ? 'cache_hit' : 'cache_miss');
            $rows = Cache::remember($key, 60, function () use ($query, $filters): array {
                try {
                    return $this->vectors->search($this->embeddings->embed($query), limit: 20, filters: $filters);
                } catch (EmbeddingBudgetExhausted) {
                    return app(EmbeddingFallback::class)->search($query, $filters);
                }
            });
            $ids = [];
            $rows = array_slice($rows, 0, 20);
            $products = Product::query()->customerAvailable()->whereIn('id', array_column($rows, 'product_id'))
                ->with(['category', 'tags', 'componentProfile.currentApprovedRevision'])->get()->keyBy('id');
            $projections = CatalogProjection::query()->whereIn('product_id', array_column($rows, 'product_id'))->get()->keyBy('product_id');
            foreach ($rows as $row) {
                $product = $products->get($row['product_id']);
                $projection = $projections->get($row['product_id']);
                $current = $product !== null && $projection !== null && (isset($row['embedding_model'])
                    ? hash_equals($this->documents->build($product, $row['embedding_model'])['hash'], $row['source_hash'])
                    : $projection->qdrant_revision === $projection->revision && hash_equals((string) $projection->content_hash, $this->documents->build($product)['hash']));
                if ($product !== null && $projection !== null
                    && $row['score'] >= (float) config('ai_integration.qdrant.score_threshold', 0.7)
                    && $row['revision'] === $projection->revision && $current) {
                    $ids[] = $product->id;
                }
            }

            app(ChatbotProviderBudget::class)->recordStage('qdrant', 'completed', (int) ((hrtime(true) - $started) / 1_000_000));
            Log::info('Chatbot retrieval completed.', ['provider' => 'qdrant', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'candidates' => count($rows), 'accepted' => count($ids), 'status' => $ids === [] ? 'no_current_match' : 'matched']);

            return array_slice(array_values(array_unique($ids)), 0, 20);
        } catch (\Throwable $exception) {
            app(ChatbotProviderBudget::class)->recordStage('qdrant', 'failed', (int) ((hrtime(true) - $started) / 1_000_000));
            $reason = $exception instanceof RetrievalFailure ? $exception->reason : ($exception instanceof EmbeddingBudgetExhausted ? 'embedding_budget' : 'local_or_embedding_failure');
            app(ChatbotProviderBudget::class)->recordStage('qdrant', $reason);
            $trace = app(ChatbotFailureTrace::class);
            if (count($trace->retrievalFailures) < 2) {
                $trace->retrievalFailures[] = ['provider' => 'qdrant', 'reason' => $reason, 'http_status' => $exception instanceof RetrievalFailure ? $exception->status : null];
            }
            Log::warning('Chatbot retrieval failed.', ['provider' => 'qdrant', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'status' => 'unavailable', 'reason' => $reason]);
            try {
                if ($exception instanceof RetrievalFailure && in_array($reason, ['provider_http', 'transport', 'invalid_response'], true)) {
                    Cache::put('chatbot:retrieval:open', true, 60);
                }
            } catch (\Throwable) {
            }

            return [];
        }
    }
}
