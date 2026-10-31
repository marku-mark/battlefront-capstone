<?php

namespace App\Actions\Forecasting;

use App\Models\Forecast;
use App\Models\Product;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PersistForecast
{
    public function __construct(private readonly MonthlyForecastInput $input = new MonthlyForecastInput) {}

    /**
     * Persist a completed product Holt-Winters forecast without fitting its model.
     *
     * @param  array<string, mixed>  $result
     */
    public function execute(array $result): Forecast
    {
        if (($result['dimension'] ?? null) === 'category') {
            throw new InvalidArgumentException('Category forecast results cannot be persisted by the product-only Forecast entity.');
        }
        if (array_key_exists('dimension', $result) && $result['dimension'] !== 'product') {
            throw new InvalidArgumentException('A product forecast result is required.');
        }

        $method = $result['method'] ?? null;
        if ($method !== 'additive_holt_winters') {
            throw new InvalidArgumentException('Only additive_holt_winters forecast results can be persisted.');
        }
        if (($result['status'] ?? null) !== 'ok') {
            throw new InvalidArgumentException('Only completed forecast results can be persisted.');
        }

        $productId = $result['product_id'] ?? null;
        if (! is_int($productId) || $productId < 1) {
            throw new InvalidArgumentException('A positive product ID is required.');
        }

        $demand = $result['forecast_quantity'] ?? null;
        if (! is_string($demand) || ! is_numeric($demand)
            || ! preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $demand)) {
            throw new InvalidArgumentException('Forecast demand must be a nonnegative two-decimal value within DECIMAL(12,2).');
        }

        [$forecastPeriod, $targetStart] = $this->validatePeriods($result);
        $this->validateMonthlyForecasts($result['monthly_forecasts'] ?? null, $targetStart, $demand);

        if (! Product::query()->whereKey($productId)->exists()) {
            throw new InvalidArgumentException('The forecast product does not exist.');
        }

        Forecast::query()->upsert([[
            'product_id' => $productId,
            'method' => $method,
            'predicted_demand' => $demand,
            'forecast_quarter' => $forecastPeriod,
            'generated_at' => CarbonImmutable::now(),
        ]], ['product_id', 'method', 'forecast_quarter'], ['predicted_demand', 'generated_at']);

        return Forecast::query()
            ->where('product_id', $productId)
            ->where('method', $method)
            ->where('forecast_quarter', $forecastPeriod)
            ->sole();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{string, CarbonImmutable}
     */
    private function validatePeriods(array $result): array
    {
        $target = $result['target_period'] ?? null;
        $source = $result['source_period'] ?? null;
        $timezoneName = $result['timezone'] ?? null;
        if (! is_array($target) || ! is_array($source) || ! is_string($timezoneName) || $timezoneName === '') {
            throw new InvalidArgumentException('Complete forecast periods and timezone are required.');
        }

        $targetStart = $this->input->boundary($target['start'] ?? null, $timezoneName);
        $targetEnd = $targetStart->addMonths(3);
        if (($target['end_exclusive'] ?? null) !== $targetEnd->toDateString()
            || $targetStart->greaterThan(CarbonImmutable::now($timezoneName)->startOfMonth())) {
            throw new InvalidArgumentException('The target must be three complete months after completed history.');
        }

        $sourceStart = $this->input->boundary($source['start'] ?? null, $timezoneName);
        if (($source['end_exclusive'] ?? null) !== $targetStart->toDateString()
            || $sourceStart->addMonths(36)->toDateString() !== $targetStart->toDateString()) {
            throw new InvalidArgumentException('The source period must span exactly 36 months ending at the target period.');
        }

        return [$targetStart->format('Y-m').'/'.$targetEnd->subMonth()->format('Y-m'), $targetStart];
    }

    private function validateMonthlyForecasts(mixed $months, CarbonImmutable $targetStart, string $demand): void
    {
        if (! is_array($months) || ! array_is_list($months) || count($months) !== 3) {
            throw new InvalidArgumentException('A forecast requires exactly three ordered target months.');
        }

        $next = $targetStart;
        $total = '0.000000000000';
        foreach ($months as $month) {
            if (! is_array($month) || ($month['year'] ?? null) !== $next->year
                || ($month['month'] ?? null) !== $next->month
                || ($month['start'] ?? null) !== $next->toDateString()
                || ($month['end_exclusive'] ?? null) !== $next->addMonth()->toDateString()) {
                throw new InvalidArgumentException('Forecast months must consecutively cover the target period.');
            }

            $raw = $month['raw_quantity'] ?? null;
            $usable = $month['usable_quantity'] ?? null;
            $clamped = $month['was_clamped'] ?? null;
            if (! is_string($raw) || ! is_numeric($raw) || ! preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]{12}$/D', $raw)
                || ! is_string($usable) || ! is_numeric($usable) || ! preg_match('/^(0|[1-9][0-9]*)\.[0-9]{12}$/D', $usable)
                || ! is_bool($clamped)) {
                throw new InvalidArgumentException('Monthly forecasts require scale-12 quantities and boolean clamp flags.');
            }
            $negative = bccomp($raw, '0', 12) < 0;
            if ($clamped !== $negative || bccomp($usable, $negative ? '0' : $raw, 12) !== 0) {
                throw new InvalidArgumentException('Monthly usable quantities must match their clamped raw projections.');
            }
            $total = bcadd($total, $usable, 12);
            $next = $next->addMonth();
        }

        if (bcdiv(bcadd($total, '0.005', 12), '1', 2) !== $demand) {
            throw new InvalidArgumentException('Three-month demand must equal the monthly usable sum rounded once half-up.');
        }
    }
}
