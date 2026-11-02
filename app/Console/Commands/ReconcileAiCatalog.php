<?php

namespace App\Console\Commands;

use App\Jobs\SyncCatalogProjection;
use App\Jobs\SyncFaqProjection;
use App\Models\CatalogProjection;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\CatalogProjectionRecorder;
use Illuminate\Console\Command;

class ReconcileAiCatalog extends Command
{
    protected $signature = 'chatbot:reconcile-catalog';

    protected $description = 'Queue rebuildable AI catalog projections from current MySQL records';

    public function handle(CatalogProjectionRecorder $recorder, CatalogDocumentBuilder $builder): int
    {
        if (! config('ai_integration.sync_enabled')) {
            $this->warn('AI catalog synchronization is disabled.');

            return self::FAILURE;
        }
        Product::withTrashed()->with(['category', 'tags', 'componentProfile.revisions'])->chunkById(100, function ($products) use ($recorder, $builder): void {
            $projections = CatalogProjection::query()->whereIn('product_id', $products->modelKeys())->get()->keyBy('product_id');
            foreach ($products as $product) {
                $projection = $projections->get($product->id);
                $eligible = ! $product->trashed() && $product->is_active && $product->category->is_active && ! $product->category->trashed();
                $hash = $eligible ? $builder->build($product)['hash'] : null;
                if (! $projection || $projection->content_hash !== $hash) {
                    $recorder->record((int) $product->id);
                } elseif ($projection->last_failure !== null
                    || (config('ai_integration.qdrant.enabled') && $projection->qdrant_revision < $projection->revision)
                    || (config('ai_integration.neo4j.enabled') && $projection->neo4j_revision < $projection->revision)) {
                    SyncCatalogProjection::dispatch((int) $product->id);
                }
            }
        });
        CatalogProjection::query()->whereNotIn('product_id', Product::withTrashed()->select('id'))->chunkById(100, function ($projections) use ($recorder): void {
            foreach ($projections as $projection) {
                $recorder->record((int) $projection->product_id);
            }
        });
        if (config('ai_integration.faq.enabled')) {
            ChatbotKnowledge::query()->select('id')->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    SyncFaqProjection::dispatch($record->id);
                }
            });
        }
        $this->info('Catalog projection reconciliation queued.');

        return self::SUCCESS;
    }
}
