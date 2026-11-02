<?php

namespace App\Services\Chatbot;

use App\Models\CatalogProjection;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Support\Facades\Cache;

class SemanticCatalogCandidates
{
    public function __construct(
        private GeminiEmbeddingClient $embeddings,
        private QdrantClient $vectors,
        private CatalogDocumentBuilder $documents,
    ) {}

    /** @return list<int> */
    public function ids(string $query): array
    {
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
            $vector = Cache::remember('chatbot:embedding:'.hash('sha256', json_encode([
                config('ai_integration.embedding.model'), config('ai_integration.embedding.dimensions'), $query,
            ], JSON_THROW_ON_ERROR)), 300, fn (): array => $this->embeddings->embed($query));
            $rows = $this->vectors->search($vector);
            $ids = [];
            $rows = array_slice($rows, 0, 20);
            $products = Product::query()->customerAvailable()->whereIn('id', array_column($rows, 'product_id'))
                ->with(['category', 'componentProfile.revisions'])->get()->keyBy('id');
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

            return array_slice(array_values(array_unique($ids)), 0, 6);
        } catch (\Throwable) {
            try {
                Cache::put('chatbot:retrieval:open', true, 60);
            } catch (\Throwable) {
            }

            return [];
        }
    }
}
