import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

function loadForm(inlineTemplate = false) {
    const calls = [];
    const purchase = Vue.reactive({ processing: false, errors: {}, post(url) { calls.push({ url, quantity: this.quantity }); this.processing = true; } });
    const route = { form: () => ({ action: '/cart/items', method: 'post' }), url: () => '/buy-now' };
    const primitive = { render() { return Vue.h('span', this.$attrs, this.$slots.default?.()); } };
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': {
            useForm: (data) => Object.assign(purchase, data),
            Form: { render() { return Vue.h('form', this.$attrs, this.$slots.default?.({ errors: {}, processing: false })); } },
        },
        '@lucide/vue': { LogIn: primitive, ShoppingCart: primitive },
        '@/actions/App/Http/Controllers/CartItemController': { store: route },
        '@/actions/App/Http/Controllers/PendingCartIntentController': { store: route },
        '@/routes/buy-now': { store: route },
        '@/components/InputError.vue': { default: { props: ['message'], render() { return this.message ? Vue.h('p', this.message) : null; } } },
        '@/components/ui/button': { Button: { render() { return Vue.h('button', this.$attrs, this.$slots.default?.()); } } },
        '@/components/ui/input': { Input: { render() { return Vue.h('input', this.$attrs); } } },
        '@/components/ui/label': { Label: { render() { return Vue.h('label', this.$attrs, this.$slots.default?.()); } } },
        '@/components/ui/spinner': { Spinner: primitive },
    };
    const { descriptor } = parse(readFileSync(new URL('./AddToCartForm.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'purchase-form', inlineTemplate });
    const code = script.content.replace(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
        (_, names, moduleName) => `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(moduleName)}];`)
        .replace(/import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) => `const ${name} = modules[${JSON.stringify(moduleName)}].default;`)
        .replace('export default', 'return');
    return { component: new Function('modules', code)(modules), calls, purchase };
}

test('Buy Now uses the chosen quantity and prevents duplicate or invalid submissions', () => {
    const { component, calls, purchase } = loadForm();
    const state = component.setup({ productId: 42, availableQuantity: 5, guest: false }, { expose() {} });
    state.trackEnteredQuantity({ currentTarget: { valueAsNumber: 3 } });
    state.purchaseNow();
    state.purchaseNow();
    assert.deepEqual(calls, [{ url: '/buy-now', quantity: 3 }]);
    purchase.processing = false;
    state.trackEnteredQuantity({ currentTarget: { valueAsNumber: 6 } });
    state.purchaseNow();
    state.trackEnteredQuantity({ currentTarget: { valueAsNumber: NaN } });
    state.purchaseNow();
    assert.equal(calls.length, 1);
});

test('customer and guest purchase controls retain native keyboard buttons and labeled quantity input', async () => {
    for (const guest of [false, true]) {
        const { component } = loadForm(true);
        const html = await renderToString(Vue.createSSRApp(component, { productId: 42, availableQuantity: 5, guest }));
        assert.match(html, /<label[^>]*for="add-cart-quantity-42"/);
        assert.match(html, /<input[^>]*id="add-cart-quantity-42"/);
        assert.match(html, /<button[^>]*type="button"[^>]*>(?:<!--.*?-->)?\s*Buy Now<\/button>/);
        assert.match(html, guest ? /Log in to add to cart/ : /Add to cart/);
        assert.match(html, /flex-col[^"]*sm:flex-row/);
        assert.match(html, /aria-live="polite"/);
    }
});

test('unavailable stock disables Buy Now and preserves a visible explanation', async () => {
    const { component } = loadForm(true);
    const html = await renderToString(Vue.createSSRApp(component, { productId: 42, availableQuantity: 0 }));
    assert.match(html, /<button[^>]*type="button"[^>]*disabled[^>]*>(?:<!--.*?-->)?\s*Buy Now<\/button>/);
    assert.match(html, /Out of stock/);
});
