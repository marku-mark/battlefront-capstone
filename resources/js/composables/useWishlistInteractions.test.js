import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSharedComposable } from '@vueuse/core';
import * as Vue from 'vue';

function harness(t) {
    const page = Vue.reactive({
        component: 'Products/Index',
        props: {
            auth: { user: { id: 1 }, can: { useCustomerCart: true } },
            wishlistCount: 0,
            products: { data: [{ id: 7, is_wishlisted: false }] },
            recommendations: [{ id: 7, is_wishlisted: false }],
            filters: { q: 'graphics', sort: 'price_asc' },
        },
    });
    const listeners = new Map();
    const reloads = [];
    let cacheFlushes = 0;
    const router = {
        on: (name, callback) => {
            listeners.set(name, callback);
            return () => listeners.delete(name);
        },
        reload: (options) => reloads.push(options),
        flushAll: () => {
            cacheFlushes++;
        },
    };
    const modules = {
        vue: Vue,
        '@vueuse/core': { createSharedComposable },
        '@inertiajs/vue3': { router, usePage: () => page },
    };
    const code = readFileSync(
        new URL('./useWishlistInteractions.js', import.meta.url),
        'utf8',
    )
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names} } = modules[${JSON.stringify(name)}];`,
        )
        .replace('export const useWishlistInteractions =', 'return');
    const useWishlistInteractions = new Function('modules', code)(modules);
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const interactions = scope.run(useWishlistInteractions);
    return {
        page,
        listeners,
        reloads,
        scope,
        interactions,
        useWishlistInteractions,
        cacheFlushes: () => cacheFlushes,
    };
}

test('heart requests preserve the viewport and loaded catalog while updating only confirmed state', (t) => {
    const h = harness(t);
    const options = h.interactions.formOptions(7, { isSaved: true });
    const products = h.page.props.products;
    const filters = h.page.props.filters;
    assert.equal(options.preserveScroll, true);
    assert.equal(options.preserveState, true);
    assert.equal(options.preserveUrl, true);
    assert.deepEqual(options.only, ['wishlistCount']);
    assert.equal(options.async, true);

    options.onBefore();
    assert.equal(h.interactions.isSaved(7), false);
    assert.equal(h.interactions.isBusy(7), true);
    // A partial response must not reset the pending request when auth is unchanged.
    h.page.props = { ...h.page.props, wishlistCount: 1 };
    options.onSuccess();
    options.onFinish();
    assert.equal(h.interactions.isSaved(7), true);
    assert.equal(h.interactions.isBusy(7), false);
    assert.equal(h.page.props.products, products);
    assert.equal(h.page.props.filters, filters);
    assert.equal(h.reloads.length, 0);
    assert.equal(h.cacheFlushes(), 1);
});

test('matching controls share confirmed state and block rapid duplicates while unrelated products remain usable', (t) => {
    const h = harness(t);
    const otherControl = h.scope.run(h.useWishlistInteractions);
    const save = h.interactions.formOptions(7, { isSaved: true });
    save.onBefore();
    assert.equal(otherControl.isBusy(7), true);
    assert.equal(
        otherControl.formOptions(7, { isSaved: true }).onBefore(),
        false,
    );
    assert.notEqual(
        otherControl.formOptions(8, { isSaved: true }).onBefore(),
        false,
    );
    save.onSuccess();
    save.onFinish();
    assert.equal(otherControl.isSaved(7), true);
});

test('validation and network failures retain saved state and permit retry', (t) => {
    const h = harness(t);
    const save = h.interactions.formOptions(7, { isSaved: true });
    save.onBefore();
    save.onError({ product_id: 'This product cannot be saved right now.' });
    save.onFinish();
    assert.equal(h.interactions.isSaved(7), false);
    assert.match(h.interactions.error(7), /cannot be saved/);

    const retry = h.interactions.formOptions(7, { isSaved: true });
    retry.onBefore();
    assert.equal(h.interactions.error(7), undefined);
    assert.equal(retry.onNetworkError(), false);
    retry.onFinish();
    assert.equal(h.interactions.isSaved(7), false);
    assert.match(h.interactions.error(7), /Unable to connect/);
    assert.equal(h.interactions.isBusy(7), false);
    assert.equal(h.cacheFlushes(), 0);
});

test('expired sessions show an actionable error without changing saved state', (t) => {
    const h = harness(t);
    const options = h.interactions.formOptions(7, { isSaved: false });
    options.onBefore();
    assert.equal(options.onHttpException({ status: 419 }), false);
    options.onFinish();
    assert.equal(h.interactions.isSaved(7, true), true);
    assert.match(h.interactions.error(7), /session expired/);
});

test('account changes clear shared state and ignore late success and finish callbacks', (t) => {
    const h = harness(t);
    const old = h.interactions.formOptions(7, { isSaved: true });
    old.onBefore();
    h.page.props.auth.user = { id: 2 };
    assert.equal(h.interactions.isBusy(7), false);
    const current = h.interactions.formOptions(7, { isSaved: false });
    current.onBefore();
    old.onSuccess();
    old.onFinish();
    assert.equal(h.interactions.isSaved(7), false);
    assert.equal(h.interactions.isBusy(7), true);
    current.onFinish();
    h.page.props.auth.user = null;
    assert.equal(h.interactions.isSaved(7), false);
});

test('fresh navigation reconciles returned products without treating retained partial props as fresh', (t) => {
    const h = harness(t);
    const save = h.interactions.formOptions(7, { isSaved: true });
    save.onBefore();
    save.onSuccess();
    save.onFinish();
    const success = (only) => {
        h.listeners.get('start')({
            detail: { visit: { id: 'get', method: 'get', only } },
        });
        h.listeners.get('success')({
            detail: { visitId: 'get', page: h.page },
        });
        h.listeners.get('finish')({ detail: { visit: { id: 'get' } } });
    };
    success(['wishlistCount']);
    assert.equal(h.interactions.isSaved(7), true);
    success(['products']);
    assert.equal(h.interactions.isSaved(7), false);
});

test('history restoration refreshes server state and shared listeners are disposed', (t) => {
    const h = harness(t);
    h.listeners.get('navigate')({ detail: { visitId: 'normal' } });
    assert.equal(h.reloads.length, 0);
    h.listeners.get('navigate')({ detail: {} });
    assert.deepEqual(h.reloads[0].only, [
        'wishlistCount',
        'products',
        'recommendations',
    ]);
    assert.equal(h.reloads[0].showProgress, false);
    h.scope.stop();
    assert.equal(h.listeners.size, 0);
});

test('loading another catalog page does not overwrite confirmed hearts with retained older rows', (t) => {
    const h = harness(t);
    const save = h.interactions.formOptions(7, { isSaved: true });
    save.onBefore();
    save.onSuccess();
    save.onFinish();
    h.listeners.get('start')({
        detail: {
            visit: {
                id: 'scroll',
                method: 'get',
                only: ['products'],
                headers: { 'X-Inertia-Infinite-Scroll-Merge-Intent': 'append' },
            },
        },
    });
    h.listeners.get('success')({ detail: { visitId: 'scroll', page: h.page } });
    assert.equal(h.interactions.isSaved(7), true);
});

test('overlapping product requests reconcile counts once after both finish', (t) => {
    const h = harness(t);
    const first = h.interactions.formOptions(7, { isSaved: true });
    const second = h.interactions.formOptions(8, { isSaved: false });
    first.onBefore();
    second.onBefore();
    second.onSuccess();
    second.onFinish();
    assert.equal(h.reloads.length, 0);
    first.onSuccess();
    first.onFinish();
    assert.deepEqual(h.reloads, [
        { only: ['wishlistCount'], showProgress: false },
    ]);
    assert.equal(h.interactions.isSaved(7), true);
    assert.equal(h.interactions.isSaved(8, true), false);
});

test('canceled requests release their lock and disposal ignores late responses', (t) => {
    const h = harness(t);
    const canceled = h.interactions.formOptions(7, { isSaved: true });
    canceled.onBefore();
    canceled.onFinish();
    assert.equal(h.interactions.isBusy(7), false);
    assert.equal(h.interactions.isSaved(7), false);
    const retry = h.interactions.formOptions(7, { isSaved: true });
    retry.onBefore();
    h.scope.stop();
    retry.onSuccess();
    assert.equal(h.interactions.isSaved(7), false);
});

const Form = { name: 'TestForm' };
const Button = { name: 'TestButton' };
const Link = { name: 'TestLink' };
const route = Object.assign(() => ({ url: '/test', method: 'get' }), {
    form: () => ({ action: '/test', method: 'post' }),
});

function renderComponent(h, path, props) {
    const primitive = {};
    const generic = new Proxy(
        { default: primitive },
        { get: (target, key) => target[key] ?? primitive },
    );
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Form,
                Link,
                Head: primitive,
                usePage: () => h.page,
            },
            '@/components/ui/button': { Button },
            '@/composables/useWishlistInteractions': {
                useWishlistInteractions: h.useWishlistInteractions,
            },
            '@/actions/App/Http/Controllers/WishlistController': {
                default: { store: route, destroy: route },
            },
            '@/actions/App/Http/Controllers/CartItemController': {
                default: { store: route },
            },
            '@/routes': { login: route },
            '@/routes/cart': { index: route },
            '@/routes/products': { index: route, show: route },
            '@/routes/wishlist': { index: route },
        },
        { get: (target, key) => target[key] ?? generic },
    );
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: path,
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
    const component = new Function('modules', code)(modules);
    return h.scope.run(() =>
        component.setup(Vue.reactive(props), { expose() {} }),
    );
}

function nodes(node, predicate) {
    if (!node || typeof node !== 'object') return [];
    const children = Array.isArray(node.children)
        ? node.children
        : (node.children?.default?.({ processing: false }) ?? []);
    return [
        ...(predicate(node) ? [node] : []),
        ...children.flatMap((child) => nodes(child, predicate)),
    ];
}

test('heart components use shared request options and local loading without page-wide progress', (t) => {
    const h = harness(t);
    const props = {
        productId: 7,
        productName: 'Graphics card',
        isWishlisted: false,
        canWishlist: true,
        compact: true,
    };
    const first = renderComponent(
        h,
        '../components/catalog/WishlistButton.vue',
        props,
    );
    const second = renderComponent(
        h,
        '../components/catalog/WishlistButton.vue',
        props,
    );
    const form = nodes(first({}, []), (node) => node.type === Form)[0];
    assert.equal(form.props['show-progress'], false);
    assert.equal(form.props.options.preserveScroll, true);
    form.props.options.onBefore();
    assert.equal(
        nodes(second({}, []), (node) => node.type === Button)[0].props.disabled,
        true,
    );
    form.props.options.onSuccess();
    form.props.options.onFinish();
    assert.equal(
        nodes(second({}, []), (node) => node.type === Button)[0].props[
            'aria-pressed'
        ],
        true,
    );
});

test('wishlist rows retain saved items after adding and replace Add to cart with View cart', (t) => {
    const h = harness(t);
    h.page.component = 'Wishlist/Index';
    const item = {
        id: 1,
        product_id: 7,
        is_in_cart: false,
        can_add_to_cart: true,
        product: { name: 'Graphics card', category: {}, inventory: {} },
    };
    const wishlist = Vue.reactive({
        count: 1,
        items: { data: [item], current_page: 1, last_page: 1 },
    });
    const render = renderComponent(h, '../pages/Wishlist/Index.vue', {
        wishlist,
    });
    const forms = nodes(render({}, []), (node) => node.type === Form);
    assert.equal(forms.length, 2);
    assert.deepEqual(forms[0].props.options.only, [
        'wishlist',
        'wishlistCount',
        'cartQuantity',
    ]);
    forms[0].props.options.onBefore();
    assert.equal(
        nodes(
            render({}, []),
            (node) => node.type === Button && node.props.type === 'submit',
        ).every((node) => node.props.disabled),
        true,
    );
    forms[0].props.options.onSuccess();
    forms[0].props.options.onFinish();
    wishlist.items.data[0].is_in_cart = true;
    assert.equal(wishlist.count, 1);
    assert.equal(nodes(render({}, []), (node) => node.type === Form).length, 1);
    const links = nodes(render({}, []), (node) => node.type === Link);
    assert.ok(
        links.some(
            (node) =>
                nodes(
                    node,
                    (child) =>
                        typeof child.children === 'string' &&
                        child.children.includes('View cart'),
                ).length,
        ),
    );
});

test('unavailable wishlist entries keep View cart and removal accessible', (t) => {
    const h = harness(t);
    h.page.component = 'Wishlist/Index';
    const wishlist = Vue.reactive({
        count: 1,
        items: {
            data: [
                {
                    id: 1,
                    product_id: 7,
                    is_in_cart: true,
                    can_add_to_cart: false,
                    product: null,
                },
            ],
            current_page: 1,
            last_page: 1,
        },
    });
    const render = renderComponent(h, '../pages/Wishlist/Index.vue', {
        wishlist,
    });
    const forms = nodes(render({}, []), (node) => node.type === Form);
    assert.equal(forms.length, 1);
    const remove = forms[0].props.options;
    assert.equal(remove.preserveScroll, true);
    assert.deepEqual(remove.only, ['wishlist', 'wishlistCount']);
    remove.onBefore();
    remove.onError({ product_id: 'Please try again.' });
    remove.onFinish();
    assert.equal(wishlist.count, 1);
    assert.ok(
        nodes(render({}, []), (node) => node.props?.role === 'alert').length,
    );
    const retry = nodes(render({}, []), (node) => node.type === Form)[0].props
        .options;
    retry.onBefore();
    retry.onSuccess();
    retry.onFinish();
    wishlist.items.data = [];
    wishlist.count = 0;
    assert.equal(nodes(render({}, []), (node) => node.type === Form).length, 0);
    assert.ok(
        nodes(
            render({}, []),
            (node) => node.props?.id === 'empty-wishlist-heading',
        ).length,
    );
});

test('guests receive the existing login link without a wishlist mutation form', (t) => {
    const h = harness(t);
    h.page.props.auth = { user: null, can: { useCustomerCart: false } };
    const render = renderComponent(
        h,
        '../components/catalog/WishlistButton.vue',
        {
            productId: 7,
            canWishlist: false,
            compact: false,
        },
    );
    assert.equal(nodes(render({}, []), (node) => node.type === Form).length, 0);
    assert.equal(nodes(render({}, []), (node) => node.type === Link).length, 1);
});
