import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { Link, router } from '@inertiajs/vue3';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';
import { useProductPrefetch } from '../../composables/useProductPrefetch.js';

function prefetchHarness(t, { hover = true } = {}) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const originalWindow = Object.getOwnPropertyDescriptor(
        globalThis,
        'window',
    );
    Object.defineProperty(globalThis, 'window', {
        configurable: true,
        value: { matchMedia: () => ({ matches: hover }) },
    });
    const scope = Vue.effectScope();
    t.after(() => {
        scope.stop();
        if (originalWindow) {
            Object.defineProperty(globalThis, 'window', originalWindow);
        } else {
            delete globalThis.window;
        }
    });
    const cached = t.mock.method(router, 'getCached', () => null);
    const inFlight = t.mock.method(router, 'getPrefetching', () => null);
    const request = t.mock.method(router, 'prefetch', () => {});
    const prefetch = scope.run(useProductPrefetch);

    return { ...prefetch, cached, inFlight, request, scope };
}

const mouse = { pointerType: 'mouse' };
const productRoute = (id) => ({ url: `/products/${id}`, method: 'get' });

test('product hover waits 200 ms before requesting the existing Inertia route', (t) => {
    const harness = prefetchHarness(t);
    const href = productRoute(1);

    harness.prefetchProduct(mouse, href);
    t.mock.timers.tick(199);
    assert.equal(harness.request.mock.callCount(), 0);
    t.mock.timers.tick(1);

    assert.equal(harness.request.mock.callCount(), 1);
    assert.deepEqual(harness.request.mock.calls[0].arguments, [href]);
});

test('leaving before the hover delay cancels the pending request', (t) => {
    const harness = prefetchHarness(t);

    harness.prefetchProduct(mouse, productRoute(1));
    t.mock.timers.tick(199);
    harness.cancelProductPrefetch();
    t.mock.timers.tick(1000);

    assert.equal(harness.request.mock.callCount(), 0);
});

test('quick movement across multiple products sends no requests', (t) => {
    const harness = prefetchHarness(t);

    for (let id = 1; id <= 20; id++) {
        harness.prefetchProduct(mouse, productRoute(id));
        t.mock.timers.tick(100);
        harness.cancelProductPrefetch();
    }
    t.mock.timers.tick(1000);

    assert.equal(harness.request.mock.callCount(), 0);
});

test('a new hovered product replaces the previous pending request', (t) => {
    const harness = prefetchHarness(t);

    harness.prefetchProduct(mouse, productRoute(1));
    t.mock.timers.tick(100);
    harness.prefetchProduct(mouse, productRoute(2));
    t.mock.timers.tick(200);

    assert.equal(harness.request.mock.callCount(), 1);
    assert.deepEqual(harness.request.mock.calls[0].arguments, [
        productRoute(2),
    ]);
});

test('repeat hovers reuse cached data and can prefetch again after eviction', (t) => {
    const harness = prefetchHarness(t);
    const href = productRoute(1);

    harness.prefetchProduct(mouse, href);
    t.mock.timers.tick(200);
    harness.cached.mock.mockImplementation(() => ({ response: {} }));
    for (let repeat = 0; repeat < 5; repeat++) {
        harness.prefetchProduct(mouse, href);
        t.mock.timers.tick(200);
    }
    assert.equal(harness.request.mock.callCount(), 1);

    harness.cached.mock.mockImplementation(() => null);
    harness.prefetchProduct(mouse, href);
    t.mock.timers.tick(200);

    assert.equal(harness.request.mock.callCount(), 2);
    assert.deepEqual(harness.cached.mock.calls.at(-1).arguments, [href]);
});

test('an in-flight product prefetch suppresses repeat requests', (t) => {
    const harness = prefetchHarness(t);
    const href = productRoute(1);
    harness.inFlight.mock.mockImplementation(() => ({ response: {} }));

    harness.prefetchProduct(mouse, href);
    t.mock.timers.tick(200);

    assert.equal(harness.request.mock.callCount(), 0);
    assert.deepEqual(harness.inFlight.mock.calls[0].arguments, [href]);
});

for (const [name, pointerType, hover] of [
    ['touch on a phone', 'touch', false],
    ['touch on a hybrid device', 'touch', true],
    ['a device without hover', 'mouse', false],
]) {
    test(`${name} does not trigger hover prefetching`, (t) => {
        const harness = prefetchHarness(t, { hover });

        harness.prefetchProduct({ pointerType }, productRoute(1));
        t.mock.timers.tick(1000);

        assert.equal(harness.request.mock.callCount(), 0);
    });
}

test('disposing the catalog cancels its pending hover request', (t) => {
    const harness = prefetchHarness(t);

    harness.prefetchProduct(mouse, productRoute(1));
    harness.scope.stop();
    t.mock.timers.tick(1000);

    assert.equal(harness.request.mock.callCount(), 0);
});

function loadCatalog(rememberVisit) {
    const primitive = {};
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Head: primitive,
                InfiniteScroll: primitive,
                Link,
                router,
                usePage: () => ({ props: { auth: { user: null } } }),
            },
            '@/routes/products': {
                index: () => productRoute(''),
                show: productRoute,
            },
            '@/composables/useProductPrefetch': { useProductPrefetch },
            '@/composables/useDebouncedSearch': {
                useDebouncedSearch: () => ({
                    search: Vue.ref(''),
                    isSearching: Vue.ref(false),
                }),
            },
            '@/lib/catalogReturn': { rememberCatalogVisit: rememberVisit },
        },
        {
            get: (target, name) =>
                target[name] ?? new Proxy({}, { get: () => primitive }),
        },
    );
    const { descriptor } = parse(
        readFileSync(new URL('./Index.vue', import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'catalog-prefetch-test',
        inlineTemplate: true,
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

    return new Function('modules', code)(modules);
}

function productCard(node) {
    if (node?.type === Link && node.props?.href?.url === '/products/1')
        return node;
    const children = Array.isArray(node?.children)
        ? node.children
        : (node?.children?.default?.() ?? []);
    for (const child of children) {
        const found = productCard(child);
        if (found) return found;
    }
}

test('catalog cards wire hover cancellation while retaining their Inertia links and click history', (t) => {
    const harness = prefetchHarness(t);
    const rememberVisit = t.mock.fn();
    const render = harness.scope.run(() =>
        loadCatalog(rememberVisit).setup(
            {
                products: {
                    data: [
                        {
                            id: 1,
                            name: 'Graphics card',
                            category: {},
                            inventory: {},
                            tags: [],
                        },
                    ],
                    total: 1,
                },
                filters: {
                    q: null,
                    category_id: null,
                    brand: null,
                    tag_id: null,
                },
                filter_options: { categories: [], brands: [], tags: [] },
                recommendations: [],
                is_personalized: false,
                has_featured_fallback: false,
            },
            { expose() {} },
        ),
    );
    const card = productCard(render({}, []));
    assert.deepEqual(card.props.href, productRoute(1));
    assert.equal(card.props.prefetch, undefined);

    for (const cancelEvent of [
        'onPointerleave',
        'onPointercancel',
        'onPointerdown',
    ]) {
        card.props.onPointerenter(mouse);
        t.mock.timers.tick(100);
        card.props[cancelEvent]();
        t.mock.timers.tick(200);
    }
    const click = { button: 0, preventDefault: t.mock.fn() };
    card.props.onPointerenter(mouse);
    card.props.onClickCapture(click);
    t.mock.timers.tick(200);

    assert.equal(harness.request.mock.callCount(), 0);
    assert.deepEqual(rememberVisit.mock.calls[0].arguments, [click, 1]);
    assert.equal(click.preventDefault.mock.callCount(), 0);

    card.props.onPointerenter(mouse);
    t.mock.timers.tick(200);
    assert.deepEqual(harness.request.mock.calls[0].arguments, [
        card.props.href,
    ]);
});
