<?php

namespace App\Services\Chatbot;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class ChatbotProviderBudget
{
    private int $retryAfter = 60;

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    private int $calls = 0;

    private int $prepaidCalls = 0;

    private ?float $retrievalStarted = null;

    /** @var array<string, mixed> */
    private array $memo = [];

    /** @var list<array{lock: string, minute: string, day: string, tokens: int}> */
    private array $reservations = [];

    private ?float $started = null;

    private ?Lock $lease = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    public function reset(): void
    {
        $this->releaseTurn();
        $this->calls = 0;
        $this->prepaidCalls = 0;
        $this->retrievalStarted = null;
        $this->memo = [];
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->started = microtime(true);
    }

    public function recordUsage(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += max(0, $inputTokens);
        $this->outputTokens += max(0, $outputTokens);
        $reservation = array_shift($this->reservations);
        if ($reservation !== null && $inputTokens + $outputTokens > 0) {
            $this->adjustReservation($reservation, $inputTokens + $outputTokens - $reservation['tokens'], false);
        }
    }

    /** @return array{calls: int, input_tokens: int, output_tokens: int} */
    public function usage(): array
    {
        return ['calls' => $this->calls, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }

    public function reserve(string $input): bool
    {
        $this->started ??= microtime(true);
        if ($this->remainingSeconds() === 0) {
            return false;
        }
        if ($this->prepaidCalls > 0) {
            if (strlen($input) > (int) config('ai_integration.gemini.max_input_bytes', 12000)) {
                return false;
            }
            $this->prepaidCalls--;
            $this->calls++;

            return true;
        }
        try {
            if (Cache::has($this->circuitKey().':open')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        if ($this->calls >= max(1, min(3, (int) config('ai_integration.gemini.max_calls', 1)))
            || microtime(true) - $this->started >= (config('ai_integration.full_catalog_pipeline') ? 25 : (int) config('ai_integration.gemini.deadline_seconds', 15))) {
            return false;
        }
        if (! $this->acquireTurn()) {
            return false;
        }
        if (! $this->reserveProject($input)) {
            $this->releaseTurn();

            return false;
        }
        $this->calls++;

        return true;
    }

    public function reserveGrounded(string $input): bool
    {
        $this->started ??= microtime(true);
        try {
            if (Cache::has($this->circuitKey().':open')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        if ($this->calls + 2 > max(1, min(3, (int) config('ai_integration.gemini.max_calls', 3)))
            || $this->remainingSeconds() <= (config('ai_integration.full_catalog_pipeline') ? 5 : (int) config('ai_integration.gemini.validation_timeout_seconds', 5))
            || ! $this->acquireTurn()) {
            return false;
        }
        if (! $this->reserveProject($input, requests: 2)) {
            $this->releaseTurn();

            return false;
        }
        $this->calls++;
        $this->prepaidCalls++;

        return true;
    }

    private function acquireTurn(): bool
    {
        if ($this->lease !== null) {
            return true;
        }
        try {
            $slots = max(1, min(10, (int) config('ai_integration.gemini.max_concurrent_turns', 2)));
            for ($slot = 0; $slot < $slots; $slot++) {
                $lease = Cache::lock($this->circuitKey().':slot:'.$slot, max(5, (int) config('ai_integration.gemini.deadline_seconds', 60)) + 5);
                if ($lease->get()) {
                    $this->lease = $lease;

                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    public function releaseTurn(): void
    {
        while ($this->prepaidCalls > 0) {
            $reservation = array_pop($this->reservations);
            if ($reservation !== null) {
                $this->adjustReservation($reservation, -$reservation['tokens'], true);
            }
            $this->prepaidCalls--;
        }
        $this->reservations = [];
        try {
            $this->lease?->release();
        } catch (\Throwable) {
        }
        $this->lease = null;
    }

    /** @param array{lock: string, minute: string, day: string, tokens: int} $reservation */
    private function adjustReservation(array $reservation, int $tokens, bool $unused): void
    {
        try {
            Cache::lock($reservation['lock'], 3)->get(function () use ($reservation, $tokens, $unused): void {
                $key = $reservation['minute'].':tokens';
                if (Cache::has($key)) {
                    Cache::put($key, max(0, (int) Cache::get($key) + $tokens), 120);
                }
                if ($unused) {
                    foreach ([$reservation['minute'], $reservation['day']] as $period) {
                        $key = $period.':requests';
                        if (Cache::has($key)) {
                            Cache::put($key, max(0, (int) Cache::get($key) - 1), $period === $reservation['day'] ? 172800 : 120);
                        }
                    }
                }
            });
        } catch (\Throwable) {
        }
    }

    public function recordFailure(): void
    {
        try {
            $key = $this->circuitKey();
            Cache::add($key.':failures', 0, 60);
            if (Cache::increment($key.':failures') >= 3) {
                Cache::put($key.':open', true, 60);
            }
        } catch (\Throwable) {
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget($this->circuitKey().':failures');
        } catch (\Throwable) {
        }
    }

    private function circuitKey(): string
    {
        return 'chatbot:provider:circuit:'.hash('sha256', (string) config('ai_integration.gemini.project_budget_key', 'battlefront').'|'.config('ai_integration.gemini.model'));
    }

    public function remainingSeconds(): int
    {
        return max(0, (int) floor((config('ai_integration.full_catalog_pipeline') ? 25 : (int) config('ai_integration.gemini.deadline_seconds', 15)) - (microtime(true) - ($this->started ?? microtime(true)))));
    }

    public function retrievalTimeout(): int
    {
        if ($this->started === null) {
            return 5;
        }
        $this->retrievalStarted ??= microtime(true);

        return min($this->remainingSeconds(), max(0, (int) floor((config('ai_integration.full_catalog_pipeline') ? 6 : 5) - (microtime(true) - $this->retrievalStarted))));
    }

    public function memoize(string $key, \Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $resolve();
        }

        return $this->memo[$key];
    }

    public function recordStage(string $stage, string $status, int $durationMs = 0): void
    {
        if (! in_array($stage, ['scope', 'generation', 'validation', 'qdrant', 'neo4j'], true) || ! in_array($status, ['completed', 'failed', 'cache_hit', 'cache_miss', 'disabled', 'unsafe_query', 'empty_query', 'circuit_open', 'no_current_seeds'], true)) {
            return;
        }
        try {
            $prefix = 'chatbot:stage:'.now()->format('Ymd').':'.$stage.':';
            foreach ([$status => 1, 'duration_ms' => max(0, $durationMs)] as $metric => $value) {
                Cache::add($prefix.$metric, 0, 172800);
                Cache::increment($prefix.$metric, $value);
            }
        } catch (\Throwable) {
        }
    }

    public function recordTurn(int $durationMs, string $source): void
    {
        try {
            $prefix = 'chatbot:metrics:'.now()->format('Ymd').':';
            $bucket = match (true) {
                $durationMs <= 1000 => 'le_1000',
                $durationMs <= 5000 => 'le_5000',
                $durationMs <= 15000 => 'le_15000',
                $durationMs <= 25000 => 'le_25000',
                default => 'over_25000',
            };
            foreach (['turns', $bucket, in_array($source, ['gemini', 'fallback'], true) ? $source : 'failed'] as $metric) {
                Cache::add($prefix.$metric, 0, 172800);
                Cache::increment($prefix.$metric);
            }
        } catch (\Throwable) {
        }
    }

    public function reserveEmbedding(string $input): bool
    {
        if ($this->started !== null && $this->remainingSeconds() === 0) {
            return false;
        }

        return $this->reserveProject($input, true);
    }

    private function reserveProject(string $input, bool $embedding = false, int $requests = 1): bool
    {
        $this->retryAfter = 60;
        if (strlen($input) > (int) config('ai_integration.gemini.max_input_bytes', 12000)) {
            return false;
        }
        $legacyPrefix = 'chatbot:quota:'.hash('sha256', (string) config('ai_integration.gemini.project_budget_key', 'battlefront'));
        $area = $embedding ? 'embedding' : 'gemini';
        $prefix = $legacyPrefix.':model:'.hash('sha256', (string) config('ai_integration.'.$area.'.model'));
        try {
            return Cache::lock($legacyPrefix.':lock', 3)->get(function () use ($prefix, $legacyPrefix, $area, $embedding, $input, $requests): bool {
                $minute = $prefix.':'.now()->format('YmdHi');
                $day = $prefix.':'.now()->setTimezone('America/Los_Angeles')->format('Ymd');
                $legacyDay = $legacyPrefix.':'.now()->setTimezone('America/Los_Angeles')->format('Ymd');
                $legacyMinute = $legacyPrefix.':'.now()->format('YmdHi');
                Cache::add($day.':requests', (int) Cache::get($legacyDay.':requests', 0), 172800);
                Cache::add($minute.':requests', (int) Cache::get($legacyMinute.':requests', 0), 120);
                Cache::add($minute.':tokens', (int) Cache::get($legacyMinute.':tokens', 0), 120);
                $usedRequests = (int) Cache::get($minute.':requests', 0);
                $daily = (int) Cache::get($day.':requests', 0);
                $tokens = (int) Cache::get($minute.':tokens', 0);
                $reservation = $embedding ? strlen($input) + 512 : strlen($input) + 2048 + (int) config('ai_integration.gemini.max_output_tokens', 512);
                $reservation *= $requests;
                if ($daily + $requests > (int) config('ai_integration.'.$area.'.requests_per_day')) {
                    $pacific = now()->setTimezone('America/Los_Angeles');
                    $this->retryAfter = max(60, (int) ceil($pacific->diffInSeconds($pacific->copy()->addDay()->startOfDay())) + 5);

                    return false;
                }
                if ($usedRequests + $requests > (int) config('ai_integration.'.$area.'.requests_per_minute')
                    || $tokens + $reservation > (int) config('ai_integration.'.$area.'.tokens_per_minute')) {
                    return false;
                }
                Cache::put($minute.':requests', $usedRequests + $requests, 120);
                Cache::put($minute.':tokens', $tokens + $reservation, 120);
                Cache::put($day.':requests', $daily + $requests, 172800);
                if (! $embedding) {
                    for ($index = 0; $index < $requests; $index++) {
                        $this->reservations[] = ['lock' => $legacyPrefix.':lock', 'minute' => $minute, 'day' => $day, 'tokens' => intdiv($reservation, $requests)];
                    }
                }

                return true;
            }) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
