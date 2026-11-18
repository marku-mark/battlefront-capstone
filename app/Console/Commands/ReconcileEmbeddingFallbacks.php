<?php

namespace App\Console\Commands;

use App\Jobs\SyncEmbeddingFallback;
use App\Models\CatalogProjection;
use App\Models\ChatbotKnowledge;
use App\Services\Chatbot\Retrieval\EmbeddingBudgetExhausted;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('chatbot:reconcile-embedding-fallbacks {--apply : Rebuild configured fallback indexes after cloud approval} {--inline : Process only fallback projections directly, without consuming other queued work} {--after-product=0 : Inline resume after this public product ID} {--after-faq=0 : Inline resume after this FAQ ID} {--through-product=0 : Inline stop after this product ID, or zero for all remaining records}')]
#[Description('Preview or queue derived fallback embedding projections without changing source records')]
class ReconcileEmbeddingFallbacks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmbeddingFallback $profiles): int
    {
        if (! $this->option('apply')) {
            $this->info('Preview only. --apply queues fallback embedding jobs for configured model/collection pairs; workers contact providers only when synchronization is enabled.');

            return self::SUCCESS;
        }
        if (! config('ai_integration.sync_enabled') || ! config('ai_integration.qdrant.enabled') || config('ai_integration.offline') || ($profiles->profiles() === [] && $profiles->profiles(true) === [])) {
            $this->error('Fallback profiles and synchronization must be configured and enabled separately.');

            return self::FAILURE;
        }
        if ($this->option('inline')) {
            return $this->inline($profiles);
        }
        CatalogProjection::query()->select(['id', 'product_id'])->chunkById(100, function (Collection $rows) use ($profiles): void {
            foreach ($rows as $row) {
                $profiles->enqueue($row->product_id);
            }
        });
        if ($profiles->profiles(true) !== []) {
            ChatbotKnowledge::query()->select('id')->chunkById(100, function (Collection $rows) use ($profiles): void {
                foreach ($rows as $row) {
                    $profiles->enqueue($row->id, true);
                }
            });
        }
        $this->info('Fallback projection jobs queued. Verify completion and retrieval quality before relying on those indexes.');

        return self::SUCCESS;
    }

    private function inline(EmbeddingFallback $profiles): int
    {
        $product = filter_var($this->option('after-product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $faq = filter_var($this->option('after-faq'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $through = filter_var($this->option('through-product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($product === false || $faq === false || $through === false || ($through !== 0 && $through <= $product)) {
            $this->error('Resume IDs must be nonnegative integers.');

            return self::FAILURE;
        }
        try {
            foreach (ChatbotKnowledge::query()->where('id', '>', $faq)->select('id')->lazyById(100) as $record) {
                foreach ($profiles->profiles(true) as $profile) {
                    app()->call([new SyncEmbeddingFallback($record->id, true, $profile['model'], $profile['collection']), 'handle'], ['deferOnLimit' => false]);
                }
                $faq = $record->id;
                $this->line('FAQ checkpoint: '.$faq);
            }
            $processed = 0;
            $query = CatalogProjection::query()->where('product_id', '>', $product)->when($through !== 0, fn (Builder $query): Builder => $query->where('product_id', '<=', $through));
            foreach ($query->select('product_id')->lazyById(100, column: 'product_id') as $record) {
                foreach ($profiles->profiles() as $profile) {
                    app()->call([new SyncEmbeddingFallback($record->product_id, false, $profile['model'], $profile['collection']), 'handle'], ['deferOnLimit' => false]);
                }
                $product = $record->product_id;
                if (++$processed % 25 === 0) {
                    $this->line('Product checkpoint: '.$product);
                }
            }
        } catch (EmbeddingBudgetExhausted $exception) {
            $this->warn('Provider limited embeddings ('.$exception->reason.'); retry after '.$exception->retryAfter.' seconds.');
            $this->line('Resume with --after-product='.$product.' --after-faq='.$faq.' --through-product='.$through);

            return 2;
        } catch (Throwable) {
            $this->error('Fallback backfill interrupted; no source records were changed.');
            $this->line('Resume with --after-product='.$product.' --after-faq='.$faq.' --through-product='.$through);

            return self::FAILURE;
        }
        $this->info('Fallback backfill completed. Last product ID '.$product.'; last FAQ ID '.$faq.'.');

        return self::SUCCESS;
    }
}
