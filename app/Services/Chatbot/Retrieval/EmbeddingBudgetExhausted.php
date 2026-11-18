<?php

namespace App\Services\Chatbot\Retrieval;

class EmbeddingBudgetExhausted extends \RuntimeException
{
    public function __construct(public int $retryAfter, public string $reason = 'local_limit')
    {
        parent::__construct('Embedding budget exhausted.');
    }
}
