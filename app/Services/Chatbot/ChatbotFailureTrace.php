<?php

namespace App\Services\Chatbot;

use Illuminate\Container\Attributes\Scoped;

#[Scoped]
class ChatbotFailureTrace
{
    public string $scope = 'unknown';

    public string $path = 'legacy';

    public bool $publicScopeConfirmed = false;

    public ?string $reviewReason = null;

    public ?string $draftReason = null;

    /** @var list<array<string, mixed>> */
    public array $retrieval = [];

    /** @var array<string, mixed>|null */
    public ?array $understanding = null;

    /** @var list<array{role: string, model: string, output_tokens: int, output_limit: int|null, limit_reached: bool}> */
    public array $providerResponses = [];

    /** @var list<array{stage: string, status: string, duration_ms: int}> */
    public array $stages = [];

    /** @var list<array{provider: string, reason: string, http_status: int|null}> */
    public array $retrievalFailures = [];

    /** @var array{reason: string, stage: string}|null */
    public ?array $event = null;

    public function reset(): void
    {
        $this->scope = 'unknown';
        $this->path = 'legacy';
        $this->publicScopeConfirmed = false;
        $this->event = null;
        $this->reviewReason = null;
        $this->draftReason = null;
        $this->retrieval = [];
        $this->understanding = null;
        $this->providerResponses = [];
        $this->stages = [];
        $this->retrievalFailures = [];
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
