<?php

use App\Models\Forecast;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

afterEach(function () {
    $this->travelBack();
});

test('generated and saved forecast views display two decimals and unit grammar without changing forecast values', function (string $quantity, string $display, string $unitLabel, array $monthlyQuantities, array $monthlyDisplays) {
    $this->travelTo('2026-10-15 12:00:00');
    config(['app.timezone' => 'UTC', 'forecasting.operational_coverage' => []]);
    $product = Product::factory()->create();
    $forecast = Forecast::factory()->for($product)->create(['predicted_demand' => $quantity, 'method' => 'additive_holt_winters']);
    $response = $this->actingAs(User::factory()->administrator()->create())
        ->get(route('administration.forecasting.index', ['product_id' => $product->id]));
    $response->assertInertia(fn (Assert $page) => $page->where('forecasts.data.0.forecast_quantity', $quantity));
    $props = $response->inertiaProps();
    $generated = [
        'status' => 'ready', 'product' => $props['selected_product'], 'forecast_quantity' => $quantity,
        'target_label' => 'Oct 2026 – Dec 2026', 'source_label' => 'October 2023 - September 2026',
        'generated_at' => '2026-10-15T12:00:00Z', 'timezone' => 'UTC', 'is_synthetic' => false,
        'message' => "Estimated 3-month demand: $quantity units for Oct 2026 – Dec 2026, based on 36 completed months of recorded sales.",
        'guidance' => 'Use this estimate alongside business judgment.', 'sales_scope_label' => 'Captured system transactions',
        'observations' => [['start' => '2026-09-01', 'label' => 'Sep 2026', 'quantity_sold' => 7]],
        'monthly_forecasts' => array_map(fn (string $month, string $monthlyQuantity): array => [
            'start' => "2026-$month-01", 'label' => "2026-$month", 'forecast_quantity' => $monthlyQuantity,
        ], ['10', '11', '12'], $monthlyQuantities),
    ];
    $script = <<<'JS'
        import { readFileSync } from 'node:fs';
        import * as Inertia from '@inertiajs/vue3';
        import * as Vue from 'vue';
        import * as ChartJS from 'chart.js';
        import { renderToString } from 'vue/server-renderer';
        import { compileSkeletonTestComponent } from './resources/js/lib/skeletonTestHelpers.js';
        import * as forecastUnits from './resources/js/lib/forecastUnits.js';
        import * as dateTime from './resources/js/lib/dateTime.js';
        const input = JSON.parse(readFileSync(0, 'utf8'));
        let chart;
        const primitive = { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) };
        const primitives = new Proxy({ default: primitive }, { get: (target, key) => target[key] ?? primitive });
        const modules = new Proxy({
            vue: Vue,
            '@inertiajs/vue3': Inertia,
            'chart.js': ChartJS,
            'vue-chartjs': { Line: { props: ['data', 'options'], setup(props) { chart = props; return () => null; } } },
            '@/components/PageHeader.vue': { default: { props: ['title'], setup: (props) => () => Vue.h('h1', props.title) } },
            '@/lib/forecastUnits': forecastUnits,
            '@/lib/dateTime': dateTime,
            '@/composables/useChartTheme': { useChartTheme: () => Vue.shallowRef({}) },
            '@/composables/useDebouncedSearch': { useDebouncedSearch: () => ({ search: Vue.ref(''), isSearching: Vue.ref(false), cancelPendingSearch() {} }) },
            '@/actions/App/Http/Controllers/Administration/ForecastingController': { default: {
                index: Object.assign(() => ({ url: '/administration/forecasting' }), { url: () => '/administration/forecasting' }),
                store: { url: () => '/administration/forecasting' },
            } },
        }, { get(target, key) {
            if (key in target) return target[key];
            if (key.startsWith('@/components/') || key === '@lucide/vue') return primitives;
            throw new Error(`Unexpected forecast test import: ${key}`);
        } });
        const page = compileSkeletonTestComponent(readFileSync('resources/js/pages/Administration/Forecasting.vue', 'utf8'), modules);
        const rendered = await Inertia.createInertiaApp({
            page: { component: 'Administration/Forecasting', props: input.props, flash: { forecast_result: input.generated }, url: '/administration/forecasting', version: null },
            render: renderToString, resolve: () => page,
            setup: ({ App, props, plugin }) => Vue.createSSRApp({ render: () => Vue.h(App, props) }).use(plugin),
        });
        process.stdout.write(JSON.stringify({
            html: rendered.body,
            monthlyData: chart.data.datasets[1].data.slice(-3),
            historicalData: chart.data.datasets[0].data[0],
            tooltip: chart.options.plugins.tooltip.callbacks.label({ dataset: { label: 'Forecast' }, parsed: { y: Number(input.generated.forecast_quantity) } }),
            monthlyTooltips: input.generated.monthly_forecasts.map(month => chart.options.plugins.tooltip.callbacks.label({ dataset: { label: 'Forecast' }, parsed: { y: Number(month.forecast_quantity) } })),
            monthlyTicks: input.generated.monthly_forecasts.map(month => chart.options.scales.y.ticks.callback(Number(month.forecast_quantity))),
            zeroTick: chart.options.scales.y.ticks.callback(0),
            originalQuantity: input.generated.forecast_quantity,
            originalMonthlyQuantities: input.generated.monthly_forecasts.map(month => month.forecast_quantity),
        }));
        JS;
    $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->setInput(json_encode(['props' => $props, 'generated' => $generated], JSON_THROW_ON_ERROR));
    $process->setTimeout(120);

    $process->mustRun();

    $rendered = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $rendered['html']);
    $visible = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($markup)));
    expect($visible)->toContain("Estimated 3-month demand: $display $unitLabel", "$display $unitLabel", 'two decimal places', 'total is calculated from full-precision monthly forecasts before rounding', 'minor decimal differences may remain');
    expect($visible)->toContain('Rolling 3-Month Demand Forecast', 'Oct 2026 – Dec 2026');
    expect($visible)->not->toContain('1.00 units');
    preg_match('/<dl[^>]*aria-label="Monthly demand estimates"[^>]*>(.*?)<\/dl>/s', $rendered['html'], $monthlySection);
    $monthlyVisible = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($monthlySection[1])));
    expect($monthlyVisible)->toContain(...$monthlyDisplays);
    expect($rendered['html'])->toMatch('/<td[^>]*>\s*'.preg_quote($display, '/').'\s*<\/td>/');
    expect($rendered['monthlyData'])->toEqual(array_map('floatval', $monthlyQuantities));
    expect($rendered['historicalData'])->toBe(7);
    expect($rendered['tooltip'])->toBe("Forecast: $display $unitLabel");
    expect($rendered['monthlyTooltips'])->toBe(array_map(fn (string $monthlyDisplay): string => "Forecast: $monthlyDisplay", $monthlyDisplays));
    expect($rendered['monthlyTicks'])->toBe(array_map(fn (string $monthlyDisplay): string => explode(' ', $monthlyDisplay)[0], $monthlyDisplays));
    expect($rendered['zeroTick'])->toBe('0.00');
    expect($rendered['originalQuantity'])->toBe($quantity);
    expect($rendered['originalMonthlyQuantities'])->toBe($monthlyQuantities);
    expect($forecast->fresh()->predicted_demand)->toBe($quantity);
})->with([
    'fractional estimates' => ['5.20', '5.20', 'units', ['3.40', '1.40', '0.40'], ['3.40 units', '1.40 units', '0.40 units']],
    'monthly rounding down differs from total' => ['5.21', '5.21', 'units', ['3.404', '1.404', '0.404'], ['3.40 units', '1.40 units', '0.40 units']],
    'monthly half-up rounding differs from total' => ['5.21', '5.21', 'units', ['3.405', '1.405', '0.395'], ['3.41 units', '1.41 units', '0.40 units']],
    'zero' => ['0.00', '0.00', 'units', ['0', '0.00', '0.000'], ['0.00 units', '0.00 units', '0.00 units']],
    'singular total with separately rounded months' => ['1.00', '1.00', 'unit', ['0.33', '0.33', '0.33'], ['0.33 units', '0.33 units', '0.33 units']],
    'singular monthly estimate' => ['3.01', '3.01', 'units', ['1.00', '1.01', '1.00'], ['1.00 unit', '1.01 units', '1.00 unit']],
    'monthly rounding to one' => ['1.01', '1.01', 'units', ['0.995', '0.005', '0.005'], ['1.00 unit', '0.01 units', '0.01 units']],
]);
