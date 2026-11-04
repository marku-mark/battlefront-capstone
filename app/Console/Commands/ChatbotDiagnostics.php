<?php

namespace App\Console\Commands;

use App\Models\CatalogProjection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('chatbot:diagnostics')]
#[Description('Report local chatbot projection backlog without contacting providers')]
class ChatbotDiagnostics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pending = CatalogProjection::query()->where(function ($query): void {
            $query->whereColumn('qdrant_revision', '<', 'revision')->orWhereColumn('neo4j_revision', '<', 'revision');
        });
        $this->table(['Metric', 'Value'], [
            ['Full catalog pipeline', config('ai_integration.full_catalog_pipeline') ? 'enabled' : 'disabled'],
            ['Qdrant pending', CatalogProjection::query()->whereColumn('qdrant_revision', '<', 'revision')->count()],
            ['Neo4j pending', CatalogProjection::query()->whereColumn('neo4j_revision', '<', 'revision')->count()],
            ['Adaptive pipeline', config('ai_integration.adaptive') ? 'enabled' : 'disabled'],
            ['Pending projections', (clone $pending)->count()],
            ['Oldest pending update', (clone $pending)->min('updated_at') ?? 'none'],
            ['Quota-deferred projections', CatalogProjection::query()->where('last_failure', 'budget_deferred')->count()],
            ['Failed projections', CatalogProjection::query()->whereNotNull('last_failure')->where('last_failure', '!=', 'budget_deferred')->count()],
            ['Queued projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->count()],
            ['Turn deadline seconds', config('ai_integration.full_catalog_pipeline') ? 25 : config('ai_integration.gemini.deadline_seconds')],
        ]);
        $prefix = 'chatbot:metrics:'.now()->format('Ymd').':';
        $rows = [];
        foreach (['turns', 'gemini', 'fallback', 'failed', 'le_1000', 'le_5000', 'le_15000', 'le_25000', 'over_25000'] as $metric) {
            $rows[] = [$metric, Cache::get($prefix.$metric, 0)];
        }
        $this->table(['Today: duration buckets are exclusive', 'Count'], $rows);
        $stageRows = [];
        foreach (['scope', 'generation', 'validation', 'qdrant', 'neo4j'] as $stage) {
            $stagePrefix = 'chatbot:stage:'.now()->format('Ymd').':'.$stage.':';
            $stageRows[] = [$stage, Cache::get($stagePrefix.'completed', 0), Cache::get($stagePrefix.'failed', 0), Cache::get($stagePrefix.'cache_hit', 0), Cache::get($stagePrefix.'cache_miss', 0), Cache::get($stagePrefix.'duration_ms', 0)];
        }
        $this->table(['Stage', 'Completed', 'Failed', 'Cache hits', 'Cache misses', 'Total milliseconds'], $stageRows);
        $skipRows = [];
        foreach (['qdrant', 'neo4j'] as $provider) {
            foreach (['disabled', 'unsafe_query', 'empty_query', 'circuit_open', 'no_current_seeds'] as $reason) {
                $skipRows[] = [$provider, $reason, Cache::get('chatbot:stage:'.now()->format('Ymd').':'.$provider.':'.$reason, 0)];
            }
        }
        $this->table(['Retrieval provider', 'Skip reason', 'Count'], $skipRows);
        $failures = DB::table('chatbot_failure_events')->where('delete_after', '>', now());
        $this->table(['Private failure review', 'Value'], [
            ['Retained cases', (clone $failures)->count()],
            ['Open cases', (clone $failures)->where('status', 'open')->count()],
            ['Review command', 'php artisan chatbot:failures'],
        ]);

        return self::SUCCESS;
    }
}
