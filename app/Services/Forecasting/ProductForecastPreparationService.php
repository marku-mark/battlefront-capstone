<?php

namespace App\Services\Forecasting;

use App\Models\Product;
use App\Repositories\Reporting\MonthlySalesRepository;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @phpstan-import-type Coverage from SalesHistoryCoverageRepository
 *
 * @phpstan-type Month array{year: int, month: int, start: string, end_exclusive: string, quantity_sold: int}
 * @phpstan-type MonthlyHistory array{product_id: int, timezone: string, start: string, end_exclusive: string, months: list<Month>}
 * @phpstan-type MonthlyPreparation array{status: 'ready'|'insufficient_history'|'history_unavailable'|'history_unsuitable', message: string, product_id: int, product_code: string, timezone: string, source_period: array{start: string, end_exclusive: string}, target_period: array{start: string, end_exclusive: string}, covered_months: int|null, coverage: Coverage|null, history: MonthlyHistory|null, positive_sales_months: list<int>|null}
 */
class ProductForecastPreparationService
{
    private const REQUIRED_MONTHS = 36;

    public function __construct(
        private readonly SalesHistoryCoverageRepository $coverageRepository,
        private readonly MonthlySalesRepository $monthlySales,
    ) {}

    /**
     * Prepare the approved monthly contract without invoking a calculator or persistence.
     *
     * @return MonthlyPreparation
     */
    public function prepareMonthly(Product $product, ?CarbonImmutable $asOf = null): array
    {
        $this->validateProduct($product);
        $result = $this->prepareCoverage($product, $this->coverageRepository->forProductCode($product->product_code), $asOf);
        if ($result['covered_months'] !== self::REQUIRED_MONTHS) {
            return $result;
        }
        $months = $this->sourceMonths($result);

        return $this->prepareObservations($result, $months, $this->monthlySales->aggregateProduct($product->id, $months));
    }

    /**
     * @param  list<Product>  $products
     * @return array<int, MonthlyPreparation>
     */
    public function prepareMonthlyBatch(array $products, ?CarbonImmutable $asOf = null): array
    {
        if ($products === []) {
            return [];
        }
        foreach ($products as $product) {
            $this->validateProduct($product);
        }
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $coverage = $this->coverageRepository->forProductCodes(array_map(fn (Product $product): string => $product->product_code, $products));
        $results = [];
        $coveredIds = [];
        $months = [];
        foreach ($products as $product) {
            $result = $this->prepareCoverage($product, $coverage[$product->product_code], $asOf);
            $results[$product->id] = $result;
            if ($result['covered_months'] === self::REQUIRED_MONTHS) {
                $coveredIds[] = $product->id;
                if ($months === []) {
                    $months = $this->sourceMonths($result);
                }
            }
        }
        if ($coveredIds === []) {
            return $results;
        }
        $recorded = $this->monthlySales->aggregateProducts($coveredIds, $months);
        foreach ($coveredIds as $id) {
            $results[$id] = $this->prepareObservations($results[$id], $months, $recorded[$id] ?? []);
        }

        return $results;
    }

    private function validateProduct(Product $product): void
    {
        if (! $product->exists || $product->id < 1) {
            throw new InvalidArgumentException('Forecast preparation requires a persisted product.');
        }
    }

    /**
     * @param  Coverage|null  $coverage
     * @return MonthlyPreparation
     */
    private function prepareCoverage(Product $product, ?array $coverage, ?CarbonImmutable $asOf): array
    {
        $timezone = config('app.timezone');
        $targetStart = ($asOf ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfMonth();
        $sourceStart = $targetStart->subMonths(self::REQUIRED_MONTHS);
        $result = [
            'status' => 'history_unavailable',
            'message' => 'Trusted monthly sales history is unavailable for the required source period.',
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'timezone' => $timezone,
            'source_period' => ['start' => $sourceStart->toDateString(), 'end_exclusive' => $targetStart->toDateString()],
            'target_period' => [
                'start' => $targetStart->toDateString(), 'end_exclusive' => $targetStart->addMonths(3)->toDateString(),
            ],
            'covered_months' => null,
            'coverage' => $coverage,
            'history' => null,
            'positive_sales_months' => null,
        ];
        if ($coverage === null || $coverage['end_exclusive'] < $targetStart->toDateString()) {
            return $result;
        }
        foreach ($coverage['unavailable_months'] as $month) {
            if ($month >= $sourceStart->toDateString() && $month < $targetStart->toDateString()) {
                return $result;
            }
        }

        $coveredMonths = 0;
        for ($month = $sourceStart; $month->lessThan($targetStart); $month = $month->addMonth()) {
            if ($month->toDateString() >= $coverage['start']) {
                $coveredMonths++;
            }
        }
        $result['covered_months'] = $coveredMonths;
        if ($coveredMonths < self::REQUIRED_MONTHS) {
            $result['status'] = 'insufficient_history';
            $result['message'] = "{$coveredMonths} completed months covered; 36 are required.";

            return $result;
        }

        return $result;
    }

    /**
     * @param  MonthlyPreparation  $result
     * @return list<Month>
     */
    private function sourceMonths(array $result): array
    {
        $sourceStart = CarbonImmutable::parse($result['source_period']['start'], $result['timezone']);
        $targetStart = CarbonImmutable::parse($result['source_period']['end_exclusive'], $result['timezone']);
        $months = [];
        for ($month = $sourceStart; $month->lessThan($targetStart); $month = $month->addMonth()) {
            $months[] = [
                'year' => $month->year, 'month' => $month->month,
                'start' => $month->toDateString(), 'end_exclusive' => $month->addMonth()->toDateString(),
                'quantity_sold' => 0,
            ];
        }

        return $months;
    }

    /**
     * @param  MonthlyPreparation  $result
     * @param  list<Month>  $months
     * @param  list<array{start: string, quantity_sold: int}>  $recorded
     * @return MonthlyPreparation
     */
    private function prepareObservations(array $result, array $months, array $recorded): array
    {
        $quantities = $this->recordedQuantities(array_column($months, 'start'), $recorded);
        foreach ($months as $index => $month) {
            $months[$index]['quantity_sold'] = $quantities[$month['start']] ?? 0;
        }
        $positiveMonths = [];
        foreach (array_chunk($months, 12) as $block) {
            $positiveMonths[] = count(array_filter($block, fn (array $month): bool => $month['quantity_sold'] > 0));
        }
        $result['history'] = [
            'product_id' => $result['product_id'], 'timezone' => $result['timezone'],
            'start' => $result['source_period']['start'], 'end_exclusive' => $result['source_period']['end_exclusive'],
            'months' => $months,
        ];
        $result['positive_sales_months'] = $positiveMonths;
        if ($positiveMonths !== [0, 0, 0] && array_filter($positiveMonths, fn (int $count): bool => $count < 6) !== []) {
            $result['status'] = 'history_unsuitable';
            $result['message'] = 'History requires at least six positive-sales months in each 12-month block, unless all 36 months are zero.';

            return $result;
        }
        $result['status'] = 'ready';
        $result['message'] = 'Ready to forecast from 36 trusted completed months.';

        return $result;
    }

    /**
     * Validate observations before filling only trusted, unrecorded months.
     *
     * @param  list<string>  $expectedStarts
     * @param  list<array{start: string, quantity_sold: mixed}>  $recorded
     * @return array<string, int>
     */
    private function recordedQuantities(array $expectedStarts, array $recorded): array
    {
        $quantities = [];
        foreach ($recorded as $row) {
            if (! in_array($row['start'], $expectedStarts, true) || array_key_exists($row['start'], $quantities)
                || ! is_int($row['quantity_sold']) || $row['quantity_sold'] < 0) {
                throw new InvalidArgumentException('Recorded monthly history contains an invalid or duplicate observation.');
            }
            $quantities[$row['start']] = $row['quantity_sold'];
        }

        return $quantities;
    }
}
