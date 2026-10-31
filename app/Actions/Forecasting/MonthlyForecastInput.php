<?php

namespace App\Actions\Forecasting;

use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Structural validation only. Coverage and model eligibility remain EXT-42's responsibility.
 *
 * @phpstan-import-type Month from ProductForecastPreparationService
 * @phpstan-import-type MonthlyPreparation from ProductForecastPreparationService
 */
class MonthlyForecastInput
{
    /** @param MonthlyPreparation $preparation */
    public function validate(array $preparation): void
    {
        $this->validateContext($preparation);
        if ($preparation['status'] !== 'ready') {
            return;
        }
        $this->validateReadyHistory($preparation);
    }

    /** @param array<string, mixed> $preparation */
    private function validateReadyHistory(array $preparation): void
    {
        $history = $preparation['history'] ?? null;
        if (! is_array($history) || ($history['product_id'] ?? null) !== $preparation['product_id']
            || ($history['timezone'] ?? null) !== $preparation['timezone']
            || ($history['start'] ?? null) !== $preparation['source_period']['start']
            || ($history['end_exclusive'] ?? null) !== $preparation['source_period']['end_exclusive']
            || ($preparation['covered_months'] ?? null) !== 36 || ! is_array($preparation['coverage'] ?? null)) {
            throw new InvalidArgumentException('Ready history must match its prepared product, timezone, and source period.');
        }
        $this->validateCoverage($preparation['coverage'], $preparation['timezone']);
        $this->validateMonths($history['months'] ?? null, $history['start'], $history['end_exclusive'], $history['timezone'], 36);
    }

    /** @param array<string, mixed> $coverage */
    private function validateCoverage(array $coverage, string $timezone): void
    {
        if (($coverage['granularity'] ?? null) !== 'month' || ($coverage['timezone'] ?? null) !== $timezone
            || ! in_array($coverage['source_kind'] ?? null, ['operational_prepared', 'synthetic_development'], true)
            || ! in_array($coverage['sales_scope'] ?? null, ['all_sagay_sales', 'captured_system_transactions', 'development_fixture_transactions'], true)
            || ! is_array($coverage['unavailable_months'] ?? null) || ! array_is_list($coverage['unavailable_months'])) {
            throw new InvalidArgumentException('Ready preparation requires the EXT-42 monthly coverage shape.');
        }
        $start = $this->boundary($coverage['start'] ?? null, $timezone);
        $end = $this->boundary($coverage['end_exclusive'] ?? null, $timezone);
        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('Coverage dates must be ordered.');
        }
        foreach ($coverage['unavailable_months'] as $month) {
            $this->boundary($month, $timezone);
        }
    }

    /** @param array<string, mixed> $preparation */
    private function validateContext(array $preparation): void
    {
        if (! in_array($preparation['status'] ?? null, ['ready', 'insufficient_history', 'history_unavailable', 'history_unsuitable'], true)
            || ! is_string($preparation['message'] ?? null)
            || ! is_int($preparation['product_id'] ?? null) || $preparation['product_id'] < 1
            || ! is_string($preparation['product_code'] ?? null) || $preparation['product_code'] === ''
            || ! is_string($preparation['timezone'] ?? null)
            || ! is_array($preparation['source_period'] ?? null) || ! is_array($preparation['target_period'] ?? null)) {
            throw new InvalidArgumentException('Expected an EXT-42 monthly preparation envelope.');
        }
        $source = $preparation['source_period'];
        $target = $preparation['target_period'];
        $start = $this->boundary($source['start'] ?? null, $preparation['timezone']);
        $end = $this->boundary($source['end_exclusive'] ?? null, $preparation['timezone']);
        if ($start->addMonths(36)->toDateString() !== $end->toDateString()
            || ($target['start'] ?? null) !== $end->toDateString()
            || ($target['end_exclusive'] ?? null) !== $end->addMonths(3)->toDateString()) {
            throw new InvalidArgumentException('Prepared source must span 36 months immediately before its three-month target period.');
        }
    }

    public function validateMonths(mixed $months, string $start, string $endExclusive, string $timezone, int $count): void
    {
        if (! is_array($months) || ! array_is_list($months) || count($months) !== $count) {
            throw new InvalidArgumentException('Monthly history must be a complete ordered list of the required length.');
        }
        $next = $this->boundary($start, $timezone);
        foreach ($months as $month) {
            if (! is_array($month) || ($month['start'] ?? null) !== $next->toDateString()
                || ($month['end_exclusive'] ?? null) !== $next->addMonth()->toDateString()
                || ($month['year'] ?? null) !== $next->year || ($month['month'] ?? null) !== $next->month
                || ! is_int($month['quantity_sold'] ?? null) || $month['quantity_sold'] < 0) {
                throw new InvalidArgumentException('Monthly observations require consecutive calendar periods and nonnegative integer quantities.');
            }
            $next = $next->addMonth();
        }
        if ($next->toDateString() !== $endExclusive) {
            throw new InvalidArgumentException('Monthly observations must cover the specified period exactly.');
        }
    }

    public function boundary(mixed $value, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-01$/D', $value) !== 1 || $timezone === '') {
            throw new InvalidArgumentException('Expected a month-aligned date and explicit timezone.');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid monthly date or timezone.', previous: $exception);
        }
        if ($date === null || $date->year < 1 || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Invalid monthly calendar date.');
        }

        return $date;
    }
}
