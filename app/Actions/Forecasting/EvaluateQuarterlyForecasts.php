<?php

namespace App\Actions\Forecasting;

use App\Services\Forecasting\ProductForecastPreparationService;
use App\Services\Reporting\QuarterlySalesAggregationService;
use InvalidArgumentException;

/**
 * Prepared folds and trusted actuals are supplied by the caller, never queried here.
 * Synthetic evaluation establishes controlled behavior, not client accuracy.
 *
 * @phpstan-import-type MonthlyPreparation from ProductForecastPreparationService
 * @phpstan-import-type Month from ProductForecastPreparationService
 * @phpstan-import-type History from QuarterlySalesAggregationService
 *
 * @phpstan-type Fold array{preparation: MonthlyPreparation, actual_months: list<Month>|null}
 * @phpstan-type Evaluation array{status: 'evaluated'|'unevaluated', product_id: int, product_code: string, source_kinds: list<string>, evaluated_count: int, excluded_count: int, origins: list<array<string, mixed>>, mae: array<string, numeric-string|null>, mae_display: array<string, numeric-string|null>}
 */
class EvaluateQuarterlyForecasts
{
    public function __construct(
        private readonly CalculateAdditiveHoltWinters $calculator = new CalculateAdditiveHoltWinters,
        private readonly CalculateMovingAverage $movingAverage = new CalculateMovingAverage,
        private readonly MonthlyForecastInput $input = new MonthlyForecastInput,
        private readonly AdditiveHoltWintersModel $decimal = new AdditiveHoltWintersModel,
    ) {}

    /**
     * Four consecutive held-out quarters; each supplied training window is 36 months.
     * Non-ready training and unavailable actuals are excluded for all three methods.
     *
     * @param  list<Fold>  $folds
     * @return Evaluation
     */
    public function execute(array $folds): array
    {
        $this->validateFoldList($folds);
        $origins = [];
        $sources = [];
        $errors = ['additive_holt_winters' => '0.000000000000', 'seasonal_naive' => '0.000000000000', 'moving_average' => '0.000000000000'];
        $evaluated = 0;
        $first = $folds[0]['preparation'];
        $expectedStart = $first['target_period']['start'];
        foreach ($folds as $fold) {
            $prepared = $fold['preparation'];
            $this->input->validate($prepared);
            $targetStart = $this->input->boundary($prepared['target_period']['start'], $prepared['timezone']);
            if ($targetStart->month % 3 !== 1) {
                throw new InvalidArgumentException('Quarterly evaluation requires calendar-quarter targets.');
            }
            if ($prepared['product_id'] !== $first['product_id'] || $prepared['product_code'] !== $first['product_code']
                || $prepared['timezone'] !== $first['timezone'] || $prepared['target_period']['start'] !== $expectedStart) {
                throw new InvalidArgumentException('Evaluation folds must belong to one product and consecutive target quarters in one timezone.');
            }
            $expectedStart = $prepared['target_period']['end_exclusive'];
            $source = $prepared['coverage']['source_kind'] ?? null;
            if ($source !== null && ! in_array($source, $sources, true)) {
                $sources[] = $source;
            }
            $row = [
                'target_quarter' => ['year' => $targetStart->year, 'quarter' => $targetStart->quarter, ...$prepared['target_period']], 'status' => 'excluded', 'reason' => null,
                'source_kind' => $source, 'sales_scope' => $prepared['coverage']['sales_scope'] ?? null,
                'actual_quantity' => null, 'predictions' => null, 'parameters' => null, 'fitting_sse' => null,
            ];
            if ($prepared['status'] !== 'ready') {
                $row['reason'] = $prepared['status'];
                $origins[] = $row;

                continue;
            }
            if ($fold['actual_months'] === null) {
                $row['reason'] = 'target_history_unavailable';
                $origins[] = $row;

                continue;
            }
            $this->input->validateMonths($fold['actual_months'], $prepared['target_period']['start'], $expectedStart, $prepared['timezone'], 3);
            $coverage = $prepared['coverage'];
            if ($coverage['start'] > $prepared['target_period']['start'] || $coverage['end_exclusive'] < $expectedStart
                || array_filter($coverage['unavailable_months'], fn (string $date): bool => $date >= $prepared['target_period']['start'] && $date < $expectedStart) !== []) {
                $row['reason'] = 'target_history_unavailable';
                $origins[] = $row;

                continue;
            }
            $forecast = $this->calculator->execute($prepared);
            $months = $prepared['history']['months'];
            $naive = $this->decimal->sum(array_map(fn (array $month): string => (string) $month['quantity_sold'], array_slice($months, 24, 3)));
            $baseline = $this->movingAverage->execute($this->quarterlyBaselineHistory($prepared), $prepared['product_id']);
            $actual = $this->decimal->sum(array_map(fn (array $month): string => (string) $month['quantity_sold'], $fold['actual_months']));
            $predictions = [
                'additive_holt_winters' => $forecast['forecast_quantity'],
                'seasonal_naive' => $this->decimal->roundNonnegative($naive), 'moving_average' => $baseline['forecast_quantity'],
            ];
            foreach ($predictions as $method => $quantity) {
                $difference = bcsub($actual, $quantity, AdditiveHoltWintersModel::SCALE);
                $absolute = bccomp($difference, '0', AdditiveHoltWintersModel::SCALE) < 0 ? bcsub('0', $difference, AdditiveHoltWintersModel::SCALE) : $difference;
                $errors[$method] = bcadd($errors[$method], $absolute, AdditiveHoltWintersModel::SCALE);
            }
            $row['status'] = 'evaluated';
            $row['actual_quantity'] = $actual;
            $row['predictions'] = $predictions;
            $row['parameters'] = $forecast['parameters'];
            $row['fitting_sse'] = $forecast['fitting_sse'];
            $origins[] = $row;
            $evaluated++;
        }
        $mae = $display = [];
        foreach ($errors as $method => $error) {
            $value = $evaluated === 0 ? null : bcdiv($error, (string) $evaluated, AdditiveHoltWintersModel::SCALE);
            $mae[$method] = $value;
            $display[$method] = $value === null ? null : $this->decimal->roundNonnegative($value);
        }

        return [
            'status' => $evaluated === 0 ? 'unevaluated' : 'evaluated', 'product_id' => $first['product_id'], 'product_code' => $first['product_code'],
            'source_kinds' => $sources, 'evaluated_count' => $evaluated, 'excluded_count' => 4 - $evaluated,
            'origins' => $origins, 'mae' => $mae, 'mae_display' => $display,
        ];
    }

    /**
     * Adapt only the final twelve observed training months to the retained baseline.
     * This is an in-memory evaluation adapter, not a sales query or new preparation.
     *
     * @param  MonthlyPreparation  $prepared
     * @return History
     */
    private function quarterlyBaselineHistory(array $prepared): array
    {
        $quarters = [];
        foreach (array_chunk(array_slice($prepared['history']['months'], -12), 3) as $block) {
            $quantity = $this->decimal->sum(array_map(fn (array $month): string => (string) $month['quantity_sold'], $block));
            $integer = filter_var(bcdiv($quantity, '1', 0), FILTER_VALIDATE_INT);
            if ($integer === false) {
                throw new InvalidArgumentException('Quarterly baseline demand exceeds the legacy integer contract.');
            }
            $quarters[] = [
                'year' => $block[0]['year'], 'quarter' => intdiv($block[0]['month'] - 1, 3) + 1,
                'start' => $block[0]['start'], 'end_exclusive' => $block[2]['end_exclusive'], 'quantity_sold' => $integer, 'item_revenue' => '0.00',
            ];
        }

        return [
            'dimension' => 'product', 'timezone' => $prepared['timezone'], 'start' => $quarters[0]['start'], 'end_exclusive' => $prepared['target_period']['start'],
            'series' => [['entity_id' => $prepared['product_id'], 'quarters' => $quarters]],
        ];
    }

    /** @param array<array-key, mixed> $folds */
    private function validateFoldList(array $folds): void
    {
        if (! array_is_list($folds) || count($folds) !== 4) {
            throw new InvalidArgumentException('Evaluation requires four consecutive quarterly folds.');
        }
        foreach ($folds as $fold) {
            if (! is_array($fold) || ! is_array($fold['preparation'] ?? null) || ! array_key_exists('actual_months', $fold)) {
                throw new InvalidArgumentException('Each evaluation fold requires preparation and explicit held-out actuals.');
            }
        }
        $this->input->validate($folds[0]['preparation']);
    }
}
