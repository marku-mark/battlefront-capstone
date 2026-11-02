import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatRelativeTime } from '../../lib/dateTime.js';
import { compileSkeletonTestComponent } from '../../lib/skeletonTestHelpers.js';

function findNode(node, predicate) {
    if (!node || typeof node !== 'object') return;
    if (predicate(node)) return node;
    let children = node.children;
    if (children && !Array.isArray(children) && typeof children === 'object') {
        children = Object.values(children)
            .filter((slot) => typeof slot === 'function')
            .flatMap((slot) =>
                slot({ errors: {}, processing: false, reveal() {} }),
            );
    }
    if (Array.isArray(children)) {
        return children
            .map((child) => findNode(child, predicate))
            .find(Boolean);
    }
}

function harness(t, pageName, pageProps = {}) {
    const forms = new Map();
    const page = {
        props: {
            auth: {
                user: { id: 1 },
                can: {
                    requestInventory: true,
                    requestInventoryAdjustment: true,
                    requestReturns: true,
                    approveInventory: true,
                    approveReturns: true,
                    reorderInventory: true,
                },
            },
        },
    };
    const row = (id, status = 'pending', requester = 2) => ({
        id,
        status,
        requester_id: requester,
        reason: 'Existing explanation',
        product_name: 'Memory module',
        requester_name: 'Staff',
        quantity: 1,
        initial_reorder_level: null,
        target_quantity: 12,
        observed_quantity: 10,
    });
    const product = (id, inventory = true) => ({
        id,
        name: 'Memory module',
        product_code: `RAM-${id}`,
        category: { name: 'Memory' },
        inventory: inventory
            ? { id, quantity: 10, quarantined_quantity: 0, reorder_level: 2 }
            : null,
    });
    const props = Vue.reactive({
        filters: { category_id: null, stock: 'all' },
        filter_options: { categories: [] },
        products:
            pageName === 'Inventory'
                ? {
                      data: [product(1), product(2), product(3, false)],
                      total: 3,
                      last_page: 1,
                  }
                : [],
        requests: {
            data: [
                row(1, 'withdrawn', 1),
                row(2, 'withdrawn', 1),
                row(3),
                {
                    ...row(4, 'withdrawn', 1),
                    initial_reorder_level: 2,
                },
            ],
            last_page: 1,
        },
        reports: { data: [row(1)], last_page: 1 },
        returns: { data: [row(1)], last_page: 1 },
        suppliers: [],
        evidence: {},
        paymentEvidence: {},
        refundPayments: {},
        selected_order: { id: 1, reference: 'BF-000001', items: [] },
        can_receive_returns: true,
        low_stock_count: 0,
        out_of_stock_count: 0,
        ...pageProps,
    });
    const primitive = {
        render() {
            return Vue.h(
                'div',
                this.$attrs,
                this.$slots.default?.({ reveal() {} }),
            );
        },
    };
    const input = {
        render() {
            return Vue.h('input', this.$attrs);
        },
    };
    const form = {
        props: ['action', 'method'],
        render() {
            const node = Vue.h(
                'form',
                { action: this.action, method: this.method },
                this.$slots.default?.({ errors: {}, processing: false }),
            );
            forms.set(this.action, node);
            return node;
        },
    };
    const generic = new Proxy(
        { default: primitive },
        { get: (target, key) => target[key] ?? primitive },
    );
    const modules = new Proxy(
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Form: form,
                Head: primitive,
                Link: primitive,
                usePage: () => page,
                useForm(initial) {
                    return Vue.reactive({
                        ...initial,
                        errors: {},
                        processing: false,
                        reset() {},
                        clearErrors() {},
                        submit() {},
                    });
                },
                router: {},
            },
            '@/components/ui/input': { Input: input },
            '@/composables/useDebouncedSearch': {
                useDebouncedSearch: () => ({
                    search: Vue.ref(''),
                    isSearching: Vue.ref(false),
                    clearSearch() {},
                    cancelPendingSearch() {},
                }),
            },
            '@/lib/dateTime': {
                formatRelativeTime,
                formatDateTime: () => 'Today',
            },
        },
        {
            get(target, key) {
                if (key.startsWith('@/actions/')) {
                    const controller = key.split('/').at(-1);
                    return {
                        default: new Proxy(
                            {},
                            {
                                get(_, method) {
                                    const url = (id = '') =>
                                        `/${controller}/${method}/${typeof id === 'object' ? id.subjectId : id}`;
                                    const action = (id) => ({
                                        url: url(id),
                                        method: 'post',
                                    });
                                    action.form = (id) => ({
                                        action: url(id),
                                        method: 'post',
                                    });
                                    return action;
                                },
                            },
                        ),
                    };
                }
                return target[key] ?? generic;
            },
        },
    );
    const component = compileSkeletonTestComponent(
        readFileSync(new URL(`./${pageName}.vue`, import.meta.url), 'utf8'),
        modules,
    );
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    return {
        async html() {
            forms.clear();
            return renderToString(
                Vue.h({ render: () => render({ $page: page }, []) }),
            );
        },
        form(controller, method, id = '') {
            const node = forms.get(`/${controller}Controller/${method}/${id}`);
            assert.ok(node, `Missing ${controller}.${method} form ${id}`);
            return node;
        },
    };
}

function field(form, name) {
    const node = findNode(form, (node) => node.props?.name === name);
    assert.ok(node, `Missing ${name} field`);
    return node;
}

function selectReason(form, value) {
    const select = field(form, 'reason_code');
    if (select.props.onChange) select.props.onChange({ target: { value } });
    else select.props['onUpdate:modelValue'](value);
}

function assertNotesAreOptional(form) {
    assert.notEqual(field(form, 'reason').props.required, true);
}

const requestForms = [
    ['Inventory', 'Inventory', 'update', 1, 'count_error'],
    ['Inventory', 'Inventory', 'store', 3, 'receiving_error'],
    [
        'InventoryAdjustments',
        'InventoryAdjustment',
        'replace',
        1,
        'count_error',
    ],
    ['DamageReports', 'DamageReport', 'store', '', 'handling_damage'],
    ['SupplierReturns', 'SupplierReturn', 'store', '', 'wrong_item'],
    ['CustomerReturns', 'CustomerReturn', 'store', '', 'defective'],
];

for (const [pageName, propName] of [
    ['DamageReports', 'reports'],
    ['SupplierReturns', 'returns'],
    ['WorkQueue', 'entries'],
]) {
    test(`${pageName} shows timestamp-based relative ages`, async (t) => {
        t.mock.timers.enable({
            apis: ['Date'],
            now: Date.parse('2026-11-02T08:02:00Z'),
        });
        const app = harness(t, pageName, {
            [propName]: {
                data: [
                    {
                        id: 1,
                        kind: 'damage',
                        status: 'pending',
                        created_at: '2026-11-02T08:00:00Z',
                        age_days: 0,
                    },
                ],
                last_page: 1,
                total: 1,
            },
        });

        const html = await app.html();

        assert.match(html, /2 minutes ago/);
        assert.doesNotMatch(html, /Less than a day old|0 days open/);
    });
}

for (const [pageName, controller, method, id, reasonCode] of requestForms) {
    test(`${pageName} ${method} requires notes only for Other`, async (t) => {
        const app = harness(t, pageName);
        let html = await app.html();
        const current = () => app.form(controller, method, id);
        assert.ok(field(current(), 'reason_code').props.required !== undefined);
        assertNotesAreOptional(current());
        assert.match(
            html,
            pageName === 'CustomerReturns'
                ? /Notes \(optional\)/
                : /Additional notes \(optional\)/,
        );

        selectReason(current(), 'other');
        html = await app.html();
        assert.equal(field(current(), 'reason').props.required, true);
        assert.equal(field(current(), 'reason').props.minlength, '5');
        assert.equal(field(current(), 'reason').props.maxlength, '2000');
        assert.match(
            html,
            pageName === 'CustomerReturns'
                ? /Notes \(required\)/
                : /Additional notes \(required\)/,
        );

        selectReason(current(), reasonCode);
        html = await app.html();
        assertNotesAreOptional(current());
        assert.equal(field(current(), 'reason').props.minlength, '5');
        assert.equal(field(current(), 'reason').props.maxlength, '2000');
        assert.doesNotMatch(
            html,
            pageName === 'CustomerReturns'
                ? /Notes \(required\)/
                : /Additional notes \(required\)/,
        );
    });
}

for (const [pageName, controller, method] of [
    ['Inventory', 'Inventory', 'update'],
    ['InventoryAdjustments', 'InventoryAdjustment', 'replace'],
]) {
    test(`${pageName} isolates notes requirements between records`, async (t) => {
        const app = harness(t, pageName);
        await app.html();
        selectReason(app.form(controller, method, 1), 'other');
        await app.html();
        assert.equal(
            field(app.form(controller, method, 1), 'reason').props.required,
            true,
        );
        assertNotesAreOptional(app.form(controller, method, 2));
        if (pageName === 'Inventory') {
            assertNotesAreOptional(app.form(controller, 'store', 3));
        }
    });
}

test('stock request filters are hidden from staff', async (t) => {
    const app = harness(t, 'InventoryAdjustments');

    const html = await app.html();

    assert.doesNotMatch(html, /aria-label="Stock request filters"/);
});

test('stock request reviewers see three filters with the selected view marked', async (t) => {
    const app = harness(t, 'InventoryAdjustments', {
        can_view_all_requests: true,
        filters: { view: 'mine' },
    });

    const html = await app.html();

    assert.match(html, /aria-label="Stock request filters"/);
    assert.match(html, />To Review</);
    assert.match(html, /aria-current="page"[^>]*>My Requests</);
    assert.match(html, />All</);
});

test('pending stock requests show Awaiting approval while withdrawn statuses stay unchanged', async (t) => {
    const app = harness(t, 'InventoryAdjustments');

    const html = await app.html();

    assert.equal((html.match(/>Awaiting approval<\/span>/g) ?? []).length, 1);
    assert.equal((html.match(/>withdrawn<\/span>/g) ?? []).length, 3);
    assert.doesNotMatch(html, />pending<\/span>/);
});

test('opening-stock replacement shows the Initial receipt label for its existing reason code', async (t) => {
    const app = harness(t, 'InventoryAdjustments');
    await app.html();
    const form = app.form('InventoryAdjustment', 'replace', 4);
    const option = findNode(
        form,
        (node) =>
            node.type === 'option' && node.props?.value === 'receiving_error',
    );
    assert.equal(option.children.trim(), 'Initial receipt');
    selectReason(form, 'receiving_error');
    await app.html();
    assertNotesAreOptional(app.form('InventoryAdjustment', 'replace', 4));
});

for (const [pageName, controller, method, id] of [
    ['Inventory', 'Inventory', 'updateReorderLevel', 1],
    ['InventoryAdjustments', 'InventoryAdjustment', 'decide', 3],
    ['DamageReports', 'DamageReport', 'decide', 1],
    ['SupplierReturns', 'SupplierReturn', 'decide', 1],
    ['CustomerReturns', 'CustomerReturn', 'decide', 1],
]) {
    test(`${pageName} keeps review and exceptional notes required`, async (t) => {
        const app = harness(t, pageName);
        await app.html();
        const notes = field(app.form(controller, method, id), 'reason');
        assert.notEqual(notes.props.required, undefined);
        assert.notEqual(notes.props.required, false);
        assert.equal(notes.props.minlength, '5');
        assert.equal(notes.props.maxlength, '2000');
    });
}

test('supplier selection narrows available products to saved links and resets the product picker', async (t) => {
    const products = [
        { id: 10, product_code: '10035', name: 'Memory', available_quantity: 5 },
        { id: 20, product_code: '40705128', name: 'Keyboard', available_quantity: 4 },
    ];
    const app = harness(t, 'SupplierReturns', {
        products,
        suppliers: [{ id: 1, name: 'Components' }, { id: 2, name: 'Peripherals' }],
        supplier_product_links: [{ supplier_id: 1, product_id: 10 }, { supplier_id: 2, product_id: 20 }],
    });
    await app.html();
    const form = () => app.form('SupplierReturn', 'store');
    const picker = () => findNode(form(), (node) => node.props?.id === 'return-product');
    assert.equal(picker().props.disabled, true);
    assert.deepEqual(picker().props.products, []);

    field(form(), 'supplier_id').props['onUpdate:modelValue']('1');
    await app.html();
    assert.equal(picker().props.disabled, false);
    assert.deepEqual(picker().props.products.map((product) => product.id), [10]);
    const oldPickerKey = picker().key;

    field(form(), 'supplier_id').props['onUpdate:modelValue']('2');
    await app.html();
    assert.deepEqual(picker().props.products.map((product) => product.id), [20]);
    assert.notEqual(picker().key, oldPickerKey);
});

test('supplier picker keeps zero-stock assignments empty and preserves manual selection for unassigned suppliers', async (t) => {
    const app = harness(t, 'SupplierReturns', {
        products: [{ id: 20, name: 'Available unrelated product', product_code: 'OTHER', available_quantity: 4 }],
        suppliers: [{ id: 1, name: 'Out of stock' }, { id: 2, name: 'Manual' }],
        supplier_product_links: [{ supplier_id: 1, product_id: 10 }],
    });
    await app.html();
    const form = () => app.form('SupplierReturn', 'store');
    const picker = () => findNode(form(), (node) => node.props?.id === 'return-product');

    field(form(), 'supplier_id').props['onUpdate:modelValue']('1');
    await app.html();
    assert.deepEqual(picker().props.products, []);
    assert.equal(picker().props['empty-message'], 'No linked products have available stock.');

    field(form(), 'supplier_id').props['onUpdate:modelValue']('2');
    await app.html();
    assert.deepEqual(picker().props.products.map((product) => product.id), [20]);
});
