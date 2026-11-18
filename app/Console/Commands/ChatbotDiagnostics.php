<?php

namespace App\Console\Commands;

use App\Models\CatalogProjection;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\QdrantClient;
use App\Services\Chatbot\Retrieval\RetrievalFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('chatbot:diagnostics {--cloud : Read vector collection index metadata without changing cloud data} {--probe-filters : With --cloud, verify the primary product filters using a synthetic vector}')]
#[Description('Report local chatbot health; cloud metadata checks require an explicit flag')]
class ChatbotDiagnostics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('probe-filters') && ! $this->option('cloud')) {
            $this->error('Filter probes require the explicit --cloud flag.');

            return self::FAILURE;
        }
        if ($this->option('cloud')) {
            $checks = [];
            try {
                $checks['primary'] = app(QdrantClient::class)->indexReadiness();
                if ($this->option('probe-filters')) {
                    $checks['primary']['filter_probe_passed'] = false;
                    if ($checks['primary']['ready']) {
                        app(QdrantClient::class)->probeProductFilters();
                        $checks['primary']['filter_probe_passed'] = true;
                    }
                }
                foreach (app(EmbeddingFallback::class)->profiles() as $index => $profile) {
                    $checks['fallback_'.$index] = (new QdrantClient($profile['collection'], true))->indexReadiness();
                }
            } catch (RetrievalFailure $exception) {
                $this->line(json_encode(['available' => false, 'reason' => $exception->reason, 'http_status' => $exception->status], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $ready = array_all($checks, fn (array $check): bool => $check['ready']);
            $this->line(json_encode(['ready' => $ready, 'checks' => $checks, 'changed_cloud_data' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $ready ? self::SUCCESS : self::FAILURE;
        }
        $this->table(['Provider configuration (not live verification)', 'Value'], [
            ['Model', config('ai_integration.gemini.model')],
            ['Scope key', filled(config('ai_integration.gemini.scope_key')) ? 'configured' : 'missing'],
            ['Response key', filled(config('ai_integration.gemini.response_key')) ? 'configured' : 'missing'],
            ['Validation key', filled(config('ai_integration.gemini.validation_key')) ? 'configured' : 'missing'],
            ['Project membership', 'unverified; obtain provider-console evidence'],
            ['Local project limits', config('ai_integration.gemini.enforce_project_limits') ? 'explicitly enabled' : 'disabled; provider enforced'],
            ['Model fallback', config('ai_integration.gemini.fallback_enabled') ? 'enabled on provider limit errors' : 'disabled'],
            ['Maximum role calls per turn', max(1, min(3, (int) config('ai_integration.gemini.max_calls', 3)))],
            ['Embedding fallback indexes', count(app(EmbeddingFallback::class)->profiles())],
            ['Embedding fallback indexes ready', count(app(EmbeddingFallback::class)->profiles(readyOnly: true))],
        ]);
        $pending = CatalogProjection::query()->where(function ($query): void {
            $query->whereColumn('qdrant_revision', '<', 'revision')->orWhereColumn('neo4j_revision', '<', 'revision');
        });
        $this->table(['Metric', 'Value'], [
            ['Full catalog pipeline', config('ai_integration.full_catalog_pipeline') ? 'enabled' : 'disabled'],
            ['Multipart public answers', config('ai_integration.multipart') ? 'enabled' : 'disabled'],
            ['Qdrant pending', CatalogProjection::query()->whereColumn('qdrant_revision', '<', 'revision')->count()],
            ['Neo4j pending', CatalogProjection::query()->whereColumn('neo4j_revision', '<', 'revision')->count()],
            ['Adaptive pipeline', config('ai_integration.adaptive') ? 'enabled' : 'disabled'],
            ['Pending projections', (clone $pending)->count()],
            ['Oldest pending update', (clone $pending)->min('updated_at') ?? 'none'],
            ['Quota-deferred projections', CatalogProjection::query()->where('last_failure', 'budget_deferred')->count()],
            ['Failed projections', CatalogProjection::query()->whereNotNull('last_failure')->where('last_failure', '!=', 'budget_deferred')->count()],
            ['Queued projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->count()],
            ['Reserved projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->whereNotNull('reserved_at')->count()],
            ['Ready projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->count()],
            ['Delayed projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->whereNull('reserved_at')->where('available_at', '>', now()->timestamp)->count()],
            ['Oldest queued projection timestamp', DB::table('jobs')->where('queue', 'chatbot-indexing')->min('created_at') ?? 'none'],
            ['Catalog retrieval circuit', Cache::has('chatbot:retrieval:open') ? 'open' : 'closed'],
            ['Turn deadline seconds', config('ai_integration.full_catalog_pipeline') ? 25 : config('ai_integration.gemini.deadline_seconds')],
        ]);
        $prefix = 'chatbot:metrics:'.now()->format('Ymd').':';
        $rows = [];
        foreach (['turns', 'gemini', 'fallback', 'failed', 'le_1000', 'le_5000', 'le_15000', 'le_25000', 'over_25000'] as $metric) {
            $rows[] = [$metric, Cache::get($prefix.$metric, 0)];
        }
        $this->table(['Today: duration buckets are exclusive', 'Count'], $rows);
        $roleRows = [];
        foreach (['scope', 'response', 'validation'] as $role) {
            $rolePrefix = 'chatbot:role:'.now()->format('Ymd').':'.$role.':';
            $roleRows[] = [$role, Cache::get($rolePrefix.'responses', 0), Cache::get($rolePrefix.'input_tokens', 0), Cache::get($rolePrefix.'output_tokens', 0)];
        }
        $this->table(['Role', 'Responses with usage', 'Input tokens', 'Output tokens'], $roleRows);
        $stageRows = [];
        foreach (['scope', 'generation', 'validation', 'qdrant', 'neo4j'] as $stage) {
            $stagePrefix = 'chatbot:stage:'.now()->format('Ymd').':'.$stage.':';
            $stageRows[] = [$stage, Cache::get($stagePrefix.'completed', 0), Cache::get($stagePrefix.'failed', 0), Cache::get($stagePrefix.'cache_hit', 0), Cache::get($stagePrefix.'cache_miss', 0), Cache::get($stagePrefix.'duration_ms', 0)];
        }
        $this->table(['Stage', 'Completed', 'Failed', 'Cache hits', 'Cache misses', 'Total milliseconds'], $stageRows);
        $skipRows = [];
        foreach (['qdrant', 'neo4j'] as $provider) {
            foreach (['disabled', 'unsafe_query', 'empty_query', 'circuit_open', 'no_current_seeds', 'invalid_configuration', 'deadline_exceeded', 'provider_http', 'transport', 'invalid_response', 'embedding_budget', 'local_or_embedding_failure'] as $reason) {
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
