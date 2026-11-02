<?php

namespace App\Services\Chatbot\Retrieval;

use App\Jobs\SyncCatalogProjection;
use App\Models\CatalogProjection;
use Illuminate\Support\Facades\DB;

class CatalogProjectionRecorder
{
    public function record(int $productId): void
    {
        if (! config('ai_integration.sync_enabled') || $productId < 1) {
            return;
        }
        DB::transaction(function () use ($productId): void {
            CatalogProjection::query()->firstOrCreate(['product_id' => $productId]);
            $projection = CatalogProjection::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
            $projection->increment('revision');
            SyncCatalogProjection::dispatch($productId)->afterCommit();
        });
    }
}
