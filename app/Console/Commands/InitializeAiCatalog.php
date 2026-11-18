<?php

namespace App\Console\Commands;

use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Console\Command;
use Throwable;

class InitializeAiCatalog extends Command
{
    protected $signature = 'chatbot:initialize-catalog {--apply : Create configured cloud collection indexes and graph constraints} {--fallbacks-only : Initialize only configured paired embedding fallback collections} {--missing-primary-indexes-only : Inspect or add missing indexes on the existing primary vector collection only}';

    protected $description = 'Preview or explicitly initialize approved AI catalog infrastructure';

    public function handle(QdrantClient $qdrant, Neo4jClient $graph): int
    {
        if ($this->option('missing-primary-indexes-only')) {
            if ($this->option('fallbacks-only') || ! config('ai_integration.qdrant.enabled')) {
                $this->error('Primary-index repair requires Qdrant and cannot be combined with fallback initialization.');

                return self::FAILURE;
            }
            try {
                $result = $this->option('apply') ? $qdrant->addMissingIndexes()
                    : ['created_indexes' => [], 'readiness' => $qdrant->indexReadiness()];
            } catch (Throwable) {
                $this->error('Primary-index inspection or repair failed; incompatible indexes are never replaced.');

                return self::FAILURE;
            }
            $this->line(json_encode(['applied' => (bool) $this->option('apply'), ...$result,
                'changed_vectors' => false, 'changed_graph' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ! $this->option('apply') || $result['readiness']['ready'] ? self::SUCCESS : self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Preview: initialize the configured vector collection and graph uniqueness constraints. No requests sent. Use --apply only after cloud setup approval.');

            return self::SUCCESS;
        }
        if (! config('ai_integration.qdrant.enabled') && ! config('ai_integration.neo4j.enabled')) {
            $this->error('No catalog provider is enabled.');

            return self::FAILURE;
        }
        if ($this->option('fallbacks-only') && (! config('ai_integration.qdrant.enabled') || (app(EmbeddingFallback::class)->profiles() === [] && app(EmbeddingFallback::class)->profiles(true) === []))) {
            $this->error('No paired fallback collections are configured for the enabled vector provider.');

            return self::FAILURE;
        }
        try {
            if (config('ai_integration.qdrant.enabled')) {
                if (! $this->option('fallbacks-only')) {
                    $qdrant->initialize();
                }
                foreach (app(EmbeddingFallback::class)->profiles() as $profile) {
                    (new QdrantClient($profile['collection'], true))->initialize();
                }
                if (config('ai_integration.faq.enabled') && ! $this->option('fallbacks-only')) {
                    (new QdrantClient((string) config('ai_integration.faq.collection')))->initialize();
                }
                if (config('ai_integration.faq.enabled') || $this->option('fallbacks-only')) {
                    foreach (app(EmbeddingFallback::class)->profiles(true) as $profile) {
                        (new QdrantClient($profile['collection']))->initialize();
                    }
                }
            }
            if (config('ai_integration.neo4j.enabled') && ! $this->option('fallbacks-only')) {
                $graph->initialize();
            }
        } catch (Throwable) {
            $this->error('Initialization failed; check configuration and provider access. Existing resources were not deleted.');

            return self::FAILURE;
        }
        $this->info('Enabled catalog providers initialized.');

        return self::SUCCESS;
    }
}
