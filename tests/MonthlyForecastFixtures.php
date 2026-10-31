<?php

namespace Tests;

use DateTimeImmutable;

class MonthlyForecastFixtures
{
    public static function months(array $quantities, string $start): array
    {
        $date = new DateTimeImmutable($start);
        $months = [];
        foreach ($quantities as $quantity) {
            $next = $date->modify('+1 month');
            $months[] = ['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n'), 'start' => $date->format('Y-m-d'), 'end_exclusive' => $next->format('Y-m-d'), 'quantity_sold' => $quantity];
            $date = $next;
        }

        return $months;
    }

    public static function preparation(array $quantities, string $start = '2023-10-01'): array
    {
        $date = (new DateTimeImmutable($start))->modify('+36 months');
        $end = $date->format('Y-m-d');
        $positive = array_map(fn (array $block): int => count(array_filter($block, fn ($value): bool => is_int($value) && $value > 0)), array_chunk($quantities, 12));

        return [
            'status' => 'ready', 'message' => 'Ready to forecast from 36 trusted completed months.',
            'product_id' => 7, 'product_code' => 'DEVTEST', 'timezone' => 'UTC',
            'source_period' => ['start' => $start, 'end_exclusive' => $end],
            'target_period' => ['start' => $end, 'end_exclusive' => $date->modify('+3 months')->format('Y-m-d')],
            'covered_months' => 36, 'positive_sales_months' => $positive,
            'coverage' => ['granularity' => 'month', 'start' => $start, 'end_exclusive' => $date->modify('+12 months')->format('Y-m-d'), 'unavailable_months' => [], 'timezone' => 'UTC', 'source_kind' => 'synthetic_development', 'sales_scope' => 'development_fixture_transactions'],
            'history' => ['product_id' => 7, 'timezone' => 'UTC', 'start' => $start, 'end_exclusive' => $end, 'months' => self::months($quantities, $start)],
        ];
    }

    public static function folds(array $quantities): array
    {
        $date = new DateTimeImmutable('2022-10-01');
        $folds = [];
        foreach ([0, 3, 6, 9] as $offset) {
            $start = $date->modify('+'.$offset.' months')->format('Y-m-d');
            $preparation = self::preparation(array_slice($quantities, $offset, 36), $start);
            $preparation['coverage']['start'] = '2022-10-01';
            $preparation['coverage']['end_exclusive'] = '2026-10-01';
            $folds[] = ['preparation' => $preparation, 'actual_months' => self::months(array_slice($quantities, $offset + 36, 3), $preparation['target_period']['start'])];
        }

        return $folds;
    }
}
