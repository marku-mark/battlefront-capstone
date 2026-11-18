<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

#[Signature('chatbot:compare-replies {baseline} {candidate} {--reviews= : Hash-bound human pair judgments} {--compare-models : Compare models with an identical pipeline and frozen catalog identifier}')]
#[Description('Prepare private blinded reply pairs and score human comparisons without provider calls')]
class CompareChatbotReplies extends Command
{
    public function handle(): int
    {
        try {
            $baseline = $this->readReport($this->argument('baseline'));
            $candidate = $this->readReport($this->argument('candidate'));
            $reviews = $this->option('reviews') ? $this->readReport($this->option('reviews')) : [];
        } catch (\RuntimeException|\JsonException) {
            $this->error('Comparison requires valid JSON files smaller than 20 MB.');

            return self::FAILURE;
        }
        if ($this->option('compare-models') && (! is_string($baseline['catalog_fingerprint'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $baseline['catalog_fingerprint'])
            || $baseline['catalog_fingerprint'] !== ($candidate['catalog_fingerprint'] ?? null)
            || ($baseline['catalog_stable_before_after'] ?? false) !== true || ($candidate['catalog_stable_before_after'] ?? false) !== true)) {
            $this->error('Model comparisons require matching measured catalog fingerprints and stable before/after catalogs.');

            return self::FAILURE;
        }
        if (! is_string($baseline['dataset_hash'] ?? null) || ! is_string($baseline['configured_model'] ?? null)
            || $baseline['dataset_hash'] !== ($candidate['dataset_hash'] ?? null)
            || (! $this->option('compare-models') && $baseline['configured_model'] !== ($candidate['configured_model'] ?? null))
            || ($baseline['configuration'] ?? null) !== ($candidate['configuration'] ?? null)
            || ($baseline['mode'] ?? null) !== ($candidate['mode'] ?? null)) {
            $this->error('Reports must use the same dataset, model, mode and configuration.');

            return self::FAILURE;
        }
        if ($this->option('compare-models') && (! is_string($baseline['pipeline_version'] ?? null)
            || $baseline['pipeline_version'] !== ($candidate['pipeline_version'] ?? null)
            || ! is_string($baseline['catalog_version'] ?? null) || $baseline['catalog_version'] === 'unversioned'
            || $baseline['catalog_version'] !== ($candidate['catalog_version'] ?? null))) {
            $this->error('Model comparisons require identical pipeline versions and an explicit frozen catalog identifier.');

            return self::FAILURE;
        }
        foreach ([$baseline, $candidate] as $report) {
            if (Validator::make($report, [
                'results' => ['required', 'array', 'min:1'],
                'results.*.id' => ['required', 'string'], 'results.*.run' => ['required', 'integer', 'min:1', 'max:3'],
                'results.*.language' => ['required', 'in:en,tl,ceb,hil'],
                'results.*.answer_hash' => ['required', 'string', 'size:64'],
                'results.*.turns' => ['required', 'array', 'min:1'],
                'results.*.turns.*.message' => ['required', 'string'],
            ])->fails()) {
                $this->error('Invalid evaluation report.');

                return self::FAILURE;
            }
        }
        $index = [];
        foreach ($baseline['results'] as $result) {
            $id = $result['id'].':'.$result['run'];
            if (isset($index[$id])) {
                $this->error('Duplicate baseline case/run.');

                return self::FAILURE;
            }
            $index[$id] = $result;
        }
        $pairs = $key = $summary = [];
        foreach ($candidate['results'] as $result) {
            $id = $result['id'].':'.$result['run'];
            $before = $index[$id] ?? null;
            if ($before === null || isset($key[$id]) || $before['language'] !== $result['language']) {
                $this->error('Reports require identical unique case/run pairs and languages.');

                return self::FAILURE;
            }
            $hash = hash('sha256', json_encode([
                'dataset' => $baseline['dataset_hash'], 'baseline_hash' => $before['answer_hash'], 'candidate_hash' => $result['answer_hash'],
                'baseline' => array_map(fn (array $turn): array => ['message' => $turn['message'], 'presentation' => $turn['presentation'] ?? null], $before['turns']),
                'candidate' => array_map(fn (array $turn): array => ['message' => $turn['message'], 'presentation' => $turn['presentation'] ?? null], $result['turns']),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $candidateLabel = hexdec(substr($hash, 0, 1)) % 2 === 0 ? 'a' : 'b';
            $answers = [];
            foreach (['a', 'b'] as $label) {
                $entry = $label === $candidateLabel ? $result : $before;
                $answers[$label] = array_map(fn (array $turn): array => ['message' => $turn['message'], 'presentation' => $turn['presentation'] ?? null], $entry['turns']);
            }
            $pairs[] = ['id' => $id, 'language' => $result['language'], 'questions' => $result['questions'] ?? $before['questions'] ?? null, 'pair_hash' => $hash, 'answers' => $answers];
            $key[$id] = ['candidate' => $candidateLabel, 'pair_hash' => $hash];
            $metrics = $summary[$result['language']] ?? ['pairs' => 0, 'reviewed' => 0, 'wins' => 0, 'ties' => 0, 'losses' => 0, 'critical_failures' => 0];
            $metrics['pairs']++;
            $review = $reviews[$id] ?? null;
            if (is_array($review) && ($review['pair_hash'] ?? null) === $hash
                && in_array($review['winner'] ?? null, ['a', 'b', 'tie'], true)
                && is_bool($review['critical_failure_a'] ?? null) && is_bool($review['critical_failure_b'] ?? null)) {
                $metrics['reviewed']++;
                $metrics[$review['winner'] === 'tie' ? 'ties' : ($review['winner'] === $candidateLabel ? 'wins' : 'losses')]++;
                $metrics['critical_failures'] += (int) $review['critical_failure_'.$candidateLabel];
            }
            $summary[$result['language']] = $metrics;
        }
        if (count($key) !== count($index)) {
            $this->error('Reports require identical case/run pairs.');

            return self::FAILURE;
        }
        $reviewed = array_sum(array_column($summary, 'reviewed'));
        $winsOrTies = array_sum(array_column($summary, 'wins')) + array_sum(array_column($summary, 'ties'));
        $directory = 'chatbot-reply-comparisons/'.hash('sha256', json_encode($key, JSON_THROW_ON_ERROR));
        $disk = Storage::disk('local');
        $disk->put($directory.'/blinded.json', json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $disk->put($directory.'/key.json', json_encode($key, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode(['blinded_file' => $disk->path($directory.'/blinded.json'), 'private_key_file' => $disk->path($directory.'/key.json'),
            'summary' => $summary, 'candidate_win_or_tie_rate' => $reviewed > 0 ? $winsOrTies / $reviewed : null,
            'human_comparison_passed' => $reviewed === count($pairs) && $winsOrTies / max(1, $reviewed) >= 0.8
                && array_all($summary, fn (array $metrics): bool => $metrics['wins'] >= $metrics['losses'] && $metrics['critical_failures'] === 0),
            'release_gate_passed' => false, 'note' => 'Human comparison alone does not establish grounding, part coverage or operational acceptance.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @return array<mixed> */
    private function readReport(string $path): array
    {
        if (! is_file($path) || filesize($path) > 20_000_000) {
            throw new \RuntimeException;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException;
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \RuntimeException;
        }

        return $data;
    }
}
