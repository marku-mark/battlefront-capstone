import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

function loadComponent(path, modules, inlineTemplate = true) {
    const { descriptor } = parse(
        readFileSync(new URL(path, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'cart-intent-test',
        inlineTemplate,
    });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(moduleName)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        )
        .replace('export default', 'return');

    return new Function('modules', code)(modules);
}

function componentModules({
    user = null,
    processing = false,
    errors = {},
} = {}) {
    const primitive = {
        render() {
            return Vue.h('div', this.$attrs, this.$slots.default?.());
        },
    };
    const route = () => ({ url: '/products', method: 'get' });
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                useForm: (data) => Vue.reactive({ ...data, processing: false, errors: {}, post() {} }),
                Head: primitive,
                Link: primitive,
                usePage: () => ({
                    props: {
                        auth: {
                            user,
                            can: { useCustomerCart: user?.role === 'customer' },
                        },
                    },
                }),
                Form: {
                    render() {
                        return Vue.h(
                            'form',
                            this.$attrs,
                            this.$slots.default({ processing, errors }),
                        );
                    },
                },
            },
            '@/components/ui/button': {
                Button: {
                    render() {
                        return Vue.h(
                            'button',
                            this.$attrs,
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/components/ui/input': {
                Input: {
                    props: ['defaultValue'],
                    render() {
                        return Vue.h('input', {
                            ...this.$attrs,
                            value: this.defaultValue,
                        });
                    },
                },
            },
            '@/components/ui/label': {
                Label: {
                    render() {
                        return Vue.h(
                            'label',
                            this.$attrs,
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/actions/App/Http/Controllers/CartItemController': {
                store: {
                    form: () => ({ action: '/cart/items', method: 'post' }),
                },
            },
            '@/actions/App/Http/Controllers/PendingCartIntentController': {
                store: {
                    form: () => ({ action: '/cart/intent', method: 'post' }),
                },
            },
            '@/lib/catalogReturn': { consumeCatalogVisit: () => false },
        },
        {
            get: (target, key) =>
                target[key] ??
                new Proxy(
                    {},
                    {
                        get: (_, name) =>
                            ['index', 'store'].includes(name)
                                ? route
                                : primitive,
                    },
                ),
        },
    );
    modules['@/components/cart/AddToCartForm.vue'] = {
        default: loadComponent('./AddToCartForm.vue', modules),
    };
    return modules;
}

test('guest and customer quantity forms use their corresponding POST endpoints', async () => {
    for (const guest of [true, false]) {
        const modules = componentModules();
        const html = await renderToString(
            Vue.h(modules['@/components/cart/AddToCartForm.vue'].default, {
                productId: 7,
                availableQuantity: 4,
                guest,
            }),
        );

        assert.match(
            html,
            new RegExp(
                `action="/cart/${guest ? 'intent' : 'items'}" method="post"`,
            ),
        );
        assert.match(html, /name="product_id" value="7"/);
        assert.match(
            html,
            /name="quantity" type="number" min="1" max="4" step="1"/,
        );
        assert.match(html, /required/);
        assert.match(html, /value="1"/);
        assert.ok(html.includes('4 available'));
        assert.ok(html.includes('does not reserve stock'));
        assert.ok(
            html.includes(guest ? 'Log in to add to cart' : 'Add to cart'),
        );
    }
});

test('quantity guidance follows changing availability without resetting the chosen quantity', () => {
    const scope = Vue.effectScope();
    try {
        const props = Vue.reactive({ productId: 7, availableQuantity: 4 });
        const form = scope.run(() =>
            loadComponent(
                './AddToCartForm.vue',
                componentModules(),
                false,
            ).setup(props, { expose() {} }),
        );
        form.trackEnteredQuantity({ currentTarget: { valueAsNumber: 3 } });
        props.availableQuantity = 2;
        assert.equal(form.exceedsStock.value, true);
        assert.equal(form.isAtMaximum.value, false);
        props.availableQuantity = 3;
        assert.equal(form.exceedsStock.value, false);
        assert.equal(form.isAtMaximum.value, true);
        props.availableQuantity = null;
        assert.equal(form.hasAvailableStock.value, false);
        props.availableQuantity = 0;
        assert.equal(form.hasAvailableStock.value, false);
        props.availableQuantity = 8;
        assert.equal(form.hasAvailableStock.value, true);
        assert.equal(form.enteredQuantity.value, 3);
    } finally {
        scope.stop();
    }
});

test('quantity forms disable purchasing when stock is zero or missing', async () => {
    for (const availableQuantity of [0, null]) {
        const modules = componentModules();
        const html = await renderToString(
            Vue.h(modules['@/components/cart/AddToCartForm.vue'].default, {
                productId: 7,
                availableQuantity,
            }),
        );
        assert.match(html, /<button[^>]*disabled/);
        assert.match(html, /<input[^>]*name="quantity"[^>]*disabled/);
        assert.ok(
            html.includes(
                availableQuantity === 0 ? 'Out of stock' : 'Stock unavailable',
            ),
        );
        assert.doesNotMatch(html, /null available|0 available/);
    }
});

test('quantity feedback reacts when a guest reaches available stock', () => {
    const scope = Vue.effectScope();
    try {
        const form = scope.run(() =>
            loadComponent(
                './AddToCartForm.vue',
                componentModules(),
                false,
            ).setup(
                { productId: 7, availableQuantity: 4, guest: true },
                { expose() {} },
            ),
        );
        assert.equal(form.isAtMaximum.value, false);
        form.trackEnteredQuantity({ currentTarget: { valueAsNumber: 4 } });
        assert.equal(form.isAtMaximum.value, true);
        form.trackEnteredQuantity({ currentTarget: { valueAsNumber: 2 } });
        assert.equal(form.isAtMaximum.value, false);
    } finally {
        scope.stop();
    }
});

test('guest submission disables the button and shows progress', async () => {
    const modules = componentModules({ processing: true });
    const html = await renderToString(
        Vue.h(modules['@/components/cart/AddToCartForm.vue'].default, {
            productId: 7,
            availableQuantity: 4,
            guest: true,
        }),
    );

    assert.match(html, /<button[^>]*disabled/);
    assert.ok(html.includes('Continuing...'));
});

test('product details offer the quantity form only to guests and customers with stock', async () => {
    for (const [user, quantity, expectedAction] of [
        [null, 4, '/cart/intent'],
        [{ role: 'customer' }, 4, '/cart/items'],
        [{ role: 'administrator' }, 4, null],
        [null, 0, null],
    ]) {
        const modules = componentModules({ user });
        const page = loadComponent('../../pages/Products/Show.vue', modules);
        const html = await renderToString(
            Vue.h(page, {
                product: {
                    id: 7,
                    name: 'Graphics card',
                    inventory: {
                        quantity,
                        status: quantity > 0 ? 'in_stock' : 'out_of_stock',
                    },
                    category: { name: 'Graphics cards' },
                    tags: [],
                },
                can_record_product_dwell: false,
            }),
        );

        if (expectedAction) {
            assert.ok(html.includes(`action="${expectedAction}"`));
        } else {
            assert.doesNotMatch(html, /<form/);
        }
    }
});
