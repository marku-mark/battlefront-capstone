<?php

namespace App\Services\Chatbot;

class ChatbotEvaluationMetrics
{
    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    public function snapshot(array $state): array
    {
        $constraints = $state['model_constraints'] ?? [];

        return [
            'intent' => $constraints['intent'] ?? null,
            'operation' => $constraints['operation'] ?? null,
            'attributes' => array_values(array_map(fn (string $attribute): string => $attribute === 'specifications' ? 'details' : $attribute, $constraints['attributes'] ?? [])),
            'constraints' => array_intersect_key($constraints, array_flip([
                'category_key', 'brand', 'excluded_brands', 'capacity', 'use_case', 'branch', 'availability', 'price',
            ])),
            'product_ids' => array_slice($state['references']['product'] ?? [], 0, 6),
            'sections' => array_map(fn (array $section): array => $this->snapshot([
                'model_constraints' => $section['constraints'] ?? [],
                'references' => ['product' => $section['product_ids'] ?? []],
            ]), array_slice($state['multipart_sections'] ?? [], 0, 2)),
        ];
    }

    /** @param array<string, mixed> $expected
     * @param array<string, mixed> $actual */
    public function matches(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }
            if (is_array($value) && (! array_is_list($value) || (isset($value[0]) && is_array($value[0])))) {
                if (! is_array($actual[$key])) {
                    return false;
                }
                if (array_is_list($value) && count($value) !== count($actual[$key])) {
                    return false;
                }
                if (! $this->matches($value, $actual[$key])) {
                    return false;
                }
            } elseif ($value !== $actual[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{expected: string, actual: string|null}> $labels */
    public function macroF1(array $labels): ?float
    {
        if ($labels === []) {
            return null;
        }
        $classes = array_unique([...array_column($labels, 'expected'), ...array_filter(array_column($labels, 'actual'))]);
        $sum = 0.0;
        foreach ($classes as $class) {
            $true = $falsePositive = $falseNegative = 0;
            foreach ($labels as $label) {
                $true += (int) ($label['expected'] === $class && $label['actual'] === $class);
                $falsePositive += (int) ($label['expected'] !== $class && $label['actual'] === $class);
                $falseNegative += (int) ($label['expected'] === $class && $label['actual'] !== $class);
            }
            $sum += 2 * $true / max(1, 2 * $true + $falsePositive + $falseNegative);
        }

        return $sum / count($classes);
    }
}
