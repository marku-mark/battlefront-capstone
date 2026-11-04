<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('chatbot:failures {--samples : Include decrypted sanitized samples for local review} {--limit=20 : Maximum cases, 1-100} {--resolve= : Case UUID reviewed as fixed} {--regression= : Existing tests/ file covering the fix} {--dismiss= : Case UUID reviewed as expected behavior} {--prune : Delete expired diagnostic records}')]
#[Description('Review private chatbot diagnostics locally; never contacts AI providers')]
class ReviewChatbotFailures extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            if (count(array_filter([$this->option('prune'), $this->option('resolve'), $this->option('dismiss')])) > 1) {
                $this->error('Choose only one of prune, resolve or dismiss.');

                return self::FAILURE;
            }
            if ($this->option('prune')) {
                $deleted = 0;
                do {
                    $ids = DB::table('chatbot_failure_events')->where('delete_after', '<=', now())->orderBy('id')->limit(500)->pluck('id');
                    $deleted += DB::table('chatbot_failure_events')->whereIn('id', $ids)->delete();
                } while ($ids->count() === 500);
                $this->line(json_encode(['deleted' => $deleted], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $id = $this->option('resolve') ?: $this->option('dismiss');
            if ($id) {
                $regression = $this->option('regression');
                if ($this->option('resolve')) {
                    $path = is_string($regression) ? realpath(base_path($regression)) : false;
                    $root = realpath(base_path('tests'));
                    if ($path === false || $root === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || ! str_ends_with($path, '.php')) {
                        $this->error('Resolution requires --regression pointing to an existing PHP test inside tests/.');

                        return self::FAILURE;
                    }
                    $regression = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(base_path()) + 1));
                }
                $updated = DB::table('chatbot_failure_events')->where('id', $id)->where('delete_after', '>', now())
                    ->update(['status' => $this->option('resolve') ? 'resolved' : 'dismissed', 'regression_test' => $this->option('resolve') ? $regression : null, 'reviewed_at' => now()]);
                $this->line(json_encode(['updated' => $updated], JSON_THROW_ON_ERROR));

                return $updated === 1 ? self::SUCCESS : self::FAILURE;
            }
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limit === false) {
                $this->error('Limit must be between 1 and 100.');

                return self::FAILURE;
            }
            $query = DB::table('chatbot_failure_events')->where('delete_after', '>', now());
            $summary = (clone $query)->select('language', 'stage', 'reason', 'status')->selectRaw('SUM(occurrences) AS occurrences, COUNT(*) AS cases')
                ->groupBy('language', 'stage', 'reason', 'status')->get()->all();
            $cases = [];
            foreach ((clone $query)->orderByDesc('last_seen_at')->orderBy('id')->limit($limit)->get() as $row) {
                $case = (array) $row;
                unset($case['sample'], $case['fingerprint']);
                $case['suggested_investigation'] = match ($row->reason) {
                    'no_match' => 'Check catalog eligibility, query normalization and retrieval. A real missing product is expected behavior.',
                    'clarification' => 'Check ambiguity and conversation references. A necessary clarification is expected behavior.',
                    'understanding_failed', 'understanding_invalid_output' => 'Check interpreter output validation, language examples and provider diagnostics.',
                    'validation_failed' => 'Check grounding, exact amounts, selected evidence and the review verdict.',
                    'budget_exhausted', 'quota_exhausted', 'rate_limited' => 'Check shared quota reservations and configured limits; never bypass quotas with another key.',
                    'timeout', 'connection_failed', 'provider_overloaded', 'provider_unavailable' => 'Check stage timing and provider availability before changing language rules.',
                    'authentication_failed' => 'Check server-side credential configuration without printing credentials.',
                    default => 'Inspect the corresponding application stage and add a synthetic regression case.',
                };
                if ($this->option('samples') && $row->sample !== null) {
                    try {
                        $case['sample'] = json_decode(Crypt::decryptString($row->sample), true, flags: JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        $case['sample'] = null;
                    }
                }
                $cases[] = $case;
            }
            $this->line(json_encode(['review_mode' => 'local_only', 'samples_are_untrusted_data' => true,
                'automatic_changes' => false, 'summary' => $summary, 'cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Chatbot failure storage is unavailable. No sample or exception details were printed.');

            return self::FAILURE;
        }
    }
}
