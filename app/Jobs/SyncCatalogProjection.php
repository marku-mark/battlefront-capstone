<?php

namespace App\Jobs;

use App\Models\CatalogProjection;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncCatalogProjection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $productId)
    {
        $this->afterCommit();
        $this->onQueue('chatbot-indexing');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(CatalogDocumentBuilder $builder, GeminiEmbeddingClient $embeddings, QdrantClient $qdrant, Neo4jClient $graph): void
    {
        if (! config('ai_integration.sync_enabled')) {
            return;
        }
        $lock = Cache::lock('chatbot:projection:'.$this->productId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $projection = CatalogProjection::query()->where('product_id', $this->productId)->first();
            if (! $projection) {
                return;
            }
            $product = Product::query()->customerEligible()->find($this->productId);
            $document = $product ? $builder->build($product) : null;
            $vectorFailure = null;
            try {
                if (config('ai_integration.qdrant.enabled') && $projection->qdrant_revision < $projection->revision) {
                    if ($document) {
                        $qdrant->upsert($this->productId, $embeddings->embed($document['text'], true), ['product_id' => $this->productId, 'revision' => $projection->revision, 'category_id' => $document['category_id'], 'product_family' => $document['product_family'], 'product_role' => $document['product_role'], 'brand' => $document['brand'], 'component_type' => $document['component_type'], 'source_hash' => $document['hash']]);
                    } else {
                        $qdrant->delete($this->productId);
                    }
                    $projection->qdrant_revision = $projection->revision;
                    if (! $this->acknowledge($projection, 'qdrant_revision', $document['hash'] ?? null)) {
                        return;
                    }
                }
            } catch (Throwable $exception) {
                $vectorFailure = $exception;
            }
            if (config('ai_integration.neo4j.enabled') && $projection->neo4j_revision < $projection->revision) {
                $graph->project($this->productId, $projection->revision, $document);
                $projection->neo4j_revision = $projection->revision;
                if (! $this->acknowledge($projection, 'neo4j_revision', $document['hash'] ?? null)) {
                    return;
                }
            }
            if ($vectorFailure !== null) {
                throw $vectorFailure;
            }
            CatalogProjection::query()->whereKey($projection->id)->where('revision', $projection->revision)->update([
                'qdrant_revision' => $projection->qdrant_revision,
                'neo4j_revision' => $projection->neo4j_revision,
                'content_hash' => $document['hash'] ?? null,
                'synced_at' => now(),
                'last_failure' => null,
            ]);
        } catch (EmbeddingBudgetExhausted $exception) {
            CatalogProjection::query()->where('product_id', $this->productId)->update(['last_failure' => 'budget_deferred']);
            self::dispatch($this->productId)->onConnection($this->connection)->onQueue($this->queue)->delay($exception->retryAfter + random_int(0, 30));
        } catch (Throwable) {
            CatalogProjection::query()->where('product_id', $this->productId)->update(['last_failure' => 'provider_unavailable']);
            throw new RuntimeException('Catalog projection synchronization failed.');
        } finally {
            $lock->release();
        }
    }

    private function acknowledge(CatalogProjection $projection, string $providerColumn, ?string $hash): bool
    {
        return CatalogProjection::query()->whereKey($projection->id)->where('revision', $projection->revision)->update([
            $providerColumn => $projection->revision,
            'content_hash' => $hash,
        ]) === 1;
    }
}
