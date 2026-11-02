import assert from 'node:assert/strict';
import { pageHeaderModule } from './pageHeaderTestHelpers.js';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { App, Deferred, usePage } from '@inertiajs/vue3';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { findAddressNode } from '../lib/deliveryAddressTestHelpers.js';
import { toUrl } from '../lib/utils.js';
import {
    compileSkeletonTestComponent,
    loadSkeletonComponent,
} from '../lib/skeletonTestHelpers.js';

const recommendationKeys = [
    'recommendations',
    'is_personalized',
    'has_featured_fallback',
    'guest_recommendation_scope',
];
const primitive = {
    render() {
        return Vue.h('div', this.$slots.default?.());
    },
};
const button = {
    render() {
        return Vue.h('button', this.$attrs, this.$slots.default?.());
    },
};
const route = (id) => ({ url: `/resource/${id ?? ''}`, method: 'get' });
const emptyFeed = {
    recommendations: [],
    is_personalized: false,
    has_featured_fallback: false,
    guest_recommendation_scope: null,
};

function deferredComponent(router = { reload() {} }) {
    return compileSkeletonTestComponent(
        readFileSync(new URL('./DeferredContent.vue', import.meta.url), 'utf8'),
        {
            vue: Vue,
            '@inertiajs/vue3': { Deferred, router },
            '@/components/ui/button': { Button: button },
        },
    );
}

async function renderPage(
    name,
    props,
    { rescuedProps = [], scrolling = false, hasMore = true } = {},
) {
    const generic = new Proxy(
        { default: primitive },
        { get: (target, key) => target[key] ?? primitive },
    );
    const modules = new Proxy(
        {
            '@/components/PageHeader.vue': pageHeaderModule,
            vue: Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                usePage,
                router: {},
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { ...this.$attrs, href: toUrl(this.href) },
                            this.$slots.default?.(),
                        );
                    },
                },
                InfiniteScroll: {
                    render() {
                        return Vue.h(
                            'div',
                            { ...this.$attrs, 'data-results': true },
                            [
                                this.$slots.default?.(),
                                this.$slots.next?.({
                                    loading: scrolling,
                                    hasMore,
                                }),
                            ],
                        );
                    },
                },
            },
            '@/components/DeferredContent.vue': {
                default: deferredComponent(),
            },
            '@/components/ProductCardSkeleton.vue': {
                default: loadSkeletonComponent('ProductCardSkeleton.vue'),
            },
            '@/components/StatSkeleton.vue': {
                default: loadSkeletonComponent('StatSkeleton.vue'),
            },
            '@/components/ListSkeleton.vue': {
                default: loadSkeletonComponent('ListSkeleton.vue'),
            },
            '@/components/RecommendationSkeleton.vue': {
                default: loadSkeletonComponent('RecommendationSkeleton.vue'),
            },
            '@/components/ui/skeleton': {
                Skeleton: loadSkeletonComponent('ui/skeleton/Skeleton.vue'),
            },
            '@/components/ui/button': { Button: button },
            '@/components/catalog/ProductCard.vue': {
                default: {
                    props: ['product'],
                    render() {
                        return Vue.h('article', this.product.name);
                    },
                },
            },
            '@/components/recommendations/RecommendationSection.vue': {
                default: {
                    props: ['title', 'recommendations'],
                    render() {
                        return Vue.h('section', [
                            Vue.h('h2', this.title),
                            ...this.recommendations.map((item) =>
                                Vue.h('article', item.product.name),
                            ),
                        ]);
                    },
                },
            },
            '@/routes': { dashboard: route },
            '@/routes/cart': { index: route },
            '@/routes/orders': { index: route },
            '@/routes/products': { index: route },
            '@/lib/currency': { formatCurrency: (value) => `PHP ${value}` },
            '@/lib/orderStatus': { orderStatusBadgeClass: () => '' },
            '@/composables/useDebouncedSearch': {
                useDebouncedSearch: () => ({
                    search: Vue.ref(''),
                    isSearching: Vue.ref(false),
                }),
            },
            ...Object.fromEntries(
                ['Order', 'Inventory', 'Product', 'SalesReport'].flatMap(
                    (controller) => [
                        [
                            `@/actions/App/Http/Controllers/Administration/${controller}Controller`,
                            { default: { index: route, show: route } },
                        ],
                        [
                            `@/actions/App/Http/Controllers/${controller}Controller`,
                            { default: { index: route, show: route } },
                        ],
                    ],
                ),
            ),
            '@/actions/App/Http/Controllers/Administration/WorkQueueController':
                {
                    default: { index: route },
                },
            '@/actions/App/Http/Controllers/Administration/StaffPerformanceController':
                {
                    default: {
                        index: () => ({
                            url: '/administration/reports/staff',
                            method: 'get',
                        }),
                    },
                },
            '@/actions/App/Http/Controllers/Administration/TeamController': {
                default: {
                    index: () => ({
                        url: '/administration/team',
                        method: 'get',
                    }),
                },
            },
            '@/actions/App/Http/Controllers/Administration/AuditLogController':
                {
                    default: {
                        index: () => ({
                            url: '/administration/reports/audit',
                            method: 'get',
                        }),
                    },
                },
        },
        { get: (target, key) => target[key] ?? generic },
    );
    const component = compileSkeletonTestComponent(
        readFileSync(new URL(`../pages/${name}.vue`, import.meta.url), 'utf8'),
        modules,
    );

    return renderToString(
        Vue.h(App, {
            initialPage: {
                component: name,
                props,
                url: '/page',
                version: 'test',
                deferredProps: {
                    dashboard: ['dashboard'],
                    recommendations: recommendationKeys,
                },
                rescuedProps,
            },
            initialComponent: component,
            resolveComponent: () => component,
        }),
    );
}

const customerProps = () => ({
    auth: { user: { id: 1, name: 'Customer' } },
    recommendations_enabled: true,
    is_first_visit: true,
});
const catalogProps = () => ({
    auth: { user: null },
    recommendations_enabled: true,
    products: { data: [{ id: 1, name: 'Existing product' }], total: 13 },
    filters: {
        q: null,
        category_id: null,
        brand: null,
        tag_id: null,
        sort: 'featured',
    },
    filter_options: { categories: [], brands: [], tags: [] },
});

test('customer dashboard shell renders local skeletons for genuinely absent deferred props', async () => {
    const html = await renderPage('Dashboard/Customer', customerProps());

    assert.match(html, /Welcome, Customer/);
    assert.match(html, /Browse products/);
    assert.equal((html.match(/data-slot="stat-skeleton"/g) ?? []).length, 4);
    assert.equal(
        (html.match(/data-slot="product-card-skeleton"/g) ?? []).length,
        4,
    );
    assert.match(html, /Loading your latest order/);
    assert.doesNotMatch(html, /No orders yet/);
});

test('loaded empty customer data clears skeletons and renders the existing empty order state', async () => {
    const html = await renderPage('Dashboard/Customer', {
        ...customerProps(),
        ...emptyFeed,
        dashboard: {
            summary: {
                cart_items: 0,
                cart_units: 0,
                active_orders: 0,
                total_orders: 0,
            },
            latest_order: null,
        },
    });

    assert.match(html, /No orders yet/);
    assert.match(html, /Products in cart/);
    assert.doesNotMatch(
        html,
        /data-slot="[^"]*skeleton"|Loading your latest order/,
    );
});

test('administrator skeletons preserve tool navigation and resolve to operational values', async () => {
    const props = {
        auth: {
            user: { id: 2, name: 'Administrator' },
            can: {
                viewReports: true,
                viewOrders: true,
                viewInventory: true,
                manageProducts: true,
            },
        },
    };
    let html = await renderPage('Dashboard/Administration', props);
    assert.equal((html.match(/data-slot="stat-skeleton"/g) ?? []).length, 5);
    assert.match(html, /Administration tools/);
    assert.match(html, /Loading recent orders/);

    props.dashboard = {
        kpis: {
            recorded_revenue: '250.00',
            recorded_sales: 1,
            pending_orders: 0,
            processing_orders: 0,
            low_stock_products: 0,
            out_of_stock_products: 0,
        },
        needs_attention: {
            pending_payment_reviews: 0,
            payment_orders: [],
            out_of_stock_products: [],
            low_stock_products: [],
        },
        recent_orders: [],
    };
    html = await renderPage('Dashboard/Administration', props);
    assert.match(html, /PHP 250\.00/);
    assert.match(html, /No orders have been placed yet/);
    assert.doesNotMatch(html, /data-slot="[^"]*skeleton"/);
});

test('manager dashboard renders authorized operations without revenue or profit cards', async () => {
    const props = {
        auth: {
            user: { name: 'Manager' },
            can: {
                viewOrders: true,
                viewInventory: true,
                viewProfitReports: true,
            },
        },
    };
    let html = await renderPage('Dashboard/Administration', props);
    assert.equal((html.match(/data-slot="stat-skeleton"/g) ?? []).length, 4);

    props.dashboard = {
        kpis: {
            pending_orders: 7,
            processing_orders: 3,
            low_stock_products: 2,
            out_of_stock_products: 1,
        },
        needs_attention: {
            pending_payment_reviews: 0,
            payment_orders: [],
            low_stock_products: [],
            out_of_stock_products: [],
        },
        recent_orders: [],
    };
    html = await renderPage('Dashboard/Administration', props);
    assert.match(html, /Pending orders/);
    assert.match(html, /Low-stock products/);
    assert.doesNotMatch(
        html,
        /All-time product revenue|Gross profit|Sales reports|PHP/,
    );

    props.auth.can = {};
    html = await renderPage('Dashboard/Administration', props);
    assert.match(html, /Administration tools/);
    assert.doesNotMatch(
        html,
        /Operations summary|Needs attention|Recent orders|All-time product revenue|Gross profit/,
    );
});

for (const [name, viewOrders, viewInventory, metricCount] of [
    ['all operations', true, true, 4],
    ['orders only', true, false, 2],
    ['inventory only', false, true, 2],
    ['no modules', false, false, 0],
]) {
    test(`staff dashboard shows permitted counts and shortcuts for ${name}`, async () => {
        const props = {
            auth: {
                user: { name: 'Staff' },
                can: { viewOrders, viewInventory },
            },
        };
        let html = await renderPage('Dashboard/Staff', props);
        assert.equal(
            (html.match(/data-slot="stat-skeleton"/g) ?? []).length,
            metricCount,
        );
        assert.match(html, />Tasks<\/span>/);

        props.dashboard = {
            pending_orders: 17,
            pending_payment_reviews: 23,
            low_stock_products: 31,
            out_of_stock_products: 47,
        };
        html = await renderPage('Dashboard/Staff', props);
        assert.equal(html.includes('Pending orders'), viewOrders);
        assert.equal(html.includes('Payment checks'), viewOrders);
        assert.equal(html.includes('Low-stock products'), viewInventory);
        assert.equal(html.includes('Out-of-stock products'), viewInventory);
        assert.equal(html.includes('>17</dd>'), viewOrders);
        assert.equal(html.includes('>23</dd>'), viewOrders);
        assert.equal(html.includes('>31</dd>'), viewInventory);
        assert.equal(html.includes('>47</dd>'), viewInventory);
        assert.equal(html.includes('>Orders</span>'), viewOrders);
        assert.equal(html.includes('>Inventory</span>'), viewInventory);
        assert.equal(html.includes('>My sales</span>'), viewOrders);
        assert.equal(
            html.includes('href="/administration/reports/staff"'),
            viewOrders,
        );
        assert.doesNotMatch(
            html,
            /All-time product revenue|Gross profit|Sales reports|data-slot="stat-skeleton"/,
        );
    });
}

test('staff dashboard keeps Tasks available when operational data needs a retry', async () => {
    const html = await renderPage(
        'Dashboard/Staff',
        { auth: { user: { name: 'Staff' }, can: { viewOrders: true } } },
        { rescuedProps: ['dashboard'] },
    );

    assert.match(html, /The operational overview could not be loaded/);
    assert.match(html, />Retry<\/button>/);
    assert.match(html, />Tasks<\/span>/);
    assert.doesNotMatch(html, /data-slot="stat-skeleton"/);
});

test('retired IT dashboard is unavailable', async () => {
    await assert.rejects(() => renderPage('Dashboard/It', {}), {
        code: 'ENOENT',
    });
});

test('catalog keeps existing results while deferred recommendations and the next page load', async () => {
    const props = catalogProps();
    let html = await renderPage('Products/Index', props);
    assert.match(html, /Existing product/);
    assert.match(html, /Loading product recommendations/);
    assert.equal(
        (html.match(/data-slot="product-card-skeleton"/g) ?? []).length,
        4,
    );

    html = await renderPage(
        'Products/Index',
        { ...props, ...emptyFeed },
        { scrolling: true },
    );
    assert.match(html, /Existing product/);
    assert.match(html, /Loading more products/);
    assert.equal(
        (html.match(/data-slot="product-card-skeleton"/g) ?? []).length,
        4,
    );

    html = await renderPage(
        'Products/Index',
        { ...props, ...emptyFeed },
        { hasMore: false },
    );
    assert.match(html, /reached the end of the catalog/);
    assert.doesNotMatch(html, /data-slot="product-card-skeleton"/);
});

test('catalog recommendation errors replace skeletons without hiding products or leaving retry disabled', async () => {
    const html = await renderPage('Products/Index', catalogProps(), {
        rescuedProps: recommendationKeys,
    });
    assert.match(
        html,
        /role="alert"[\s\S]*Product recommendations could not be loaded/,
    );
    assert.match(html, /Existing product/);
    assert.match(html, />Retry<\/button>/);
    assert.doesNotMatch(html, /data-slot="product-card-skeleton"|disabled/);
});

test('paused personalization and search intent never display recommendation skeletons', async () => {
    for (const props of [
        { ...catalogProps(), recommendations_enabled: false },
        {
            ...catalogProps(),
            filters: { ...catalogProps().filters, q: 'Keyboard' },
        },
    ]) {
        const html = await renderPage('Products/Index', props);
        assert.doesNotMatch(
            html,
            /Loading product recommendations|data-slot="product-card-skeleton"/,
        );
    }
});

test('retry requests only the failed data and disables duplicate retry actions while reloading', () => {
    const calls = [];
    const render = deferredComponent({
        reload: (options) => calls.push(options),
    }).setup(
        {
            data: recommendationKeys,
            errorMessage: 'Recommendations unavailable',
        },
        { expose() {} },
    );
    const root = render({}, []);
    const deferred = findAddressNode(root, (node) => node.type === Deferred);
    const retry = findAddressNode(
        deferred.children.rescue({ reloading: false })[0],
        (node) => node.type === button,
    );
    retry.props.onClick();
    assert.deepEqual(calls, [{ only: recommendationKeys }]);
    const busy = findAddressNode(
        deferred.children.rescue({ reloading: true })[0],
        (node) => node.type === button,
    );
    assert.equal(busy.props.disabled, true);
});

test('clearing catalog intent restores deferred recommendations and their server heading', async () => {
    const props = {
        ...catalogProps(),
        recommendations_enabled: false,
        filters: { ...catalogProps().filters, q: 'Keyboard' },
    };
    assert.doesNotMatch(
        await renderPage('Products/Index', props),
        /Loading product recommendations/,
    );

    props.filters.q = null;
    props.recommendations_enabled = true;
    assert.match(
        await renderPage('Products/Index', props),
        /Loading product recommendations/,
    );

    const html = await renderPage('Products/Index', {
        ...props,
        ...emptyFeed,
        is_personalized: true,
        recommendations: [{ product: { id: 7, name: 'Recommended keyboard' } }],
    });
    assert.match(html, /Recommended for you/);
    assert.match(html, /Recommended keyboard/);
    assert.match(html, /Existing product/);
    assert.doesNotMatch(html, /data-slot="product-card-skeleton"/);
});
