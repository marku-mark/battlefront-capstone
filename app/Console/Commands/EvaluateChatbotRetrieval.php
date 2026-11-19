<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use JsonException;

#[Signature('chatbot:evaluate-retrieval {--dataset= : Labeled public product candidate snapshots as JSON}')]
#[Description('Compare retrieval snapshots and RRF offline without contacting providers')]
class EvaluateChatbotRetrieval extends Command
{
    public function handle(): int
    {
        $path = $this->option('dataset');
        if (! is_string($path) || ! is_file($path) || filesize($path) > 2_000_000) {
            $this->error('Provide a retrieval dataset file smaller than 2 MB.');

            return self::FAILURE;
        }
        try {
            $contents = file_get_contents($path);
            $data = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        $validator = Validator::make(['dataset' => $data], [
            'dataset' => ['required', 'array:catalog_version,cases'],
            'dataset.catalog_version' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'dataset.cases' => ['required', 'array', 'min:1', 'max:1000'],
            'dataset.cases.*' => ['array:id,relevant_product_ids,algolia,qdrant,expected_no_match'],
            'dataset.cases.*.expected_no_match' => ['sometimes', 'boolean'],
            'dataset.cases.*.id' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'dataset.cases.*.relevant_product_ids' => ['present', 'array', 'max:100'],
            'dataset.cases.*.relevant_product_ids.*' => ['required', 'integer:strict', 'min:1'],
            'dataset.cases.*.algolia' => ['present', 'array', 'max:100'],
            'dataset.cases.*.algolia.*' => ['required', 'integer:strict', 'min:1'],
            'dataset.cases.*.qdrant' => ['present', 'array', 'max:100'],
            'dataset.cases.*.qdrant.*' => ['required', 'integer:strict', 'min:1'],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid retrieval dataset. Supply catalog version, case IDs, relevant IDs and bounded candidate lists only.');

            return self::FAILURE;
        }
        foreach ($data['cases'] as $case) {
            if (($case['relevant_product_ids'] === []) !== ($case['expected_no_match'] ?? false)) {
                $this->error('Empty relevance labels require expected_no_match=true; positive cases must contain relevant IDs.');

                return self::FAILURE;
            }
            foreach (['relevant_product_ids', 'algolia', 'qdrant'] as $field) {
                if (! array_is_list($case[$field]) || count(array_unique($case[$field])) !== count($case[$field])) {
                    $this->error('Retrieval IDs must be unique ordered lists.');

                    return self::FAILURE;
                }
            }
        }
        $positiveCases = count(array_filter($data['cases'], fn (array $case): bool => $case['relevant_product_ids'] !== []));
        $negativeCases = count($data['cases']) - $positiveCases;
        $totals = array_fill_keys(['algolia', 'qdrant', 'rrf'], ['recall_at_10' => 0.0, 'mrr_at_10' => 0.0, 'precision_at_10' => 0.0, 'precision_among_returned' => 0.0, 'zero_result_cases' => 0, 'false_positive_cases' => 0]);
        $results = [];
        foreach ($data['cases'] as $case) {
            $scores = [];
            foreach (['algolia', 'qdrant'] as $source) {
                foreach ($case[$source] as $index => $id) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (60 + $index + 1);
                }
            }
            uksort($scores, fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
            $rankings = ['algolia' => $case['algolia'], 'qdrant' => $case['qdrant'], 'rrf' => array_keys($scores)];
            $metrics = [];
            foreach ($rankings as $source => $ids) {
                $top = array_slice($ids, 0, 10);
                $relevant = count(array_intersect($case['relevant_product_ids'], $top));
                $recall = $case['relevant_product_ids'] !== [] ? $relevant / count($case['relevant_product_ids']) : null;
                $reciprocal = 0.0;
                foreach ($top as $index => $id) {
                    if (in_array($id, $case['relevant_product_ids'], true)) {
                        $reciprocal = 1 / ($index + 1);
                        break;
                    }
                }
                $precision = $recall !== null ? $relevant / 10 : null;
                $precisionAmongReturned = $recall !== null ? $relevant / max(1, count($top)) : null;
                $metrics[$source] = ['recall_at_10' => $recall, 'mrr_at_10' => $reciprocal, 'precision_at_10' => $precision, 'precision_among_returned' => $precisionAmongReturned, 'top_ids' => $top];
                $totals[$source]['recall_at_10'] += $recall;
                $totals[$source]['mrr_at_10'] += $reciprocal;
                $totals[$source]['precision_at_10'] += $precision;
                $totals[$source]['precision_among_returned'] += $precisionAmongReturned;
                $totals[$source]['zero_result_cases'] += (int) ($ids === []);
                $totals[$source]['false_positive_cases'] += (int) ($case['relevant_product_ids'] === [] && $ids !== []);
            }
            $results[] = ['id' => $case['id'], 'metrics' => $metrics];
        }
        foreach ($totals as &$metrics) {
            foreach (['recall_at_10', 'mrr_at_10', 'precision_at_10', 'precision_among_returned'] as $metric) {
                $metrics[$metric] = $positiveCases > 0 ? $metrics[$metric] / $positiveCases : null;
            }
            $metrics['false_positive_rate'] = $negativeCases > 0 ? $metrics['false_positive_cases'] / $negativeCases : null;
            $metrics['zero_result_rate'] = $metrics['zero_result_cases'] / count($results);
        }
        unset($metrics);
        $depthSummary = [];
        foreach ([10, 20, 40] as $depth) {
            $depthSummary[$depth] = $this->depthMetrics($data['cases'], $depth);
        }
        $this->line(json_encode([
            'mode' => 'offline_snapshot', 'metrics_version' => 2, 'catalog_version' => $data['catalog_version'],
            'precision_denominators' => ['precision_at_10' => 10, 'precision_among_returned' => 'max(1, returned in top 10)'],
            'dataset_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            'cases' => count($results), 'rrf_k' => 60, 'summary' => $totals, 'results' => $results,
            'positive_cases' => $positiveCases, 'negative_cases' => $negativeCases,
            'candidate_depths' => $depthSummary,
            'live_quality_verified' => false,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $cases
     * @return array<string, array<string, float|int|null>> */
    private function depthMetrics(array $cases, int $depth): array
    {
        $positiveCases = count(array_filter($cases, fn (array $case): bool => $case['relevant_product_ids'] !== []));
        $negativeCases = count($cases) - $positiveCases;
        $totals = array_fill_keys(['algolia', 'qdrant', 'rrf'], ['recall_at_10' => 0.0, 'mrr_at_10' => 0.0, 'zero_result_rate' => 0.0, 'false_positive_rate' => 0.0]);
        foreach ($cases as $case) {
            $rankings = [];
            $scores = [];
            foreach (['algolia', 'qdrant'] as $source) {
                $rankings[$source] = array_slice($case[$source], 0, $depth);
                foreach ($rankings[$source] as $rank => $id) {
                    $scores[$id] = ($scores[$id] ?? 0) + 1 / (61 + $rank);
                }
            }
            uksort($scores, fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
            $rankings['rrf'] = array_keys($scores);
            foreach ($rankings as $source => $ids) {
                $top = array_slice($ids, 0, 10);
                $totals[$source]['recall_at_10'] += $case['relevant_product_ids'] !== [] ? count(array_intersect($case['relevant_product_ids'], $top)) / count($case['relevant_product_ids']) : 0;
                $totals[$source]['zero_result_rate'] += (int) ($ids === []);
                $totals[$source]['false_positive_rate'] += (int) ($case['relevant_product_ids'] === [] && $ids !== []);
                foreach ($top as $rank => $id) {
                    if (in_array($id, $case['relevant_product_ids'], true)) {
                        $totals[$source]['mrr_at_10'] += 1 / ($rank + 1);
                        break;
                    }
                }
            }
        }
        foreach ($totals as &$metrics) {
            foreach ($metrics as $metric => &$value) {
                $denominator = match ($metric) {
                    'zero_result_rate' => count($cases), 'false_positive_rate' => $negativeCases, default => $positiveCases,
                };
                $value = $denominator > 0 ? $value / $denominator : null;
            }
            unset($value);
        }
        unset($metrics);

        return $totals;
    }
}
