<?php

namespace App\Services\Reporting;

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Forecast;
use App\Models\Product;
use App\Services\Forecasting\ProductForecastPreparationService;
use ArithmeticError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @phpstan-import-type MonthlyPreparation from ProductForecastPreparationService
 */
class ForecastingService
{
    private const PRODUCT_BATCH_SIZE = 200;

    private const PRODUCTS_PER_PAGE = 15;

    public function __construct(
        private readonly ProductForecastPreparationService $preparation,
        private readonly CalculateAdditiveHoltWinters $holtWinters,
        private readonly PersistForecast $persistForecast,
    ) {}

    /**
     * @param  array{q?: string|null, product_id?: int|string|null, page?: int|string|null, forecast_page?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function page(array $filters): array
    {
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $search = trim($filters['q'] ?? '');
        $asOf = CarbonImmutable::now(config('app.timezone'));
        $selected = $productId === null ? null : Product::with(['category', 'inventory'])->findOrFail($productId);
        $readiness = $selected === null ? null : $this->readinessData($this->preparation->prepareMonthly($selected, $asOf));

        return [
            'products' => $this->forecastReadyProducts($search, (int) ($filters['page'] ?? 1), $asOf),
            'selected_product' => $selected === null ? null : $this->productData($selected),
            'current_inventory' => $selected === null ? null : $this->inventoryData($selected),
            'filters' => ['q' => $search, 'product_id' => $productId],
            'readiness' => $readiness,
            'timezone' => config('app.timezone'),
            'forecasts' => Forecast::query()->with('product.category')
                ->when($productId !== null, fn (Builder $query) => $query->where('product_id', $productId))
                ->orderByDesc('generated_at')->orderByDesc('id')
                ->paginate(15, ['*'], 'forecast_page')->withQueryString()
                ->through($this->savedData(...)),
        ];
    }

    /** @return LengthAwarePaginator<int, array{id: int, name: string, product_code: string, category: string, is_active: bool, is_synthetic: bool}> */
    private function forecastReadyProducts(string $search, int $page, CarbonImmutable $asOf): LengthAwarePaginator
    {
        $total = 0;
        $pageIds = [];
        $offset = ($page - 1) * self::PRODUCTS_PER_PAGE;
        Product::query()->select(['id', 'name', 'product_code'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$search.'%')->orWhere('product_code', 'like', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')
            ->chunk(self::PRODUCT_BATCH_SIZE, function (Collection $products) use ($asOf, $offset, &$total, &$pageIds): void {
                $prepared = $this->preparation->prepareMonthlyBatch(array_values($products->all()), $asOf);
                foreach ($products as $product) {
                    if ($prepared[$product->id]['status'] !== 'ready') {
                        continue;
                    }
                    if ($total >= $offset && count($pageIds) < self::PRODUCTS_PER_PAGE) {
                        $pageIds[] = $product->id;
                    }
                    $total++;
                }
            });
        $products = Product::query()->with('category')->whereIn('id', $pageIds)
            ->orderBy('name')->orderBy('id')->get()->map($this->productData(...));

        return (new LengthAwarePaginator($products, $total, self::PRODUCTS_PER_PAGE, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]))->withQueryString();
    }

    /** @return array<string, mixed> */
    public function generate(Product $product): array
    {
        try {
            $prepared = $this->preparation->prepareMonthly($product);
        } catch (InvalidArgumentException|QueryException $exception) {
            report($exception);
            throw ValidationException::withMessages(['forecast' => 'Sales history could not be prepared. No forecast was saved.']);
        }
        $base = [
            ...$this->readinessData($prepared),
            'product' => $this->productData($product->loadMissing(['category', 'inventory'])),
            'current_inventory' => $this->inventoryData($product),
            'observations' => [],
            'monthly_forecasts' => [],
            'forecast_quantity' => null,
            'generated_at' => null,
        ];
        if ($prepared['status'] !== 'ready') {
            return $base;
        }

        if ($prepared['history'] === null) {
            throw ValidationException::withMessages(['forecast' => 'Prepared history is unavailable. Please refresh and try again.']);
        }
        try {
            $result = $this->holtWinters->execute($prepared);
        } catch (InvalidArgumentException|ArithmeticError $exception) {
            report($exception);
            throw ValidationException::withMessages(['forecast' => 'The forecast could not be calculated from the prepared monthly history. No forecast was saved.']);
        }
        if ($result['status'] !== 'ok' || $result['forecast_quantity'] === null) {
            throw ValidationException::withMessages(['forecast' => 'The forecast could not be calculated from the prepared monthly history. No forecast was saved.']);
        }
        if (bccomp($result['forecast_quantity'], '9999999999.99', 2) > 0) {
            throw ValidationException::withMessages(['forecast' => 'This forecast exceeds the supported quantity and could not be saved.']);
        }
        $observations = [];
        foreach ($prepared['history']['months'] as $month) {
            $observations[] = [
                'label' => $this->monthLabel($month['start']),
                'start' => $month['start'],
                'end_exclusive' => $month['end_exclusive'],
                'quantity_sold' => $month['quantity_sold'],
            ];
        }
        $monthlyForecasts = [];
        foreach ($result['monthly_forecasts'] as $month) {
            $monthlyForecasts[] = [
                'label' => $this->monthLabel($month['start']),
                'start' => $month['start'],
                'end_exclusive' => $month['end_exclusive'],
                'forecast_quantity' => bcdiv(bcadd($month['usable_quantity'], '0.005', 12), '1', 2),
                'was_clamped' => $month['was_clamped'],
            ];
        }
        try {
            $forecast = DB::transaction(fn (): Forecast => $this->persistForecast->execute($result));
        } catch (InvalidArgumentException|QueryException $exception) {
            report($exception);
            throw ValidationException::withMessages(['forecast' => 'This forecast could not be saved. Please refresh and try again.']);
        }
        $quantity = $forecast->predicted_demand;

        return [
            ...$base,
            'id' => $forecast->id,
            'method' => 'additive_holt_winters',
            'observations' => $observations,
            'monthly_forecasts' => $monthlyForecasts,
            'forecast_quantity' => $quantity,
            'generated_at' => $forecast->generated_at->toIso8601String(),
            'message' => "Estimated 3-month demand: {$quantity} units for {$base['target_label']}, based on 36 completed months of recorded sales.",
            'rounding_note' => 'Monthly estimates are rounded; the 3-month total is calculated before rounding.',
            'guidance' => 'Use this estimate alongside business judgment when planning stock. Actual demand may differ.',
        ];
    }

    /**
     * @param  MonthlyPreparation  $prepared
     * @return array<string, mixed>
     */
    private function readinessData(array $prepared): array
    {
        $status = $prepared['status'];
        $message = match ($status) {
            'ready' => $prepared['message'],
            'insufficient_history' => $prepared['message'].' No forecast was saved.',
            'history_unavailable' => 'Complete monthly sales history is unavailable for the required 36 months. History preparation or import must establish coverage. No forecast was saved.',
            'history_unsuitable' => 'This product’s monthly sales history is too sparse for this forecasting model. No forecast was saved.',
        };

        return [
            'status' => $status,
            'covered_months' => $prepared['covered_months'],
            'source_period' => $prepared['source_period'],
            'source_label' => $this->monthlyPeriodLabel($prepared['source_period']['start'], $prepared['source_period']['end_exclusive']),
            'target_period' => $prepared['target_period'],
            'target_label' => $this->monthlyPeriodLabel($prepared['target_period']['start'], $prepared['target_period']['end_exclusive']),
            'timezone' => $prepared['timezone'],
            'is_synthetic' => ($prepared['coverage']['source_kind'] ?? null) === 'synthetic_development',
            'source_kind' => $prepared['coverage']['source_kind'] ?? null,
            'sales_scope' => $prepared['coverage']['sales_scope'] ?? null,
            'sales_scope_label' => match ($prepared['coverage']['sales_scope'] ?? null) {
                'all_sagay_sales' => 'All Sagay sales',
                'captured_system_transactions' => 'Captured system transactions',
                'development_fixture_transactions' => 'Synthetic development transactions',
                default => null,
            },
            'message' => $message,
        ];
    }

    /** @return array{id: int, name: string, product_code: string, category: string, is_active: bool, is_synthetic: bool} */
    private function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'category' => $product->category->name,
            'is_active' => $product->is_active,
            'is_synthetic' => str_starts_with($product->product_code, 'DEVHIST40'),
        ];
    }

    /** @return array{quantity: int, last_updated: string}|null */
    private function inventoryData(Product $product): ?array
    {
        $inventory = $product->inventory;

        return $inventory === null ? null : [
            'quantity' => $inventory->quantity,
            'last_updated' => $inventory->last_updated->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function savedData(Forecast $forecast): array
    {
        $rolling = str_contains($forecast->forecast_quarter, '/');
        $target = $rolling
            ? CarbonImmutable::parse(substr($forecast->forecast_quarter, 0, 7).'-01', config('app.timezone'))
            : CarbonImmutable::create((int) substr($forecast->forecast_quarter, 0, 4), ((int) substr($forecast->forecast_quarter, -1) - 1) * 3 + 1, 1, 0, 0, 0, config('app.timezone'));

        return [
            'id' => $forecast->id,
            'product' => [
                ...$this->productData($forecast->product),
                'is_deleted' => $forecast->product->trashed(),
            ],
            'method' => $forecast->method,
            'method_label' => match ($forecast->method) {
                'additive_holt_winters' => 'Additive Holt–Winters',
                'moving_average' => 'Moving average — legacy',
                'linear_trend' => 'Linear trend — legacy',
                default => $forecast->method.' — legacy',
            },
            'is_legacy' => $forecast->method !== 'additive_holt_winters',
            'forecast_quantity' => $forecast->predicted_demand,
            'target_label' => $rolling ? $this->monthlyPeriodLabel($target->toDateString(), $target->addMonths(3)->toDateString()) : $this->quarterLabel($target),
            'source_label' => match ($forecast->method) {
                'additive_holt_winters' => $this->monthlyPeriodLabel($target->subMonths(36)->toDateString(), $target->toDateString()).' (inferred from 36-month method)',
                'moving_average' => $this->periodLabel($target->subQuarters(4)->toDateString(), $target->toDateString()).' (inferred from four-quarter method)',
                default => 'Not retained with this saved forecast',
            },
            'generated_at' => $forecast->generated_at->toIso8601String(),
        ];
    }

    private function monthLabel(string $start): string
    {
        return CarbonImmutable::parse($start, config('app.timezone'))->format('M Y');
    }

    private function monthlyPeriodLabel(string $start, string $endExclusive): string
    {
        return $this->monthLabel($start).' – '.$this->monthLabel(CarbonImmutable::parse($endExclusive, config('app.timezone'))->subMonth()->toDateString());
    }

    private function periodLabel(string $start, string $endExclusive): string
    {
        return $this->quarterLabel(CarbonImmutable::parse($start)).' – '.$this->quarterLabel(CarbonImmutable::parse($endExclusive)->subQuarter());
    }

    private function quarterLabel(CarbonImmutable $quarter): string
    {
        return 'Q'.$quarter->quarter.' '.$quarter->year;
    }
}
