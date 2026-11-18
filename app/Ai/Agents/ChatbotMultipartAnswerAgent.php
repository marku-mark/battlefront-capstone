<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;

#[MaxTokens(1024)]
class ChatbotMultipartAnswerAgent extends ChatbotEvidenceAgent
{
    public function instructions(): string
    {
        return parent::instructions()."\n\n".<<<'INSTRUCTIONS'
            MULTIPART OUTPUT
            Return answers for EVERY intent.parts entry, in the same order, with part_id, answer and evidence_ids. Do not omit a part or add section numbering; the application formats sections.
            Begin each catalog part with a concise natural opening in the requested language. Explain the requested focus and limitations; the application appends authoritative labeled product cards, so do not repeat the whole product list.
            Evidence keys begin with that part ID and a colon. Use ONLY that part's evidence, constraints, price scope and compatibility verdict. Never borrow facts across parts. Preserve unknowns and warnings in each affected part.
            INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answers' => $schema->array()->items($schema->object(fn (JsonSchema $schema): array => [
            'part_id' => $schema->string()->enum(['a', 'b', 'c'])->required(),
            'answer' => $schema->string()->required(),
            'evidence_ids' => $schema->array()->items($schema->string())->required(),
        ]))->required()];
    }
}
