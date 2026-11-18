<?php

namespace App\Services\Chatbot;

class ChatbotIntentState
{
    public const OPERATIONS = ['new_search', 'refine', 'select', 'clarify', 'change_topic'];

    public const FIELDS = ['brand', 'excluded_brands', 'capacity', 'use_case', 'branch', 'availability', 'budget'];

    /** @param array<string, mixed> $intent
     * @param array<string, mixed> $previous
     * @return array<string, mixed> */
    public function apply(array $intent, array $previous): array
    {
        $operation = $intent['operation'] ?? match (true) {
            $intent['reference'] !== 'previous' => 'new_search',
            ($intent['price_scope'] ?? null) === 'previous', ($intent['position'] ?? 0) > 0 => 'select',
            default => 'refine',
        };
        $intent['operation'] = $operation;
        if (in_array($operation, ['refine', 'select'], true)) {
            $intent['reference'] = 'previous';
        }
        if ($operation === 'refine' && in_array($intent['intent'], ['discovery', 'branch_availability', 'recommendation'], true)) {
            $intent['price_scope'] = 'catalog';
            $intent['position'] = 0;
        }
        if (in_array($operation, ['new_search', 'change_topic'], true)) {
            $intent['reference'] = 'new';
            $previous = [];
        }
        foreach ($intent['constraint_updates'] ?? [] as $field => $update) {
            if (in_array($field, ['brand', 'capacity', 'branch'], true) && in_array($update, ['clear', 'set'], true)
                && ($previous[$field] ?? '') !== '' && ($update === 'clear' || $previous[$field] !== ($intent[$field] ?? ''))) {
                $intent['search_query'] = trim(preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($previous[$field], '/').'(?![\p{L}\p{N}])/iu', '', $intent['search_query']) ?? $intent['search_query']);
            }
            if ($update === 'clear') {
                if ($field === 'budget') {
                    $intent['minimum_php'] = $intent['maximum_php'] = null;
                    $intent['sort'] = 'relevance';
                } else {
                    $intent[$field] = match ($field) {
                        'excluded_brands' => [], 'availability' => 'any', default => '',
                    };
                }
            } elseif ($update === 'retain' && $previous !== []) {
                if ($field === 'budget') {
                    $intent['minimum_php'] = $previous['price']['minimum'] ?? null;
                    $intent['maximum_php'] = $previous['price']['maximum'] ?? null;
                    $intent['minimum_inclusive'] = $previous['price']['minimum_inclusive'] ?? true;
                    $intent['maximum_inclusive'] = $previous['price']['maximum_inclusive'] ?? true;
                    $intent['sort'] = match ($previous['price']['sort'] ?? null) {
                        'asc' => 'price_asc', 'desc' => 'price_desc', default => 'relevance',
                    };
                } else {
                    $intent[$field] = $previous[$field] ?? $intent[$field];
                }
            }
        }

        return $intent;
    }

    /** @param array<string, mixed> $intent */
    public function inherits(array $intent, string $field): bool
    {
        return $intent['reference'] === 'previous'
            && ! in_array($intent['constraint_updates'][$field] ?? null, ['clear', 'set'], true);
    }
}
