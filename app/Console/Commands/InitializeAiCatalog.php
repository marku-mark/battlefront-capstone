<?php

namespace App\Console\Commands;

use App\Services\Chatbot\Retrieval\Neo4jClient;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Console\Command;
use Throwable;

class InitializeAiCatalog extends Command
{
    protected $signature = 'chatbot:initialize-catalog {--apply : Create configured cloud collection indexes and graph constraints}';

    protected $description = 'Preview or explicitly initialize approved AI catalog infrastructure';

    public function handle(QdrantClient $qdrant, Neo4jClient $graph): int
    {
        if (! $this->option('apply')) {
            $this->info('Preview: initialize the configured vector collection and graph uniqueness constraints. No requests sent. Use --apply only after cloud setup approval.');

            return self::SUCCESS;
        }
        if (! config('ai_integration.qdrant.enabled') && ! config('ai_integration.neo4j.enabled')) {
            $this->error('No catalog provider is enabled.');

            return self::FAILURE;
        }
        try {
            if (config('ai_integration.qdrant.enabled')) {
                $qdrant->initialize();
                if (config('ai_integration.faq.enabled')) {
                    (new QdrantClient((string) config('ai_integration.faq.collection')))->initialize();
                }
            }
            if (config('ai_integration.neo4j.enabled')) {
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
