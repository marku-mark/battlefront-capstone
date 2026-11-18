<?php

namespace App\Console\Commands;

use App\Models\CatalogProjection;
use App\Models\ChatbotKnowledge;
use App\Models\Product;
use App\Services\Chatbot\Retrieval\CatalogDocumentBuilder;
use App\Services\Chatbot\Retrieval\EmbeddingFallback;
use App\Services\Chatbot\Retrieval\FaqDocumentBuilder;
use App\Services\Chatbot\Retrieval\QdrantClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('chatbot:audit-embedding-fallbacks')]
#[Description('Read paired vector payloads and compare current public source hashes without changing data')]
class AuditEmbeddingFallbacks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmbeddingFallback $profiles, CatalogDocumentBuilder $catalog, FaqDocumentBuilder $faqs): int
    {
        if (config('ai_integration.offline') || ! config('ai_integration.qdrant.enabled')) {
            $this->error('The vector provider is disabled.');

            return self::FAILURE;
        }
        $results = [];
        try {
            foreach ([false, true] as $faq) {
                foreach ($profiles->profiles($faq) as $profile) {
                    $totals = ['current' => 0, 'missing' => 0, 'stale' => 0, 'absent_ineligible' => 0];
                    $vectors = new QdrantClient($profile['collection'], ! $faq);
                    if ($faq) {
                        ChatbotKnowledge::query()->chunkById(20, function (Collection $rows) use ($faqs, $profile, $vectors, &$totals): void {
                            $expected = [];
                            foreach ($rows as $record) {
                                $documents = $faqs->documents($record);
                                foreach (['en', 'ceb', 'tl', 'hil'] as $index => $language) {
                                    $expected[$record->id * 10 + $index] = isset($documents[$language]) ? ['faq_id' => $record->id, 'language' => $language, 'source_hash' => $faqs->hash($record)] : null;
                                }
                            }
                            $this->check($vectors, $expected, $profile, $totals);
                        });
                    } else {
                        $eligibleProducts = Product::query()->customerEligible();
                        $totals['eligible_products'] = (clone $eligibleProducts)->count();
                        $totals['missing_projections'] = $eligibleProducts
                            ->whereNotIn('id', CatalogProjection::query()->select('product_id'))->count();
                        CatalogProjection::query()->select(['id', 'product_id', 'revision'])->chunkById(100, function (Collection $rows) use ($catalog, $profile, $vectors, &$totals): void {
                            $products = Product::query()->customerEligible()->whereIn('id', $rows->pluck('product_id'))->with(['category', 'tags', 'componentProfile.currentApprovedRevision'])->get()->keyBy('id');
                            $expected = [];
                            foreach ($rows as $projection) {
                                $product = $products->get($projection->product_id);
                                $expected[(int) $projection->product_id] = $product ? ['product_id' => $product->id, 'revision' => (int) $projection->revision, 'source_hash' => $catalog->build($product, $profile['model'])['hash']] : null;
                            }
                            $this->check($vectors, $expected, $profile, $totals);
                        });
                    }
                    $results[] = ['kind' => $faq ? 'faq' : 'catalog', ...$profile, ...$totals];
                }
            }
        } catch (Throwable) {
            $this->error('Fallback index audit unavailable; no data was changed.');

            return self::FAILURE;
        }
        $ready = $results !== [] && array_all($results, fn (array $row): bool => $row['missing'] === 0 && $row['stale'] === 0 && ($row['missing_projections'] ?? 0) === 0);
        $this->line(json_encode(['payloads_current' => $ready, 'semantic_quality_verified' => false, 'profiles' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int,array<string,mixed>|null> $expected
     * @param array{model:string,collection:string} $profile
     * @param array<string,int> $totals */
    private function check(QdrantClient $vectors, array $expected, array $profile, array &$totals): void
    {
        $payloads = $vectors->payloads(array_keys($expected));
        foreach ($expected as $id => $fields) {
            $payload = $payloads[$id] ?? null;
            if ($fields === null) {
                $totals[$payload === null ? 'absent_ineligible' : 'stale']++;
            } elseif ($payload === null) {
                $totals['missing']++;
            } else {
                $fields = [...$fields, 'embedding_model' => $profile['model'], 'embedding_dimensions' => (int) config('ai_integration.embedding.dimensions')];
                $matches = true;
                foreach ($fields as $key => $value) {
                    $matches = $matches && ($payload[$key] ?? null) === $value;
                }
                $totals[$matches ? 'current' : 'stale']++;
            }
        }
    }
}
