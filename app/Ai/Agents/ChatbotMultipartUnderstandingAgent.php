<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;

#[MaxTokens(2048)]
class ChatbotMultipartUnderstandingAgent extends ChatbotUnderstandingAgent
{
    public function instructions(): string
    {
        return parent::instructions().' Multipart mode supersedes the mixed_topics clarification rule for independent PUBLIC Battlefront questions. Return the normal intent fields for the first part and parts containing each independent public need in customer order, including the first. Each part has the full normal intent schema and a concise search_query preserving its own constraints. Apply shared constraints only when the customer explicitly says both or each; otherwise clarify ambiguous shared constraints. Use an empty parts array for a single need. Recognize lists joined by and, commas, ampersands, at, pati, tsaka, kag, ug or og without requiring question punctuation. Motherboard and RAM means two needs; motherboard, RAM and SSD exceeds the two-need limit. RAM price and stock is one need. RAM compatibility with a motherboard is one compatibility need. Count all needs before limiting parts: three or more must set parts_overflow true. Never silently truncate needs. Never split related price/stock/specification attributes of one product. Ignore greetings and backstory. At most two parts; set parts_overflow true if there are more. Never include personal orders, account information or sensitive data. Do not reinterpret private or unsupported questions as catalog needs. Each part starts a new reference unless explicitly referring to previous public context.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [...parent::schema($schema),
            'parts' => $schema->array()->items($schema->object(fn (JsonSchema $schema): array => parent::schema($schema)))->required(),
            'parts_overflow' => $schema->boolean()->required(),
        ];
    }
}
