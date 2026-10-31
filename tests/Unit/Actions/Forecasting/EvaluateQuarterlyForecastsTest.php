<?php

use App\Actions\Forecasting\EvaluateQuarterlyForecasts;
use Tests\MonthlyForecastFixtures;

test('quarterly evaluation continues to reject non calendar quarter folds', function () {
    $folds = MonthlyForecastFixtures::folds(range(1, 48));
    $folds[0]['preparation'] = MonthlyForecastFixtures::preparation(range(1, 36), '2022-11-01');
    expect(fn () => (new EvaluateQuarterlyForecasts)->execute($folds))->toThrow(InvalidArgumentException::class, 'calendar-quarter targets');
});

test('evaluates four rolling linear windows against independently worked quarterly baselines', function () {
    $folds = MonthlyForecastFixtures::folds(range(1, 48));
    $original = $folds;

    $result = (new EvaluateQuarterlyForecasts)->execute($folds);

    expect($result['status'])->toBe('evaluated');
    expect($result['evaluated_count'])->toBe(4);
    expect($result['excluded_count'])->toBe(0);
    expect($result['source_kinds'])->toBe(['synthetic_development']);
    expect(array_column($result['origins'], 'actual_quantity'))->toBe(['114.000000000000', '123.000000000000', '132.000000000000', '141.000000000000']);
    expect(array_column($result['origins'], 'predictions'))->toBe([
        ['additive_holt_winters' => '114.00', 'seasonal_naive' => '78.00', 'moving_average' => '91.50'],
        ['additive_holt_winters' => '123.00', 'seasonal_naive' => '87.00', 'moving_average' => '100.50'],
        ['additive_holt_winters' => '132.00', 'seasonal_naive' => '96.00', 'moving_average' => '109.50'],
        ['additive_holt_winters' => '141.00', 'seasonal_naive' => '105.00', 'moving_average' => '118.50'],
    ]);
    expect($result['mae'])->toBe(['additive_holt_winters' => '0.000000000000', 'seasonal_naive' => '36.000000000000', 'moving_average' => '22.500000000000']);
    expect($result['mae_display'])->toBe(['additive_holt_winters' => '0.00', 'seasonal_naive' => '36.00', 'moving_average' => '22.50']);
    expect($folds)->toBe($original);
});

test('stable and covered zero demand give zero MAE for every method', function (int $quantity) {
    $result = (new EvaluateQuarterlyForecasts)->execute(MonthlyForecastFixtures::folds(array_fill(0, 48, $quantity)));

    expect($result['evaluated_count'])->toBe(4);
    expect($result['mae'])->toBe(array_fill_keys(['additive_holt_winters', 'seasonal_naive', 'moving_average'], '0.000000000000'));
})->with([0, 10]);

test('excludes unavailable targets and unsuitable training from every paired comparison', function () {
    $folds = MonthlyForecastFixtures::folds(range(1, 48));
    $folds[0]['preparation']['status'] = 'history_unsuitable';
    $folds[1]['actual_months'] = null;

    $result = (new EvaluateQuarterlyForecasts)->execute($folds);

    expect($result['evaluated_count'])->toBe(2);
    expect($result['excluded_count'])->toBe(2);
    expect(array_column($result['origins'], 'reason'))->toBe(['history_unsuitable', 'target_history_unavailable', null, null]);
    expect($result['origins'][0]['predictions'])->toBeNull();
    expect($result['origins'][1]['parameters'])->toBeNull();
    expect($result['mae_display'])->toBe(['additive_holt_winters' => '0.00', 'seasonal_naive' => '36.00', 'moving_average' => '22.50']);
});

test('returns unevaluated with null accuracy when all folds are excluded', function () {
    $folds = MonthlyForecastFixtures::folds(array_fill(0, 48, 10));
    foreach ($folds as &$fold) {
        $fold['preparation']['status'] = 'history_unavailable';
        $fold['preparation']['history'] = null;
        $fold['preparation']['coverage'] = null;
    }
    unset($fold);

    $result = (new EvaluateQuarterlyForecasts)->execute($folds);

    expect($result['status'])->toBe('unevaluated');
    expect($result['evaluated_count'])->toBe(0);
    expect($result['excluded_count'])->toBe(4);
    expect($result['source_kinds'])->toBe([]);
    expect($result['mae'])->toBe(array_fill_keys(['additive_holt_winters', 'seasonal_naive', 'moving_average'], null));
    expect($result['mae_display'])->toBe($result['mae']);
});

test('requires coverage for held out actuals even when actual buckets are supplied', function (array $changes) {
    $folds = MonthlyForecastFixtures::folds(array_fill(0, 48, 10));
    $folds[0]['preparation']['coverage'] = array_replace($folds[0]['preparation']['coverage'], $changes);

    $result = (new EvaluateQuarterlyForecasts)->execute($folds);

    expect($result['evaluated_count'])->toBe(3);
    expect($result['origins'][0]['reason'])->toBe('target_history_unavailable');
})->with([
    'stale end' => [['end_exclusive' => '2025-12-01']],
    'target gap' => [['unavailable_months' => ['2025-11-01']]],
    'future coverage only' => [['start' => '2026-01-01']],
]);

test('held out observations affect errors but cannot affect that origins fit or predictions', function () {
    $folds = MonthlyForecastFixtures::folds(range(1, 48));
    $before = (new EvaluateQuarterlyForecasts)->execute($folds);
    $folds[0]['actual_months'][0]['quantity_sold'] += 7;
    $after = (new EvaluateQuarterlyForecasts)->execute($folds);

    expect($after['origins'][0]['predictions'])->toBe($before['origins'][0]['predictions']);
    expect($after['origins'][0]['parameters'])->toBe($before['origins'][0]['parameters']);
    expect($after['origins'][0]['fitting_sse'])->toBe($before['origins'][0]['fitting_sse']);
    expect(array_slice($after['origins'], 1))->toBe(array_slice($before['origins'], 1));
    expect($after['mae'])->toBe(['additive_holt_winters' => '1.750000000000', 'seasonal_naive' => '37.750000000000', 'moving_average' => '24.250000000000']);
});

test('fits again when earlier held out observations enter the next rolling training window', function () {
    $quantities = [...array_fill(0, 36, 10), ...array_fill(0, 12, 30)];
    $result = (new EvaluateQuarterlyForecasts)->execute(MonthlyForecastFixtures::folds($quantities));

    expect($result['origins'][0]['parameters'])->toBe(['alpha' => '0.1', 'beta' => '0.0', 'gamma' => '0.0']);
    expect($result['origins'][0]['predictions']['additive_holt_winters'])->toBe('30.00');
    expect($result['origins'][1]['parameters'])->not->toBe($result['origins'][0]['parameters']);
    expect($result['origins'][1]['fitting_sse'])->not->toBe('0.000000000000');
});

test('reports the abrupt EXT40 scenario even when Holt Winters loses to a baseline', function () {
    $quantities = [...array_fill(0, 12, 10), ...array_fill(0, 12, 15), 80, 5, 120, 8, 90, 6, 150, 12, 100, 4, 130, 9, ...array_fill(0, 6, 5), ...array_fill(0, 6, 120)];

    $result = (new EvaluateQuarterlyForecasts)->execute(MonthlyForecastFixtures::folds($quantities));

    expect($result['evaluated_count'])->toBe(4);
    expect(array_column($result['origins'], 'actual_quantity'))->toBe(['15.000000000000', '15.000000000000', '360.000000000000', '360.000000000000']);
    expect($result['mae']['seasonal_naive'])->toBe('148.500000000000');
    expect($result['mae']['moving_average'])->toBe('189.375000000000');
    expect($result['mae_display']['moving_average'])->toBe('189.38');
    expect(bccomp($result['mae']['additive_holt_winters'], $result['mae']['seasonal_naive'], 12))->toBe(1);
});

test('rejects malformed evaluation folds instead of silently repairing or dropping them', function (Closure $change) {
    $folds = MonthlyForecastFixtures::folds(range(1, 48));
    $change($folds);

    expect(fn () => (new EvaluateQuarterlyForecasts)->execute($folds))->toThrow(InvalidArgumentException::class);
})->with([
    'missing folds' => [function (&$folds) {
        $folds = [];
    }],
    'only three' => [function (&$folds) {
        array_pop($folds);
    }],
    'extra fold' => [function (&$folds) {
        $folds[] = $folds[3];
    }],
    'unordered keys' => [function (&$folds) {
        $folds[5] = $folds[3];
        unset($folds[3]);
    }],
    'invalid first envelope' => [function (&$folds) {
        $folds[0]['preparation'] = [];
    }],
    'missing actual declaration' => [function (&$folds) {
        unset($folds[0]['actual_months']);
    }],
    'different product' => [function (&$folds) {
        $folds[1]['preparation']['product_code'] = 'OTHER';
    }],
    'nonconsecutive origin' => [function (&$folds) {
        $folds[1] = $folds[2];
    }],
    'missing actual month' => [function (&$folds) {
        array_pop($folds[0]['actual_months']);
    }],
    'actual negative quantity' => [function (&$folds) {
        $folds[0]['actual_months'][0]['quantity_sold'] = -1;
    }],
    'actual wrong calendar' => [function (&$folds) {
        $folds[0]['actual_months'][0]['start'] = '2025-09-01';
    }],
    'noninteger actual' => [function (&$folds) {
        $folds[0]['actual_months'][0]['quantity_sold'] = '37';
    }],
]);

test('rejects overflow in the retained moving averages integer quarter contract', function () {
    $folds = MonthlyForecastFixtures::folds(array_fill(0, 48, PHP_INT_MAX));

    expect(fn () => (new EvaluateQuarterlyForecasts)->execute($folds))->toThrow(InvalidArgumentException::class, 'legacy integer contract');
});
