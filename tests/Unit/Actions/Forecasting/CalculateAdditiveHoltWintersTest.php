<?php

use App\Actions\Forecasting\CalculateAdditiveHoltWinters;
use Tests\MonthlyForecastFixtures;

test('rolling month origins preserve the same Holt Winters numerical calculations', function (string $start, array $targets) {
    $prepared = MonthlyForecastFixtures::preparation(range(1, 36), $start);
    $result = (new CalculateAdditiveHoltWinters)->execute($prepared);
    expect($result['forecast_quantity'])->toBe('114.00');
    expect(array_column($result['monthly_forecasts'], 'raw_quantity'))->toBe(['37.000000000000', '38.000000000000', '39.000000000000']);
    expect(array_column($result['monthly_forecasts'], 'start'))->toBe($targets);
})->with([
    'November' => ['2023-11-01', ['2026-11-01', '2026-12-01', '2027-01-01']],
    'December' => ['2023-12-01', ['2026-12-01', '2027-01-01', '2027-02-01']],
]);

test('forecasts independently known stable growth decline seasonal and zero series', function (array $quantities, array $monthly, string $quarterly) {
    $prepared = MonthlyForecastFixtures::preparation($quantities);

    $result = (new CalculateAdditiveHoltWinters)->execute($prepared);

    expect($result['status'])->toBe('ok');
    expect($result['method'])->toBe('additive_holt_winters');
    expect($result['product_id'])->toBe(7);
    expect($result['product_code'])->toBe('DEVTEST');
    expect($result['source_kind'])->toBe('synthetic_development');
    expect($result['sales_scope'])->toBe('development_fixture_transactions');
    expect($result['forecast_quantity'])->toBe($quarterly);
    expect(array_column($result['monthly_forecasts'], 'raw_quantity'))->toBe($monthly);
    expect(array_column($result['monthly_forecasts'], 'start'))->toBe(['2026-10-01', '2026-11-01', '2026-12-01']);
})->with([
    'stable' => [array_fill(0, 36, 10), ['10.000000000000', '10.000000000000', '10.000000000000'], '30.00'],
    'growth' => [range(1, 36), ['37.000000000000', '38.000000000000', '39.000000000000'], '114.00'],
    'decline' => [range(39, 4), ['3.000000000000', '2.000000000000', '1.000000000000'], '6.00'],
    'negative projection' => [range(35, 0), ['-1.000000000000', '-2.000000000000', '-3.000000000000'], '0.00'],
    'all zero' => [array_fill(0, 36, 0), ['0.000000000000', '0.000000000000', '0.000000000000'], '0.00'],
    'annual profile' => [array_merge(...array_fill(0, 3, [18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22])), ['18.000000000000', '14.000000000000', '12.000000000000'], '44.00'],
    'mixed zero' => [array_merge(...array_fill(0, 3, [18, 0, 20, 0, 10, 0, 12, 0, 14, 0, 16, 0])), ['18.000000000000', '0.000000000000', '20.000000000000'], '38.00'],
]);

test('preserves all nonready preparation outcomes without attempting fitting', function (string $status) {
    $prepared = MonthlyForecastFixtures::preparation(array_fill(0, 36, 10));
    $prepared['status'] = $status;
    $prepared['message'] = 'Not ready';
    if ($status !== 'history_unsuitable') {
        $prepared['history'] = null;
        $prepared['covered_months'] = null;
        $prepared['coverage'] = null;
    }

    $result = (new CalculateAdditiveHoltWinters)->execute($prepared);

    expect($result['status'])->toBe($status);
    expect($result['message'])->toBe('Not ready');
    expect($result['forecast_quantity'])->toBeNull();
    expect($result['parameters'])->toBeNull();
    expect($result['fitting_sse'])->toBeNull();
    expect($result['monthly_forecasts'])->toBe([]);
    expect($result['candidates_evaluated'])->toBe(0);
})->with(['insufficient_history', 'history_unavailable', 'history_unsuitable']);

test('rejects malformed ready preparations instead of repairing input', function (string $path, mixed $value) {
    $prepared = MonthlyForecastFixtures::preparation(array_fill(0, 36, 10));
    $parts = explode('.', $path);
    $cursor = &$prepared;
    foreach ($parts as $part) {
        $cursor = &$cursor[$part];
    }
    $cursor = $value;

    expect(fn () => (new CalculateAdditiveHoltWinters)->execute($prepared))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown status' => ['status', 'ok'], 'missing ID' => ['product_id', 0], 'missing code' => ['product_code', ''],
    'wrong timezone' => ['timezone', 'Invalid/Timezone'], 'empty timezone' => ['timezone', ''],
    'source gap' => ['source_period.start', '2023-11-01'], 'unaligned start' => ['source_period.start', '2023-10-02'],
    'invalid calendar date' => ['source_period.start', '2023-02-30'], 'year zero' => ['source_period.start', '0000-10-01'],
    'target mismatch' => ['target_period.start', '2026-11-01'], 'wrong horizon' => ['target_period.end_exclusive', '2027-02-01'],
    'wrong end year' => ['target_period.end_exclusive', '2028-01-01'], 'reversed end' => ['target_period.end_exclusive', '2026-07-01'],
    'missing history' => ['history', null], 'wrong product' => ['history.product_id', 9], 'wrong history timezone' => ['history.timezone', 'Asia/Shanghai'],
    'wrong end' => ['history.end_exclusive', '2026-09-01'], 'empty months' => ['history.months', []], 'short months' => ['history.months', array_fill(0, 35, [])],
    'negative demand' => ['history.months.0.quantity_sold', -1], 'decimal demand' => ['history.months.0.quantity_sold', 1.5],
    'string demand' => ['history.months.0.quantity_sold', '10'], 'null demand' => ['history.months.0.quantity_sold', null], 'boolean demand' => ['history.months.0.quantity_sold', true],
    'gap' => ['history.months.1.start', '2023-12-01'], 'duplicate month' => ['history.months.1.start', '2023-10-01'],
    'wrong month' => ['history.months.0.month', 11], 'wrong observation year' => ['history.months.0.year', 2024],
    'wrong observation end' => ['history.months.0.end_exclusive', '2023-12-01'], 'missing coverage' => ['coverage', null], 'short coverage count' => ['covered_months', 35],
    'empty coverage' => ['coverage', []], 'coverage timezone mismatch' => ['coverage.timezone', 'Asia/Shanghai'],
    'wrong granularity' => ['coverage.granularity', 'quarter'], 'malformed coverage end' => ['coverage.end_exclusive', null],
    'reversed coverage dates' => ['coverage.start', '2029-10-01'], 'invalid gap list' => ['coverage.unavailable_months', null],
    'invalid gap date' => ['coverage.unavailable_months', ['2025-11-02']], 'invalid provenance' => ['coverage.source_kind', 'unknown'],
    'invalid scope' => ['coverage.sales_scope', 'unknown'],
]);

test('rejects an empty envelope and unordered or extra monthly buckets', function (string $case) {
    $prepared = MonthlyForecastFixtures::preparation(array_fill(0, 36, 10));
    match ($case) {
        'empty' => $prepared = [],
        'unordered' => $prepared['history']['months'] = array_reverse($prepared['history']['months']),
        'extra bucket' => $prepared['history']['months'][] = $prepared['history']['months'][35],
    };

    expect(fn () => (new CalculateAdditiveHoltWinters)->execute($prepared))->toThrow(InvalidArgumentException::class);
})->with(['empty', 'unordered', 'extra bucket']);

test('preserves its input and ignores global decimal scale', function () {
    $prepared = MonthlyForecastFixtures::preparation([...array_fill(0, 24, 10), ...range(11, 22)]);
    $original = $prepared;
    $calculator = new CalculateAdditiveHoltWinters;
    $expected = $calculator->execute($prepared);
    $oldScale = bcscale(2);

    try {
        expect($calculator->execute($prepared))->toBe($expected);
        expect(bcscale())->toBe(2);
    } finally {
        bcscale($oldScale);
    }
    expect($prepared)->toBe($original);
});

test('uses supplied timezone and handles target year rollover', function () {
    $prepared = MonthlyForecastFixtures::preparation(array_fill(0, 36, 10), '2024-01-01');
    $prepared['timezone'] = $prepared['history']['timezone'] = $prepared['coverage']['timezone'] = 'Asia/Shanghai';

    $result = (new CalculateAdditiveHoltWinters)->execute($prepared);

    expect($result['timezone'])->toBe('Asia/Shanghai');
    expect(array_column($result['monthly_forecasts'], 'start'))->toBe(['2027-01-01', '2027-02-01', '2027-03-01']);
    expect($result['monthly_forecasts'][1]['end_exclusive'])->toBe('2027-03-01');
});
