<?php

namespace App\Services\Chatbot;

use Illuminate\Container\Attributes\Scoped;

#[Scoped]
class ChatbotFailureTrace
{
    public string $scope = 'unknown';

    public string $path = 'legacy';

    public bool $publicScopeConfirmed = false;

    /** @var array{reason: string, stage: string}|null */
    public ?array $event = null;

    public function reset(): void
    {
        $this->scope = 'unknown';
        $this->path = 'legacy';
        $this->publicScopeConfirmed = false;
        $this->event = null;
    }

    public function record(string $reason, string $stage): void
    {
        if (! in_array($reason, ['understanding_failed', 'understanding_invalid_output', 'budget_exhausted', 'timeout', 'provider_unavailable',
            'rate_limited', 'quota_exhausted', 'authentication_failed', 'connection_failed', 'provider_overloaded',
            'empty_response', 'validation_failed', 'no_match', 'clarification', 'application_error'], true)
            || ! in_array($stage, ['scope', 'retrieval', 'generation', 'validation', 'application'], true)) {
            return;
        }
        $this->event = ['reason' => $reason, 'stage' => $stage];
    }
}
