import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from './skeletonTestHelpers.js';
import * as forecastUnits from './forecastUnits.js';

test('generation renders the chart after redirected selection props settle', async () => {
    let request;
    let chart;
    let generate;
    const page = Vue.reactive({ props: { auth: { can: { manageForecast: true } } }, flash: {} });
    const primitive = { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) };
    const modules = new Proxy({
        capture: (callback) => { generate = callback; },
        vue: Vue,
        '@inertiajs/vue3': {
            Head: primitive,
            router: {},
            usePage: () => page,
            useForm: (data) => Vue.reactive({ ...data, errors: {}, post: (_, options) => { request = options; } }),
        },
        'chart.js': { Chart: { register() {} } },
        'vue-chartjs': { Line: { props: ['data', 'options'], setup(props) { return () => { chart = props.data; return Vue.h('canvas'); }; } } },
        '@/lib/forecastUnits': forecastUnits,
        '@/lib/dateTime': { BUSINESS_TIMEZONE: 'UTC', formatDateTime: (value) => value },
        '@/composables/useChartTheme': { useChartTheme: () => Vue.ref({}) },
        '@/composables/useDebouncedSearch': { useDebouncedSearch: () => ({ search: Vue.ref(''), isSearching: Vue.ref(false), cancelPendingSearch() {} }) },
        '@/actions/App/Http/Controllers/Administration/ForecastingController': { default: { index: () => ({}), store: { url: () => '/forecasting' } } },
    }, { get: (target, key) => target[key] ?? new Proxy({ default: primitive }, { get: (value, name) => value[name] ?? primitive }) });
    const component = compileSkeletonTestComponent(
        readFileSync(new URL('../pages/Administration/Forecasting.vue', import.meta.url), 'utf8'),
        modules,
        (code) => code.replace('function generate() {', 'modules.capture(generate);\nfunction generate() {'),
    );
    const node = () => ({ children: [] });
    const renderer = Vue.createRenderer({
        createElement: node, createText: node, createComment: node,
        insertStaticContent(_, parent) { const element = node(); element.parent = parent; parent.children.push(element); return [element, element]; },
        setText() {}, setElementText() {}, patchProp() {},
        parentNode: (element) => element.parent,
        nextSibling: () => null,
        insert(element, parent) { element.parent = parent; parent.children.push(element); },
        remove(element) { const parent = element.parent; if (parent) parent.children = parent.children.filter((child) => child !== element); },
    });
    const props = Vue.reactive({
        products: { data: [], current_page: 1 }, forecasts: { data: [], current_page: 1 },
        filters: {}, timezone: 'UTC', selected_product: { id: 1, name: 'Product' },
        readiness: { status: 'ready' },
    });
    const app = renderer.createApp({ render: () => Vue.h(component, props) });
    app.mount(node());
    generate();
    props.selected_product = { id: 2, name: 'Selected product' };
    const result = {
        status: 'ready', product: props.selected_product, forecast_quantity: '6.00',
        observations: Array.from({ length: 36 }, (_, index) => ({ label: `Historical month ${index + 1}`, quantity_sold: 4 })),
        monthly_forecasts: [{ label: 'Nov 2026', forecast_quantity: '1.00' }, { label: 'Dec 2026', forecast_quantity: '2.00' }, { label: 'Jan 2027', forecast_quantity: '3.00' }],
    };
    await request.onSuccess({ flash: { forecast_result: result } });
    await Vue.nextTick();
    assert.ok(chart, 'the generated forecast must mount its chart');
    assert.equal(chart.labels.length, 39);
    assert.deepEqual(chart.labels.slice(-3), ['Nov 2026', 'Dec 2026', 'Jan 2027']);
    assert.deepEqual(chart.datasets[0].data, [...Array(36).fill(4), null, null, null]);
    assert.deepEqual(chart.datasets[1].data, [...Array(35).fill(null), 4, 1, 2, 3]);
    chart = undefined;
    generate();
    await Vue.nextTick();
    assert.equal(chart, undefined, 'the previous chart clears during regeneration');
    await request.onSuccess({ flash: { forecast_result: {
        ...result,
        monthly_forecasts: result.monthly_forecasts.map((month) => ({ ...month, forecast_quantity: '5.00' })),
    } } });
    await Vue.nextTick();
    assert.deepEqual(chart.datasets[1].data, [...Array(35).fill(null), 4, 5, 5, 5]);
    app.unmount();
});
