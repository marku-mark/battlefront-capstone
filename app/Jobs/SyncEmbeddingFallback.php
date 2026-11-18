<?php

namespace App\Jobs;

use App\Models\CatalogProjection;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncEmbeddingFallback implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public int $id, public bool $faq, public string $model, public string $collection)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->id.'|'.(int) $this->faq.'|'.$this->model.'|'.$this->collection);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(EmbeddingFallback $profiles, GeminiEmbeddingClient $embeddings, CatalogDocumentBuilder $catalog, FaqDocumentBuilder $faqs, bool $deferOnLimit = true): void
    {
        if (config('ai_integration.offline') || ! config('ai_integration.sync_enabled') || ! config('ai_integration.qdrant.enabled')
            || ! in_array(['model' => $this->model, 'collection' => $this->collection], $profiles->profiles($this->faq), true)) {
            return;
        }
        $lock = Cache::lock('chatbot:fallback:sync:'.$this->uniqueId(), 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $vectors = new QdrantClient($this->collection, ! $this->faq);
            $meta = ['embedding_model' => $this->model, 'embedding_dimensions' => (int) config('ai_integration.embedding.dimensions')];
            if ($this->faq) {
                $record = ChatbotKnowledge::query()->find($this->id);
                $documents = $record ? $faqs->documents($record) : [];
                foreach (['en', 'ceb', 'tl', 'hil'] as $index => $language) {
                    $id = $this->id * 10 + $index;
                    if (! isset($documents[$language])) {
                        $vectors->delete($id);

                        continue;
                    }
                    $text = mb_strcut(implode("\n", $documents[$language]), 0, 5500, 'UTF-8');
                    $vectors->upsert($id, $embeddings->embed($text, true, $this->model), [...$meta, 'faq_id' => $this->id, 'language' => $language, 'source_hash' => $faqs->hash($record), 'revision' => 1]);
                }
            } else {
                $revision = CatalogProjection::query()->where('product_id', $this->id)->value('revision');
                if ($revision === null) {
                    return;
                }
                $product = Product::query()->customerEligible()->find($this->id);
                if ($product === null) {
                    $vectors->delete($this->id);

                    return;
                }
                $document = $catalog->build($product, $this->model);
                $vectors->upsert($this->id, $embeddings->embed($document['text'], true, $this->model), [
                    ...$meta, ...array_intersect_key($document, array_flip(['product_id', 'category_id', 'product_family', 'product_role', 'brand', 'component_type'])),
                    'revision' => (int) $revision, 'source_hash' => $document['hash'],
                ]);
            }
        } catch (EmbeddingBudgetExhausted $exception) {
            if (! $deferOnLimit) {
                throw $exception;
            }
            self::dispatch($this->id, $this->faq, $this->model, $this->collection)->delay($exception->retryAfter + random_int(0, 30));
        } catch (Throwable) {
            throw new RuntimeException('Fallback embedding projection failed.');
        } finally {
            $lock->release();
        }
    }
}
