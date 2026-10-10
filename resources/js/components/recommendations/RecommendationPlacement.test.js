import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

const primitive = {
    render() {
        return Vue.h('div', this.$slots.default?.());
    },
};
const route = (id) => ({ url: `/products/${id ?? ''}`, method: 'get' });
const recommendationSection = {
    props: ['title', 'description', 'recommendations', 'placement'],
    render() {
        return Vue.h('section', { 'data-placement': this.placement }, [
            Vue.h('h2', this.title),
            Vue.h('p', this.description),
            ...this.recommendations.map((item) =>
                Vue.h('article', item.product.name),
            ),
        ]);
    },
};

function loadComponent(
    path,
    {
        authenticated = false,
        inlineTemplate = true,
        router = { visit() {} },
        realSearch = false,
    } = {},
) {
    const page = Vue.reactive({
        props: {
            auth: { user: authenticated ? { id: 1, name: 'Customer' } : null },
        },
    });
    const generic = new Proxy(
        { default: primitive },
        { get: (target, key) => target[key] ?? primitive },
    );
    const modules = new Proxy(
        {
            vue: realSearch
                ? { ...Vue, onBeforeUnmount: Vue.onScopeDispose }
                : Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                Form: {
                    render() {
                        return Vue.h(
                            'form',
                            this.$slots.default?.({
                                errors: {},
                                processing: false,
                            }),
                        );
                    },
                },
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { href: this.href?.url },
                            this.$slots.default?.(),
                        );
                    },
                },
                InfiniteScroll: {
                    render() {
                        return Vue.h(
                            'div',
                            { 'data-catalog-results': true },
                            this.$slots.default?.(),
                        );
                    },
                },
                usePage: () => page,
                router,
            },
            '@/routes': {
                dashboard: route,
                register: route,
                login: route,
                home: route,
            },
            '@/routes/products': {
                index: ({ query = {} } = {}) => {
                    const params = new URLSearchParams(
                        Object.entries(query).filter(
                            ([, value]) => value !== undefined,
                        ),
                    );
                    return {
                        url: `/products${params.size ? `?${params}` : ''}`,
                        method: 'get',
                    };
                },
                show: route,
            },
            '@/routes/branches': { index: route },
            '@/routes/cart': { index: route },
            '@/routes/orders': { index: route },
            '@/routes/profile': { edit: () => ({ url: '/settings/profile' }) },
            '@/actions/App/Http/Controllers/OrderController': {
                default: { show: route },
            },
            '@/actions/App/Http/Controllers/Settings/ProfileController': {
                default: {
                    update: {
                        form: () => ({
                            action: '/settings/profile',
                            method: 'patch',
                        }),
                    },
                },
            },
            '@/lib/orderStatus': { orderStatusBadgeClass: () => '' },
            '@/lib/currency': { formatCurrency: (value) => value },
            '@/lib/catalogReturn': { rememberCatalogVisit() {} },
            '@/composables/useProductPrefetch': {
                useProductPrefetch: () => ({
                    prefetchProduct() {},
                    cancelProductPrefetch() {},
                }),
            },
            '@/composables/useDebouncedSearch': {
                useDebouncedSearch: ({ initialSearch }) => {
                    const search = Vue.ref(initialSearch ?? '');
                    return {
                        search,
                        isSearching: Vue.ref(false),
                        clearSearch: () => {
                            search.value = '';
                        },
                        cancelPendingSearch() {},
                    };
                },
            },
            '@/components/recommendations/RecommendationSection.vue': {
                default: recommendationSection,
            },
        },
        { get: (target, key) => target[key] ?? generic },
    );
    if (realSearch) {
        const searchCode = readFileSync(
            new URL('../../composables/useDebouncedSearch.js', import.meta.url),
            'utf8',
        )
            .replace(
                /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
                (_, names, moduleName) =>
                    `const { ${names} } = modules[${JSON.stringify(moduleName)}];`,
            )
            .replace('export function', 'function');
        modules['@/composables/useDebouncedSearch'] = {
            useDebouncedSearch: new Function(
                'modules',
                searchCode + '\nreturn useDebouncedSearch;',
            )(modules),
        };
    }
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'recommendation-placement-test',
        inlineTemplate,
    });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');
    const component = new Function('modules', code)(modules);
    return { ...component, computed: { $page: () => page } };
}

function catalogProps(overrides = {}) {
    return {
        products: {
            data: [
                {
                    id: 1,
                    name: 'Ordinary catalog product',
                    category: { name: 'Hardware' },
                    inventory: {},
                },
            ],
            total: 13,
        },
        filters: { q: null, category_id: null, brand: null, tag_id: null },
        filter_options: { categories: [], brands: [], tags: [] },
        recommendations: [
            { product: { id: 99, name: 'Behavioral suggestion' } },
        ],
        is_personalized: true,
        has_featured_fallback: false,
        ...overrides,
    };
}

test('stale catalog selections remain clearable and labels recover when availability returns', async (t) => {
    const requests = [];
    const props = Vue.reactive(
        catalogProps({
            products: { data: [], total: 0 },
            filters: { q: null, category_id: 7, brand: 'Atlas', tag_id: 8 },
        }),
    );
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const component = loadComponent('../../pages/Products/Index.vue', {
        inlineTemplate: false,
        router: {
            visit: (route, options) => requests.push({ route, options }),
        },
    });
    const state = scope.run(() => component.setup(props, { expose() {} }));

    assert.equal(
        state.categoryPlaceholder.value,
        'Selected category unavailable',
    );
    assert.equal(state.brandPlaceholder.value, 'Selected brand unavailable');
    assert.equal(state.tagPlaceholder.value, 'Selected tag unavailable');
    assert.equal(state.hasAppliedFilters.value, true);
    assert.deepEqual(state.selectedFilters(), {
        category_id: '7',
        brand: 'Atlas',
        tag_id: '8',
    });
    assert.equal(state.showRecommendations.value, false);

    props.filter_options = {
        categories: [{ id: 7, name: 'Networking' }],
        brands: ['Atlas'],
        tags: [{ id: 8, name: 'Remote kit' }],
    };
    assert.equal(state.categoryPlaceholder.value, 'All categories');
    assert.equal(state.brandPlaceholder.value, 'All brands');
    assert.equal(state.tagPlaceholder.value, 'All tags');
    assert.equal(requests.length, 0);

    state.clearFilters();
    await Vue.nextTick();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].route.url, '/products');
    assert.deepEqual(requests[0].options.reset, ['products']);
});

test('catalog renders recommendations above all products and outside ordinary results', async () => {
    const component = loadComponent('../../pages/Products/Index.vue');
    const html = await renderToString(Vue.h(component, catalogProps()));
    assert.ok(
        html.indexOf('Recommended for you') < html.indexOf('All products'),
    );
    assert.ok(html.indexOf('All products') < html.indexOf('Search products'));
    assert.ok(
        html.indexOf('Behavioral suggestion') <
            html.indexOf('data-catalog-results'),
    );
    assert.match(html, /recent browsing in this browser/);
    assert.match(html, /Showing 1 of 13/);
    assert.match(html, /Ordinary catalog product/);
    assert.doesNotMatch(html, /View all recommendations/);
});

for (const [filter, value] of Object.entries({
    q: 'RTX 5070',
    category_id: 1,
    brand: 'Atlas',
    tag_id: 1,
})) {
    test(`catalog hides recommendations for ${filter} even if old props contain cards`, async () => {
        const props = catalogProps();
        props.filters[filter] = value;
        const html = await renderToString(
            Vue.h(loadComponent('../../pages/Products/Index.vue'), props),
        );
        assert.doesNotMatch(
            html,
            /data-placement="catalog"|Behavioral suggestion/,
        );
        assert.match(html, /All products/);
    });
}

test('catalog immediately hides cards while entering intent and waits for cleared server filters', () => {
    const props = Vue.reactive(catalogProps());
    const scope = Vue.effectScope();
    try {
        const component = loadComponent('../../pages/Products/Index.vue', {
            inlineTemplate: false,
        });
        const state = scope.run(() => component.setup(props, { expose() {} }));
        assert.equal(state.showRecommendations.value, true);
        state.search.value = 'RTX 5070';
        assert.equal(state.showRecommendations.value, false);
        state.search.value = '';
        state.categoryId.value = '1';
        assert.equal(state.showRecommendations.value, false);
        props.filters.category_id = 1;
        state.categoryId.value = 'all';
        assert.equal(state.showRecommendations.value, false);
        props.filters.category_id = null;
        assert.equal(state.showRecommendations.value, true);
    } finally {
        scope.stop();
    }
});

test('catalog fallback and empty sections use truthful compact presentation', async () => {
    const component = loadComponent('../../pages/Products/Index.vue');
    const html = await renderToString(
        Vue.h(
            component,
            catalogProps({
                is_personalized: false,
                has_featured_fallback: true,
            }),
        ),
    );
    assert.match(html, /Popular and featured products/);
    assert.doesNotMatch(
        html,
        /Recommended for you|recent browsing in this browser/,
    );
    const empty = await renderToString(
        Vue.h(component, catalogProps({ recommendations: [] })),
    );
    assert.doesNotMatch(empty, /data-placement="catalog"|No recommendations/);
});

test('customer catalog uses account shopping wording', async () => {
    const html = await renderToString(
        Vue.h(
            loadComponent('../../pages/Products/Index.vue', {
                authenticated: true,
            }),
            catalogProps(),
        ),
    );
    assert.match(html, /your recent browsing, cart, and completed purchases/);
});

test('dashboard keeps recommendations between shopping summary and latest order', async () => {
    const component = loadComponent('../../pages/Dashboard/Customer.vue', {
        authenticated: true,
    });
    const props = {
        dashboard: {
            summary: {
                cart_items: 0,
                cart_units: 0,
                active_orders: 0,
                total_orders: 0,
            },
            latest_order: null,
        },
        recommendations: catalogProps().recommendations,
        is_personalized: true,
    };
    const html = await renderToString(Vue.h(component, props));
    assert.match(html, /data-placement="dashboard"/);
    assert.ok(
        html.indexOf('Shopping summary') < html.indexOf('Recommended for you'),
    );
    assert.ok(
        html.indexOf('Recommended for you') < html.indexOf('Latest order'),
    );
    const empty = await renderToString(
        Vue.h(component, { ...props, recommendations: [] }),
    );
    assert.doesNotMatch(empty, /data-placement="dashboard"|No recommendations/);
    const fallback = await renderToString(
        Vue.h(component, {
            ...props,
            is_personalized: false,
            has_featured_fallback: true,
        }),
    );
    assert.match(fallback, /Popular and featured products/);
    assert.doesNotMatch(fallback, /Recommended for you/);
});

test('landing page and public header have no dedicated recommendation destination', async () => {
    const html = await renderToString(
        Vue.h(loadComponent('../../pages/Welcome.vue')),
    );
    assert.doesNotMatch(
        html,
        /data-placement|View all recommendations|Recommended for you/,
    );
    const header = await renderToString(
        Vue.h(loadComponent('../StorefrontHeader.vue')),
    );
    assert.doesNotMatch(header, /Recommendations|For you|\/recommendations/);
    assert.match(header, /Products|Shop/);
});

test('shared recommendation cards retain product navigation and remove page navigation', async () => {
    const component = loadComponent('./RecommendationSection.vue');
    const html = await renderToString(
        Vue.h(component, {
            title: 'Recommended for you',
            description: 'Based on recent browsing.',
            placement: 'catalog',
            recommendations: [
                {
                    product: {
                        id: 99,
                        name: 'RTX 5070',
                        category: { name: 'Graphics cards' },
                        inventory: {},
                    },
                    reasons: [
                        {
                            code: 'matched_recent_searches',
                            value: 'Matched recent searches',
                        },
                    ],
                },
            ],
        }),
    );
    assert.match(html, /href="\/products\/99"/);
    assert.match(html, /Matched recent searches/);
    assert.match(html, /> Hide </);
    assert.doesNotMatch(html, /Report a problem/);
    assert.doesNotMatch(
        html,
        /View all recommendations|href="\/recommendations"/,
    );
});

test('first live catalog search and clear refresh filters and recommendations along with products', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    const router = {
        visit: (route, options) => {
            requests.push({ route, options });
            options.onCancelToken?.({ cancel() {} });
            options.onStart?.();
        },
    };
    const props = Vue.reactive(catalogProps());
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const component = loadComponent('../../pages/Products/Index.vue', {
        inlineTemplate: false,
        router,
        realSearch: true,
    });
    const state = scope.run(() => component.setup(props, { expose() {} }));

    state.search.value = 'laptop';
    await Vue.nextTick();
    t.mock.timers.tick(400);
    assert.equal(requests[0].route.url, '/products?q=laptop');
    assert.ok(requests[0].options.only?.includes('filters'));
    assert.ok(requests[0].options.only?.includes('recommendations'));
    props.filters = { ...props.filters, q: 'laptop' };
    props.recommendations = [];
    requests[0].options.onFinish();

    state.clearSearch();
    await Vue.nextTick();
    assert.equal(requests[1].route.url, '/products');
    assert.ok(requests[1].options.only.includes('filters'));
    assert.ok(requests[1].options.only.includes('recommendations'));
    assert.deepEqual(requests[1].options.reset, ['products']);
    props.filters = { ...props.filters, q: null };
    props.recommendations = catalogProps().recommendations;
    requests[1].options.onFinish();
    assert.equal(state.showRecommendations.value, true);

    state.categoryId.value = '7';
    await Vue.nextTick();
    assert.equal(requests[2].route.url, '/products?category_id=7');
    assert.ok(requests[2].options.only.includes('filters'));
    assert.ok(requests[2].options.only.includes('recommendations'));
    assert.deepEqual(requests[2].options.reset, ['products']);
});

test('disabled preference responses omit dashboard and catalog sections without an empty block', async () => {
    const dashboard = {
        summary: {
            cart_items: 0,
            cart_units: 0,
            active_orders: 0,
            total_orders: 0,
        },
        latest_order: null,
    };
    for (const path of [
        '../../pages/Dashboard/Customer.vue',
        '../../pages/Products/Index.vue',
    ]) {
        const component = loadComponent(path, { authenticated: true });
        const html = await renderToString(
            Vue.h(component, {
                ...catalogProps(),
                dashboard,
                recommendations: [],
                is_personalized: false,
                has_featured_fallback: false,
            }),
        );
        assert.doesNotMatch(
            html,
            /data-placement="(?:dashboard|catalog)"|No recommendations|Recommended for you/,
        );
    }
});

test('profile settings explain disabled discovery sections and remaining contextual suggestions', async () => {
    const component = loadComponent('../../pages/settings/Profile.vue', {
        authenticated: true,
    });
    const html = await renderToString(
        Vue.h(component, {
            canManageDefaultDeliveryAddress: true,
            personalizedRecommendationsEnabled: false,
            searchRecommendationsEnabled: true,
            productViewRecommendationsEnabled: true,
        }),
    );
    assert.match(
        html,
        /hides recommendation sections on your dashboard and product catalog/,
    );
    assert.match(
        html,
        /Popular picks can still appear on product pages and in your cart/,
    );
    assert.doesNotMatch(
        html,
        /id="personalized-recommendations-enabled"[^>]*checked/,
    );
});
