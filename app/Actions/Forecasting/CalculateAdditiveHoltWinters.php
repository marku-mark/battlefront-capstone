<?php

namespace App\Actions\Forecasting;

use App\Services\Forecasting\ProductForecastPreparationService;

/**
 * @phpstan-import-type MonthlyPreparation from ProductForecastPreparationService
 * @phpstan-import-type Parameters from AdditiveHoltWintersModel
 * @phpstan-import-type Projection from AdditiveHoltWintersModel
 *
 * @phpstan-type Forecast array{method: 'additive_holt_winters', status: 'ok'|'insufficient_history'|'history_unavailable'|'history_unsuitable', message: string, product_id: int, product_code: string, timezone: string, source_period: array{start: string, end_exclusive: string}, target_period: array{start: string, end_exclusive: string}, source_kind: string|null, sales_scope: string|null, parameters: Parameters|null, fitting_sse: numeric-string|null, candidates_evaluated: int, monthly_forecasts: list<array{year: int, month: int, start: string, end_exclusive: string, raw_quantity: numeric-string, usable_quantity: numeric-string, was_clamped: bool}>, forecast_quantity: numeric-string|null}
 */
class CalculateAdditiveHoltWinters
{
    public function __construct(
        private readonly AdditiveHoltWintersModel $model = new AdditiveHoltWintersModel,
        private readonly MonthlyForecastInput $input = new MonthlyForecastInput,
    ) {}

    /** @param MonthlyPreparation $preparation
     * @return Forecast
     */
    public function execute(array $preparation): array
    {
        $this->input->validate($preparation);
        $result = [
            'method' => 'additive_holt_winters', 'status' => $preparation['status'] === 'ready' ? 'ok' : $preparation['status'], 'message' => $preparation['message'],
            'product_id' => $preparation['product_id'], 'product_code' => $preparation['product_code'], 'timezone' => $preparation['timezone'],
            'source_period' => $preparation['source_period'], 'target_period' => $preparation['target_period'],
            'source_kind' => $preparation['coverage']['source_kind'] ?? null, 'sales_scope' => $preparation['coverage']['sales_scope'] ?? null,
            'parameters' => null, 'fitting_sse' => null, 'candidates_evaluated' => 0, 'monthly_forecasts' => [], 'forecast_quantity' => null,
        ];
        if ($preparation['status'] !== 'ready') {
            return $result;
        }
        $fit = $this->model->fit(array_column($preparation['history']['months'], 'quantity_sold'));
        $projections = $this->model->forecast($fit['state']);
        $month = $this->input->boundary($preparation['target_period']['start'], $preparation['timezone']);
        foreach ($projections as $projection) {
            $result['monthly_forecasts'][] = [
                'year' => $month->year, 'month' => $month->month, 'start' => $month->toDateString(), 'end_exclusive' => $month->addMonth()->toDateString(),
                ...$projection,
            ];
            $month = $month->addMonth();
        }
        $result['status'] = 'ok';
        $result['parameters'] = $fit['parameters'];
        $result['fitting_sse'] = $fit['fitting_sse'];
        $result['candidates_evaluated'] = $fit['candidates_evaluated'];
        $result['forecast_quantity'] = $this->model->roundNonnegative($this->model->sum(array_column($projections, 'usable_quantity')));

        return $result;
    }
}
