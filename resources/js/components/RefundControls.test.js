import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { formatRelativeTime, formatDateTime } from '../lib/dateTime.js';

const primitive = {
    render() {
        return Vue.h('div', this.$attrs, this.$slots.default?.());
    },
};
const input = {
    props: ['modelValue', 'defaultValue'],
    render() {
        return Vue.h('input', {
            ...this.$attrs,
            value: this.modelValue ?? this.defaultValue,
        });
    },
};
const modules = {
    vue: Vue,
    '@/components/ui/input': { Input: input },
    '@/components/ui/button': { Button: primitive },
    '@/components/ui/label': { Label: primitive },
    '@/components/InputError.vue': { default: primitive },
    '@/components/FileInput.vue': { default: input },
};
function load(file, inlineTemplate = true, dependencies = {}) {
    const { descriptor } = parse(
        readFileSync(new URL(file, import.meta.url), 'utf8'),
    );
    const script = compileScript(descriptor, { id: file, inlineTemplate });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, symbol, name) =>
                `const ${symbol} = modules[${JSON.stringify(name)}].default;`,
        )
        .replace('export default', 'return');
    return new Function('modules', code)({ ...modules, ...dependencies });
}

test('full refund is prefilled from the server item value with no shipping addition', async () => {
    const html = await renderToString(
        Vue.createSSRApp(load('./RefundAmountFields.vue'), {
            maximum: '2999.90',
            monetary: true,
        }),
    );
    assert.match(html, /name="approved_amount" value="2999\.90"/);
    assert.match(html, /Shipping is not included/);
    assert.match(html, /Partial refund/);
    assert.doesNotMatch(html, /resolution_summary|Partial refund explanation/);
    assert.doesNotMatch(html, /type="number"/);
});

test('partial refund preserves decimal typing and resets to full or zero when its resolution changes', async (t) => {
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const props = Vue.reactive({ maximum: '2999.90', monetary: true });
    const state = scope.run(() =>
        load('./RefundAmountFields.vue', false).setup(props, { expose() {} }),
    );
    state.partial.value = true;
    for (const value of ['1', '1.', '1.2', '1.20', '1499.95']) {
        state.amount.value = value;
        assert.equal(state.approvedAmount.value, value);
    }
    state.partial.value = false;
    assert.equal(state.approvedAmount.value, '2999.90');
    props.monetary = false;
    await Vue.nextTick();
    assert.equal(state.approvedAmount.value, '0.00');
    props.monetary = true;
    await Vue.nextTick();
    assert.equal(state.partial.value, false);
    assert.equal(state.approvedAmount.value, '2999.90');
});

test('clear selection empties the pending file input and resets its selection state', () => {
    const selections = [];
    let cleared = false;
    const state = load('./EvidenceFileInput.vue', false).setup(
        { label: 'Inspection evidence' },
        {
            expose() {},
            emit(event, files) {
                selections.push(files);
            },
        },
    );
    state.input.value = {
        clear() {
            cleared = true;
        },
    };
    state.selected.value = true;
    state.clearSelection();
    assert.equal(cleared, true);
    assert.equal(state.selected.value, false);
    assert.deepEqual(selections, [[]]);
});

function returnsPage(inlineTemplate = true, submissions = []) {
    const route = () => ({ url: '/test' });
    route.form = () => ({ action: '/test', method: 'post' });
    const actions = new Proxy({}, { get: () => route });
    return load('../pages/Administration/CustomerReturns.vue', inlineTemplate, {
        '@inertiajs/vue3': {
            Head: primitive,
            Link: primitive,
            Form: {
                render() {
                    return Vue.h(
                        'form',
                        this.$attrs,
                        this.$slots.default?.({
                            errors: {},
                            processing: false,
                        }),
                    );
                },
            },
            usePage: () => ({
                props: {
                    auth: {
                        user: { id: 9 },
                        can: {
                            viewOrders: true,
                            recordRefundPayments: true,
                            reconcileReturns: true,
                        },
                    },
                },
            }),
            useForm(initial) {
                const form = Vue.reactive({
                    ...initial,
                    errors: {},
                    processing: false,
                    reset() {
                        Object.assign(form, initial);
                    },
                    clearErrors() {
                        form.errors = {};
                    },
                    submit(target, options) {
                        form.processing = true;
                        submissions.push({
                            target,
                            options,
                            files: form.files,
                        });
                    },
                });
                return form;
            },
        },
        '@/actions/App/Http/Controllers/Administration/CustomerReturnController':
            { default: actions },
        '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController':
            { default: actions },
        '@/actions/App/Http/Controllers/Administration/OrderController': {
            default: actions,
        },
        '@/components/RefundAmountFields.vue': {
            default: load('./RefundAmountFields.vue'),
        },
        '@/components/EvidenceFileInput.vue': {
            default: load('./EvidenceFileInput.vue'),
        },
        '@/components/PageHeader.vue': { default: primitive },
        '@/components/administration/OperationalExports.vue': { default: primitive },
        '@/components/SearchField.vue': { default: primitive },
        '@/composables/useDebouncedSearch': {
            useDebouncedSearch: () => ({
                search: Vue.ref(''),
                isSearching: Vue.ref(false),
                clearSearch() {},
            }),
        },
        '@/lib/dateTime': { formatRelativeTime, formatDateTime },
    });
}
async function pageHtml(overrides = {}, customerRequests = []) {
    return renderToString(
        Vue.createSSRApp(returnsPage(), {
            returns: {
                data: [
                    {
                        id: 1,
                        product_name: 'Returned hardware',
                        quantity: 1,
                        status: 'accepted',
                        refund_id: 1,
                        approved_amount: '0.01',
                        verified_paid_amount: '0.00',
                        awaiting_review_amount: '0.01',
                        refund_balance: '0.01',
                        unrecorded_refund_amount: '0.00',
                        can_record_payment: false,
                        created_at: '2026-10-23T06:30:00Z',
                        ...overrides,
                    },
                ],
                last_page: 1,
            },
            customerRequests: { data: customerRequests, links: [] },
            refundPayments: {},
            evidence: {},
            paymentEvidence: {},
            suppliers: [],
            filters: {},
        }),
    );
}

test('fully recorded unverified refund has no second payment form', async () => {
    const html = await pageHtml();
    assert.doesNotMatch(html, /name="amount"/);
    assert.match(
        html,
        /Legacy payments may still need review; do not record them again/,
    );
});

test('eligible approver payment form prefills the unrecorded balance as a decimal string', async () => {
    const html = await pageHtml({
        unrecorded_refund_amount: '1499.95',
        can_record_payment: true,
        payment_submission_key: 'submission-token',
    });
    assert.match(html, /name="amount"[^>]*type="text"[^>]*value="1499\.95"/);
    assert.match(html, /inputmode="decimal"/);
    assert.match(html, /value="cash"/);
    assert.match(html, /name="submission_key"[^>]*value="submission-token"/);
    assert.doesNotMatch(html, /name="external_reference"/);
    assert.match(html, /Confirm refund payment/);
});

test('new refund proof accepts a single image under its payment field name', async () => {
    const html = await renderToString(
        Vue.createSSRApp(load('./EvidenceFileInput.vue'), {
            label: 'Refund Payment Proof',
            imagesOnly: true,
            multiple: false,
            name: 'proof',
        }),
    );
    assert.match(html, /name="proof"/);
    assert.match(html, /accept="\.jpg,\.jpeg,\.png,\.webp"/);
    assert.doesNotMatch(html, /multiple|\.pdf/);
});

test('other administrators see payment ownership guidance instead of payment controls', async () => {
    const html = await pageHtml({
        unrecorded_refund_amount: '0.01',
        can_record_payment: false,
    });
    assert.doesNotMatch(html, /name="amount"/);
    assert.match(html, /Only the return approver may make and record/);
});

test('Staff receipt submits selected product photos with multipart form data', () => {
    const submissions = [];
    const component = returnsPage(false, submissions);
    const state = component.setup(
        { can_receive_returns: true, filters: {} },
        { expose() {} },
    );
    const request = { id: 7, can_receive: true };
    state.receiveCustomerRequest(request);
    assert.equal(submissions.length, 0);
    state.receiptForm.files = [{ name: 'product.jpg' }];
    state.receiptForm.condition = 'opened';
    state.receiveCustomerRequest(request);
    assert.equal(submissions.length, 1);
    assert.equal(submissions[0].options.forceFormData, true);
    assert.equal(submissions[0].files[0].name, 'product.jpg');
});

test('product-photo selection excludes PDFs while payment proof still supports them', async () => {
    const component = load('./EvidenceFileInput.vue');
    const product = await renderToString(
        Vue.createSSRApp(component, {
            label: 'Product photos',
            imagesOnly: true,
        }),
    );
    const payment = await renderToString(
        Vue.createSSRApp(component, {
            label: 'Refund Payment Proof',
            imagesOnly: false,
        }),
    );
    assert.match(product, /accept="\.jpg,\.jpeg,\.png,\.webp"/);
    assert.doesNotMatch(product, /\.pdf/);
    assert.match(payment, /\.pdf/);
});

test('read-only administrators cannot open receipt controls', () => {
    const submissions = [];
    const state = returnsPage(false, submissions).setup(
        { can_receive_returns: false, filters: {} },
        { expose() {} },
    );
    state.receiveCustomerRequest({ id: 7, can_receive: true });
    assert.equal(state.receiptRequest.value, null);
    assert.equal(submissions.length, 0);
});

test('receipt errors include rejected images beyond the first selected file', () => {
    const state = returnsPage(false).setup(
        { can_receive_returns: true, filters: {} },
        { expose() {} },
    );
    assert.equal(
        state.evidenceError({
            'files.1': 'The second photo exceeds the size limit.',
        }),
        'The second photo exceeds the size limit.',
    );
    assert.equal(
        state.evidenceError({
            files: 'Too many product photos.',
            'files.1': 'Invalid image.',
        }),
        'Too many product photos.',
    );
});

test('compact refund summary keeps confirmed amounts separate from pending records', async () => {
    const html = await pageHtml({
        approved_amount: '200.00',
        verified_paid_amount: '50.00',
        awaiting_review_amount: '75.00',
        disputed_amount: '25.00',
        refund_balance: '150.00',
        unrecorded_refund_amount: '50.00',
    });

    for (const [label, value] of [
        ['Approved', '200.00'],
        ['Confirmed paid', '50.00'],
        ['Awaiting review', '75.00'],
        ['Disputed', '25.00'],
        ['Awaiting confirmation', '150.00'],
        ['Not recorded', '50.00'],
    ]) {
        assert.match(
            html,
            new RegExp(
                `<dt[^>]*>\\s*${label}\\s*</dt><dd[^>]*>\\s*₱${value.replace('.', '\\.')}\\s*</dd>`,
            ),
        );
    }
    assert.doesNotMatch(html, /name="amount"/);
    assert.match(html, /Only the return approver/);
});

test('new returns show a precise relative age without a customer resolution summary input', async (t) => {
    t.mock.timers.enable({
        apis: ['Date'],
        now: Date.parse('2026-10-23T06:32:00Z'),
    });

    const html = await pageHtml({ age_days: 0, status: 'pending' });

    assert.match(html, /2 minutes ago/);
    assert.match(html, /title="Oct 23, 2026, 2:30 PM"/);
    assert.doesNotMatch(html, /0 days old|name="resolution_summary"/);
});

test('pending return requests show relative submission time with the exact timestamp available', async (t) => {
    t.mock.timers.enable({
        apis: ['Date'],
        now: Date.parse('2026-10-23T06:30:45Z'),
    });

    const html = await pageHtml({}, [
        {
            id: 2,
            order_id: 1,
            product_name: 'Returned hardware',
            quantity: 1,
            reason_label: 'Defective',
            created_at: '2026-10-23T06:30:00Z',
        },
    ]);

    assert.match(
        html,
        /Requested\s*<span title="Oct 23, 2026, 2:30 PM">45 seconds ago<\/span>/,
    );
});
