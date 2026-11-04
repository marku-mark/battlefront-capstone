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
            ['Adaptive pipeline', config('ai_integration.adaptive') ? 'enabled' : 'disabled'],
            ['Pending projections', (clone $pending)->count()],
            ['Oldest pending update', (clone $pending)->min('updated_at') ?? 'none'],
            ['Failed projections', CatalogProjection::query()->whereNotNull('last_failure')->count()],
            ['Queued projection jobs', DB::table('jobs')->where('queue', 'chatbot-indexing')->count()],
            ['Turn deadline seconds', config('ai_integration.gemini.deadline_seconds')],
        ]);
        $prefix = 'chatbot:metrics:'.now()->format('Ymd').':';
        $rows = [];
        foreach (['turns', 'gemini', 'fallback', 'failed', 'le_1000', 'le_5000', 'le_15000', 'le_25000', 'over_25000'] as $metric) {
            $rows[] = [$metric, Cache::get($prefix.$metric, 0)];
        }
        $this->table(['Today: duration buckets are exclusive', 'Count'], $rows);
        $failures = DB::table('chatbot_failure_events')->where('delete_after', '>', now());
        $this->table(['Private failure review', 'Value'], [
            ['Retained cases', (clone $failures)->count()],
            ['Open cases', (clone $failures)->where('status', 'open')->count()],
            ['Review command', 'php artisan chatbot:failures'],
        ]);

        return self::SUCCESS;
    }
}
