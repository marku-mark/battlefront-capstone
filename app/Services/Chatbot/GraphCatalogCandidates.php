<?php

namespace App\Services\Chatbot;

use App\Models\CatalogProjection;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\Neo4jClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GraphCatalogCandidates
{
    public function __construct(private Neo4jClient $graph, private CatalogDocumentBuilder $documents) {}

    /** Shared graph facts identify alternatives, never compatibility verdicts.
     * @return list<int>
     */
    public function ids(int $seedId): array
    {
        if (config('ai_integration.offline') || ! config('ai_integration.enabled') || ! config('ai_integration.neo4j.enabled')) {
            return [];
        }
        try {
            if (Cache::has('chatbot:graph:open')) {
                return [];
            }
            $seed = Product::query()->customerEligible()->with(['category', 'componentProfile.revisions'])->find($seedId);
            $seedProjection = CatalogProjection::query()->where('product_id', $seedId)->first();
            if ($seed === null || $seedProjection === null || ! $this->current($seed, $seedProjection)) {
                return [];
            }
            $rows = array_slice($this->graph->candidates([$seedId], $seedProjection->revision), 0, 20);
            $products = Product::query()->customerAvailable()->whereIn('id', array_column($rows, 'product_id'))
                ->where('id', '!=', $seedId)->with(['category', 'componentProfile.revisions'])->get()->keyBy('id');
            $projections = CatalogProjection::query()->whereIn('product_id', $products->modelKeys())->get()->keyBy('product_id');
            $ids = [];
            foreach ($rows as $row) {
                $product = $products->get($row['product_id']);
                $projection = $projections->get($row['product_id']);
                if ($product !== null && $projection !== null && $projection->revision === $row['revision']
                    && $product->componentProfile?->component_type === $seed->componentProfile?->component_type
                    && $this->current($product, $projection)) {
                    $ids[] = $product->id;
                }
            }

            return array_slice(array_values(array_unique($ids)), 0, 6);
        } catch (Throwable) {
            try {
                Cache::put('chatbot:graph:open', true, 60);
            } catch (Throwable) {
            }

            return [];
        }
    }

    private function current(Product $product, CatalogProjection $projection): bool
    {
        $document = $this->documents->build($product);

        return $document['component_type'] !== null
            && $document['specification_revision'] !== null
            && $projection->revision > 0
            && $projection->neo4j_revision === $projection->revision
            && hash_equals((string) $projection->content_hash, $document['hash']);
    }
}
