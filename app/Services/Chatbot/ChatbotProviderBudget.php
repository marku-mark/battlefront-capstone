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

    private ?float $started = null;

    private ?Lock $lease = null;

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    public function reset(): void
    {
        $this->releaseTurn();
        $this->calls = 0;
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->started = microtime(true);
    }

    public function recordUsage(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += max(0, $inputTokens);
        $this->outputTokens += max(0, $outputTokens);
    }

    /** @return array{calls: int, input_tokens: int, output_tokens: int} */
    public function usage(): array
    {
        return ['calls' => $this->calls, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }

    public function reserve(string $input): bool
    {
        $this->started ??= microtime(true);
        try {
            if (Cache::has($this->circuitKey().':open')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        if ($this->calls >= max(1, min(3, (int) config('ai_integration.gemini.max_calls', 1)))
            || microtime(true) - $this->started >= (int) config('ai_integration.gemini.deadline_seconds', 25)) {
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
        try {
            $this->lease?->release();
        } catch (\Throwable) {
        }
        $this->lease = null;
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
        return max(1, (int) floor((int) config('ai_integration.gemini.deadline_seconds', 25) - (microtime(true) - ($this->started ?? microtime(true)))));
    }

    public function reserveEmbedding(string $input): bool
    {
        return $this->reserveProject($input, true);
    }

    private function reserveProject(string $input, bool $embedding = false): bool
    {
        $this->retryAfter = 60;
        if (strlen($input) > (int) config('ai_integration.gemini.max_input_bytes', 12000)) {
            return false;
        }
        $legacyPrefix = 'chatbot:quota:'.hash('sha256', (string) config('ai_integration.gemini.project_budget_key', 'battlefront'));
        $area = $embedding ? 'embedding' : 'gemini';
        $prefix = $legacyPrefix.':model:'.hash('sha256', (string) config('ai_integration.'.$area.'.model'));
        try {
            return Cache::lock($legacyPrefix.':lock', 3)->get(function () use ($prefix, $legacyPrefix, $area, $embedding, $input): bool {
                $minute = $prefix.':'.now()->format('YmdHi');
                $day = $prefix.':'.now()->setTimezone('America/Los_Angeles')->format('Ymd');
                $legacyDay = $legacyPrefix.':'.now()->setTimezone('America/Los_Angeles')->format('Ymd');
                $legacyMinute = $legacyPrefix.':'.now()->format('YmdHi');
                Cache::add($day.':requests', (int) Cache::get($legacyDay.':requests', 0), 172800);
                Cache::add($minute.':requests', (int) Cache::get($legacyMinute.':requests', 0), 120);
                Cache::add($minute.':tokens', (int) Cache::get($legacyMinute.':tokens', 0), 120);
                $requests = (int) Cache::get($minute.':requests', 0);
                $daily = (int) Cache::get($day.':requests', 0);
                $tokens = (int) Cache::get($minute.':tokens', 0);
                $reservation = $embedding ? strlen($input) + 512 : strlen($input) + 2048 + (int) config('ai_integration.gemini.max_output_tokens', 512);
                if ($daily >= (int) config('ai_integration.'.$area.'.requests_per_day')) {
                    $pacific = now()->setTimezone('America/Los_Angeles');
                    $this->retryAfter = max(60, (int) ceil($pacific->diffInSeconds($pacific->copy()->addDay()->startOfDay())) + 5);

                    return false;
                }
                if ($requests >= (int) config('ai_integration.'.$area.'.requests_per_minute')
                    || $tokens + $reservation > (int) config('ai_integration.'.$area.'.tokens_per_minute')) {
                    return false;
                }
                Cache::put($minute.':requests', $requests + 1, 120);
                Cache::put($minute.':tokens', $tokens + $reservation, 120);
                Cache::put($day.':requests', $daily + 1, 172800);

                return true;
            }) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
