<?php

namespace App\Services\Chatbot\Retrieval;

class EmbeddingBudgetExhausted extends \RuntimeException
{
    public function __construct(public int $retryAfter)
    {
        parent::__construct('Embedding budget exhausted.');
    }
}
