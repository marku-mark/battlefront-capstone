<?php

namespace App\Services\Chatbot;

use App\Models\CatalogProjection;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GraphCatalogCandidates
{
    public function __construct(private Neo4jClient $graph, private CatalogDocumentBuilder $documents) {}

    /** Shared graph facts identify alternatives, never compatibility verdicts.
     * @return list<int>
     */
    public function ids(int $seedId): array
    {
        return $this->forSeeds([$seedId]);
    }

    /** @param list<int> $seedIds
     * @return list<int> */
    public function forSeeds(array $seedIds): array
    {
        $started = hrtime(true);
        if (config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.neo4j.enabled')) {
            return [];
        }
        try {
            if (Cache::has('chatbot:graph:open')) {
                return [];
            }
            $seeds = Product::query()->customerEligible()->with(['category', 'tags', 'componentProfile.currentApprovedRevision'])
                ->whereIn('id', array_slice($seedIds, 0, 2))->get();
            $seedProjections = CatalogProjection::query()->whereIn('product_id', $seeds->modelKeys())->get()->keyBy('product_id');
            $seeds = $seeds->filter(fn (Product $seed): bool => $seedProjections->has($seed->id) && $this->current($seed, $seedProjections[$seed->id]));
            if ($seeds->isEmpty()) {
                return [];
            }
            $versions = $seeds->map(fn (Product $seed): array => ['id' => $seed->id, 'revision' => $seedProjections[$seed->id]->revision])->values()->all();
            $rows = array_slice($this->graph->candidates($seeds->modelKeys(), $seeds->count() === 1 ? $versions[0]['revision'] : null, $versions), 0, 20);
            $products = Product::query()->customerAvailable()->whereIn('id', array_column($rows, 'product_id'))
                ->whereNotIn('id', $seeds->modelKeys())->with(['category', 'tags', 'componentProfile.currentApprovedRevision'])->get()->keyBy('id');
            $projections = CatalogProjection::query()->whereIn('product_id', $products->modelKeys())->get()->keyBy('product_id');
            $ids = [];
            foreach ($rows as $row) {
                $product = $products->get($row['product_id']);
                $projection = $projections->get($row['product_id']);
                if ($product !== null && $projection !== null && $projection->revision === $row['revision']
                    && $seeds->contains(fn (Product $seed): bool => $this->sameFamily($product, $seed))
                    && $this->current($product, $projection)) {
                    $ids[] = $product->id;
                }
            }

            Log::info('Chatbot retrieval completed.', ['provider' => 'neo4j', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'candidates' => count($rows), 'accepted' => count($ids), 'status' => $ids === [] ? 'no_current_match' : 'matched']);

            return array_slice(array_values(array_unique($ids)), 0, 20);
        } catch (Throwable) {
            Log::warning('Chatbot retrieval failed.', ['provider' => 'neo4j', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'status' => 'unavailable']);
            try {
                Cache::put('chatbot:graph:open', true, 60);
            } catch (Throwable) {
            }

            return [];
        }
    }

    private function sameFamily(Product $product, Product $seed): bool
    {
        $first = $this->documents->build($product);
        $second = $this->documents->build($seed);

        return $first['product_family'] === $second['product_family'] && $first['product_role'] === $second['product_role']
            && $first['component_type'] === $second['component_type'];
    }

    private function current(Product $product, CatalogProjection $projection): bool
    {
        $document = $this->documents->build($product);

        return $document['product_family'] !== 'unknown'
            && ($product->componentProfile === null || $document['specification_revision'] !== null)
            && $projection->revision > 0
            && $projection->neo4j_revision === $projection->revision
            && hash_equals((string) $projection->content_hash, $document['hash']);
    }
}
