<?php

namespace App\Services\Chatbot\Retrieval;

class RetrievalFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct('Retrieval unavailable.');
    }
}
