<?php

namespace App\Jobs;

use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\Retrieval\GeminiEmbeddingClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SyncFaqProjection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $knowledgeId)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(FaqDocumentBuilder $builder, GeminiEmbeddingClient $embeddings): void
    {
        if (config('ai_integration.offline') || ! config('ai_integration.sync_enabled') || ! config('ai_integration.faq.enabled') || ! config('ai_integration.qdrant.enabled')) {
            return;
        }
        $lock = Cache::lock('chatbot:faq:sync:'.$this->knowledgeId, 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $record = ChatbotKnowledge::query()->find($this->knowledgeId);
            $documents = $record ? $builder->documents($record) : [];
            $hash = $record ? $builder->hash($record) : null;
            $vectors = new QdrantClient((string) config('ai_integration.faq.collection'));
            foreach (['en', 'ceb', 'tl', 'hil'] as $index => $language) {
                $id = $this->knowledgeId * 10 + $index;
                if (! isset($documents[$language])) {
                    $vectors->delete($id);

                    continue;
                }
                $document = $documents[$language];
                $text = mb_strcut($document['question_pattern']."\n".$document['response_template'], 0, 5500, 'UTF-8');
                $vectors->upsert($id, $embeddings->embed($text, true), ['faq_id' => $this->knowledgeId, 'language' => $language, 'source_hash' => $hash, 'revision' => 1]);
            }
        } catch (EmbeddingBudgetExhausted $exception) {
            self::dispatch($this->knowledgeId)->onConnection($this->connection)->onQueue($this->queue)->delay($exception->retryAfter + random_int(0, 30));
        } catch (Throwable) {
            throw new RuntimeException('FAQ projection synchronization failed.');
        } finally {
            $lock->release();
        }
    }
}
