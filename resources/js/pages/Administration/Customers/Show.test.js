import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as icons from '@lucide/vue';
import * as Vue from 'vue';

async function renderPurchases(capabilities, status = 'completed') {
    const primitive = {
        render() {
            return Vue.h('div', [
                this.$slots.default?.(),
                this.$slots.actions?.(),
            ]);
        },
    };
    const controller = (prefix) => ({
        default: {
            index: () => ({ url: prefix }),
            show: (id) => ({ url: `${prefix}/${id}` }),
        },
    });
    const modules = new Proxy(
        {
            vue: Vue,
            '@lucide/vue': icons,
            '@inertiajs/vue3': {
                Head: primitive,
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { href: this.href.url ?? this.href },
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/components/ui/button': { Button: primitive },
            '@/lib/dateTime': { formatTimestampDate: () => 'October 22, 2026' },
            '@/actions/App/Http/Controllers/Administration/CustomerController':
                controller('/administration/customers'),
            '@/actions/App/Http/Controllers/Administration/OrderController':
                controller('/administration/orders'),
            '@/actions/App/Http/Controllers/Administration/CustomerReturnController':
                controller('/administration/customer-returns'),
        },
        { get: (target, key) => target[key] ?? { default: primitive } },
    );
    const { descriptor } = parse(
        readFileSync(new URL('./Show.vue', import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, {
        id: 'customer-purchases-test',
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
    const app = Vue.createSSRApp(component, {
        customer: { id: 1, name: 'Customer', email: 'customer@example.test' },
        order_status_filter: 'completed',
        orders: {
            data: [
                {
                    id: 1,
                    reference: 'BF-000001',
                    status,
                    payment_status: 'verified',
                    items: [],
                    total_amount: '100.00',
                    product_subtotal: '100.00',
                    delivery_fee: '0.00',
                },
            ],
            last_page: 1,
        },
    });
    app.config.globalProperties.$page = {
        props: { auth: { can: capabilities } },
    };
    return renderToString(app);
}

for (const viewOrders of [true, false]) {
    for (const requestReturns of [true, false]) {
        test(`customer record actions respect order=${viewOrders} and return=${requestReturns} permissions`, async () => {
            const html = await renderPurchases({ viewOrders, requestReturns });
            assert.match(html, /BF-000001/);
            assert.equal(
                html.includes('href="/administration/orders/1"'),
                viewOrders,
            );
            assert.equal(html.includes('Record return'), false);
        });
    }
}

test('pending orders never show a return action', async () => {
    assert.doesNotMatch(
        await renderPurchases(
            { viewOrders: true, requestReturns: true },
            'pending',
        ),
        /Record return/,
    );
});
