import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import { useVModel } from '@vueuse/core';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from '../../../lib/skeletonTestHelpers.js';

const input = compileSkeletonTestComponent(
    readFileSync(
        new URL('../../../components/ui/input/Input.vue', import.meta.url),
        'utf8',
    ),
    {
        vue: Vue,
        '@vueuse/core': { useVModel },
        '@/lib/utils': { cn: () => '' },
    },
);

function orderHarness(t, overrides = {}, permissions = {}) {
    const primitive = {
        render() {
            return Vue.h('div', this.$slots.default?.());
        },
    };
    const empty = { render: () => null };
    const button = {
        render() {
            return Vue.h('button', this.$attrs, this.$slots.default?.());
        },
    };
    const dialog = {
        props: ['open'],
        render() {
            return this.open ? Vue.h('div', this.$slots.default?.()) : null;
        },
    };
    const page = {
        props: {
            auth: {
                user: { id: 1 },
                can: {
                    processOrders: true,
                    assignOrders: false,
                    ...permissions,
                },
            },
        },
    };
    const submissions = [];
    const forms = [];
    const props = Vue.reactive({
        order: {
            id: 1,
            reference: 'BF-000001',
            can_process: false,
            assigned_handler_id: 2,
            assigned_handler_name: 'Assigned Staff',
            customer: { name: 'Customer', email: 'customer@example.test' },
            recipient: { name: 'Recipient', contact_number: '09171234567' },
            fulfillment: { value: 'delivery', label: 'Delivery' },
            status: { value: 'processing', label: 'Processing' },
            shipment: {
                status: { value: 'handed_to_lbc', label: 'Handed to LBC' },
                tracking_reference: 'LBC-1',
            },
            shipment_actions: {
                next_status: { value: 'in_transit', label: 'In transit' },
                can_update_reference: true,
                can_update_package_details: true,
            },
            allowed_status_transitions: [
                { value: 'cancelled', label: 'Cancelled' },
            ],
            payment: {
                method: {
                    value: 'gcash',
                    label: 'GCash',
                    requires_proof: true,
                },
                status: { value: 'pending', label: 'Pending' },
                proof_submitted: true,
                proof_available: true,
                rejection_reasons: [],
            },
            items: [],
            total: '100.00',
            total_quantity: 0,
            delivery_fee: '0.00',
            ...overrides,
        },
        handler_options: [
            { id: 1, name: 'Current Supervisor' },
            { id: 2, name: 'Assigned Staff' },
            { id: 3, name: 'Other Staff' },
        ],
    });
    const inertia = {
        Head: empty,
        Link: primitive,
        usePage: () => page,
        useForm: (data) => {
            let defaults = { ...data };
            const form = Vue.reactive({
                ...data,
                errors: {},
                processing: false,
                reset() {
                    Object.assign(form, defaults);
                },
                defaults(values) {
                    defaults = { ...values };
                },
                transform() {
                    return form;
                },
                clearErrors() {
                    form.errors = {};
                },
                submit: (...args) => submissions.push(args),
                post: (...args) => submissions.push(args),
                patch: (...args) => submissions.push(args),
            });
            forms.push(form);
            return form;
        },
    };
    const source = readFileSync(new URL('./Show.vue', import.meta.url), 'utf8');
    const modules = {
        vue: Vue,
        '@inertiajs/vue3': inertia,
        '@/components/ui/input': { Input: input },
    };
    for (const [, name] of source.matchAll(/from ['"]([^'"]+)['"]/g)) {
        if (modules[name]) continue;
        if (name.startsWith('@/actions/')) {
            const route = () => ({ url: '/orders/1', method: 'patch' });
            modules[name] = { default: new Proxy(route, { get: () => route }) };
        } else if (name.startsWith('@/routes/')) {
            const route = () => ({ url: '/orders/1', method: 'patch' });
            modules[name] = {
                store: () => ({ url: '/orders/1', method: 'post' }),
                update: route,
            };
        } else if (name.startsWith('@/lib/')) {
            modules[name] = {
                formatDateTime: () => 'Today',
                formatCurrency: String,
                orderStatusBadgeClass: () => '',
            };
        } else {
            modules[name] = new Proxy(
                {},
                {
                    get: (_, key) =>
                        key === 'Button'
                            ? button
                            : key === 'Dialog'
                              ? dialog
                              : name === '@lucide/vue'
                                ? empty
                                : primitive,
                },
            );
        }
    }
    const component = compileSkeletonTestComponent(source, modules);
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    let tree;
    return {
        props,
        forms,
        submissions,
        html: () =>
            renderToString(Vue.h({ render: () => (tree = render({}, [])) })),
        tree: () => tree,
    };
}

function findButton(node, label, matches = () => true) {
    if (Array.isArray(node)) {
        return node
            .map((child) => findButton(child, label, matches))
            .find(Boolean);
    }
    if (!node || typeof node !== 'object') return null;
    if (node.children?.default) {
        const children = node.children.default();
        if (
            node.type?.render &&
            children.some(
                (child) =>
                    typeof child.children === 'string' &&
                    child.children.includes(label),
            )
        ) {
            if (node.props?.onClick && matches(node)) return node;
        }
        return findButton(children, label, matches);
    }
    return findButton(node.children, label, matches);
}

function findElement(node, type) {
    if (Array.isArray(node)) {
        return node.map((child) => findElement(child, type)).find(Boolean);
    }
    if (!node || typeof node !== 'object') return null;
    if (node.type === type) return node;
    return findElement(node.children?.default?.() ?? node.children, type);
}

test('saved shipment records are read-only until explicitly edited', async (t) => {
    const view = orderHarness(t, {
        can_process: true,
        assigned_handler_id: 1,
        shipment: {
            status: {
                value: 'awaiting_preparation',
                label: 'Awaiting preparation',
            },
            tracking_reference: null,
            operations: {
                package_count: 1,
                package_weight_kg: '1.000',
                package_length_cm: '10.00',
                package_width_cm: '10.00',
                package_height_cm: '10.00',
                declared_value: '100.00',
                handoff_mode: 'branch_dropoff',
            },
        },
    });

    await view.html();
    assert.ok(findButton(view.tree(), 'Edit package details'));
    assert.equal(
        Boolean(findButton(view.tree(), 'Save package details')),
        false,
    );
    assert.equal(findElement(view.tree(), 'fieldset').props.disabled, true);

    findButton(view.tree(), 'Edit package details').props.onClick();
    await view.html();
    assert.ok(findButton(view.tree(), 'Save package details'));
    assert.equal(findElement(view.tree(), 'fieldset').props.disabled, false);

    findButton(view.tree(), 'Save package details').props.onClick();
    view.submissions.at(-1)[1].onSuccess();

    await view.html();
    assert.ok(findButton(view.tree(), 'Edit package details'));
    assert.equal(
        Boolean(findButton(view.tree(), 'Save package details')),
        false,
    );
});

test('delivery arrangement confirmation keeps optional details blank and requires actual arrangement', async (t) => {
    const view = orderHarness(t, {
        can_process: true,
        assigned_handler_id: 1,
        shipment: null,
        can_create_manual_shipment: true,
        delivery_quote: {
            delivery_arrangement_required: true,
            pricing_status: 'not_configured',
            assumption_label: 'Historical delivery pricing remains unchanged.',
        },
    });
    let html = await view.html();
    assert.match(html, /Confirm delivery arrangement/);
    assert.doesNotMatch(html, /Create Manual Shipment/);
    findButton(view.tree(), 'Confirm delivery arrangement').props.onClick();
    html = await view.html();

    const form = view.forms.find(
        (candidate) => 'arrangement_confirmed' in candidate,
    );
    assert.equal(form.carrier, 'lbc');
    assert.equal(form.tracking_reference, '');
    assert.equal(form.eta_min_days, '');
    assert.equal(form.eta_max_days, '');
    assert.equal(form.arrangement_confirmed, false);
    assert.match(
        html,
        /<input[^>]*id="manual-shipment-carrier"[^>]*readonly[^>]*value="LBC"/,
    );
    assert.match(html, /Estimated delivery days \(optional\)/);
    assert.match(html, /Leave both blank when unknown/);
    assert.match(html, /arrangement with LBC has actually been made/);

    const submitButton = () =>
        findButton(
            view.tree(),
            'Confirm delivery arrangement',
            (node) => 'disabled' in node.props,
        );
    assert.equal(submitButton().props.disabled, true);
    submitButton().props.onClick();
    assert.equal(view.submissions.length, 0);
    form.arrangement_confirmed = true;
    await view.html();
    assert.equal(submitButton().props.disabled, false);
    submitButton().props.onClick();
    assert.equal(view.submissions.length, 1);
    assert.equal(form.carrier, 'lbc');
    assert.equal(form.tracking_reference, '');
    assert.equal(form.eta_min_days, '');
    assert.equal(form.eta_max_days, '');

    view.submissions[0][1].onSuccess();
    assert.equal(form.arrangement_confirmed, false);
    assert.equal(form.carrier, 'lbc');
});

test('unconfigured delivery fee remains explicitly unpriced after shipment confirmation', async (t) => {
    const view = orderHarness(t, {
        delivery_quote: {
            delivery_arrangement_required: true,
            pricing_status: 'not_configured',
            assumption_label: 'Historical delivery pricing remains unchanged.',
        },
    });
    assert.match(
        await view.html(),
        /No delivery fee included; this does not guarantee free delivery/,
    );
});

test('other staff can inspect details without processing controls', async (t) => {
    const view = orderHarness(t);
    const html = await view.html();
    assert.match(html, /Handled by Assigned Staff/);
    assert.equal(html.match(/View only/g)?.length, 1);
    assert.doesNotMatch(
        html,
        /Only the assigned handler|Reassign it explicitly/,
    );
    assert.doesNotMatch(
        html,
        /Update order status|Save reference|Mark In transit|Claim order|Assign handler/,
    );
});

test('unassigned staff can claim without assignment controls or extra notices', async (t) => {
    const view = orderHarness(t, {
        assigned_handler_id: null,
        assigned_handler_name: null,
    });
    const html = await view.html();
    assert.match(html, /Unassigned/);
    assert.match(html, /Claim order/);
    assert.doesNotMatch(
        html,
        /Update order status|Save reference|Assign handler|View only|before processing it/,
    );
});

test('supervisors can reassign while processing controls remain restricted', async (t) => {
    const html = await orderHarness(t, {}, { assignOrders: true }).html();
    assert.match(html, /Reassign handler/);
    assert.match(html, /View only/);
    assert.match(html, /<option value="1">Current Supervisor<\/option>/);
    assert.match(html, /<option value="3">Other Staff<\/option>/);
    assert.match(html, /<button[^>]*disabled[^>]*>Reassign handler<\/button>/);
    assert.doesNotMatch(html, /<option value="2">/);
    assert.doesNotMatch(html, /Update order status|Save reference|Claim order/);
});

test('the current handler sees eligible controls and loses submission access after reassignment', async (t) => {
    const view = orderHarness(t, { can_process: true });
    const html = await view.html();
    assert.match(html, /Handled by Assigned Staff/);
    assert.doesNotMatch(html, /View only|You are responsible|Claim order/);
    assert.match(html, /Update order status/);
    assert.match(html, /Save reference/);
    assert.match(html, /Mark In transit/);
    const shipmentButton = findButton(view.tree(), 'Mark');
    assert.ok(shipmentButton);
    shipmentButton.props.onClick();
    assert.equal(view.submissions.length, 1);
    view.props.order.can_process = false;
    shipmentButton.props.onClick();
    assert.equal(view.submissions.length, 1);
    assert.doesNotMatch(
        await view.html(),
        /Save reference|Mark In transit|Update order status/,
    );
});

test('completed orders retain final-handler tracking corrections without claim or reassignment', async (t) => {
    const view = orderHarness(
        t,
        {
            can_process: true,
            status: { value: 'completed', label: 'Completed' },
            allowed_status_transitions: [],
            shipment_actions: { next_status: null, can_update_reference: true },
        },
        { assignOrders: true },
    );
    const html = await view.html();
    assert.match(html, /Save reference/);
    assert.doesNotMatch(
        html,
        /Claim order|Assign handler|Reassign handler|Update order status/,
    );
});

test('unassigned supervisors can claim or assign another eligible handler', async (t) => {
    const view = orderHarness(
        t,
        { assigned_handler_id: null, assigned_handler_name: null },
        { assignOrders: true },
    );
    const html = await view.html();
    assert.match(html, /Claim order/);
    assert.match(html, /Assign handler/);
    assert.match(html, /Select handler/);
    assert.doesNotMatch(html, /Reassign handler|<option value="1">/);
    assert.match(html, /<option value="2">Assigned Staff<\/option>/);
    assert.match(html, /<option value="3">Other Staff<\/option>/);
});

test('assigned supervisors can reassign to others without a redundant self option', async (t) => {
    const view = orderHarness(
        t,
        {
            assigned_handler_id: 1,
            assigned_handler_name: 'Current Supervisor',
            can_process: true,
        },
        { assignOrders: true },
    );
    const html = await view.html();
    assert.match(html, /Reassign handler/);
    assert.doesNotMatch(html, /<option value="1">|Claim order|View only/);
    assert.match(html, /<option value="3">Other Staff<\/option>/);
});

test('reassignment requires an eligible selection and resets after success or handler changes', async (t) => {
    const view = orderHarness(t, {}, { assignOrders: true });
    await view.html();
    const form = findElement(view.tree(), 'form');
    const submit = () => form.props.onSubmit({ preventDefault() {} });
    submit();
    assert.equal(view.submissions.length, 0);
    view.forms[0].handler_id = 2;
    submit();
    assert.equal(view.submissions.length, 0);
    view.forms[0].handler_id = 1;
    view.forms[0].processing = true;
    submit();
    assert.equal(view.submissions.length, 0);
    view.forms[0].processing = false;
    submit();
    assert.equal(view.submissions.length, 1);
    view.submissions[0][1].onSuccess();
    assert.equal(view.forms[0].handler_id, '');
    view.forms[0].handler_id = 3;
    view.props.order.assigned_handler_id = 3;
    await Vue.nextTick();
    assert.equal(view.forms[0].handler_id, '');
    view.forms[0].handler_id = 1;
    view.props.order.id = 4;
    await Vue.nextTick();
    assert.equal(view.forms[0].handler_id, '');
    view.forms[0].handler_id = 1;
    view.props.handler_options = [];
    submit();
    assert.equal(view.submissions.length, 1);
    view.props.handler_options = [{ id: 1, name: 'Current Supervisor' }];
    view.props.order.status = { value: 'completed', label: 'Completed' };
    submit();
    assert.equal(view.submissions.length, 1);
});

test('claiming updates responsibility and processing controls without another action', async (t) => {
    const view = orderHarness(t, {
        assigned_handler_id: null,
        assigned_handler_name: null,
    });
    assert.match(await view.html(), /Claim order/);
    view.props.order.assigned_handler_id = 1;
    view.props.order.assigned_handler_name = 'Current Staff';
    view.props.order.can_process = true;
    const html = await view.html();
    assert.match(html, /Handled by Current Staff/);
    assert.match(html, /Update order status/);
    assert.doesNotMatch(html, /Claim order|View only/);
});

test('supervisors with no other eligible handler can claim without a redundant assignment form', async (t) => {
    const view = orderHarness(
        t,
        { assigned_handler_id: null, assigned_handler_name: null },
        { assignOrders: true },
    );
    view.props.handler_options = [{ id: 1, name: 'Current Supervisor' }];
    const html = await view.html();
    assert.match(html, /Claim order/);
    assert.doesNotMatch(html, /Assign handler|Reassign handler/);
});

test('pending cancellation hides claim and assignment controls and rejection restores them', async (t) => {
    const view = orderHarness(
        t,
        {
            assigned_handler_id: null,
            assigned_handler_name: null,
            cancellation: {
                status: { value: 'requested', label: 'Cancellation requested' },
            },
        },
        { assignOrders: true },
    );
    assert.doesNotMatch(
        await view.html(),
        /Claim order|Assign handler|Reassign handler/,
    );
    view.props.order.cancellation.status.value = 'rejected';
    const html = await view.html();
    assert.match(html, /Claim order/);
    assert.match(html, /Assign handler/);
});

test('stale claim and reassignment callbacks cannot submit after a cancellation hold arrives', async (t) => {
    for (const assigned of [false, true]) {
        const view = orderHarness(
            t,
            {
                assigned_handler_id: assigned ? 2 : null,
                assigned_handler_name: assigned ? 'Assigned Staff' : null,
            },
            { assignOrders: true },
        );
        await view.html();
        const form = findElement(view.tree(), 'form');
        view.forms[0].handler_id = 3;
        view.props.order.cancellation = {
            status: { value: 'requested', label: 'Cancellation requested' },
        };
        form.props.onSubmit({ preventDefault() {} });
        assert.equal(view.submissions.length, 0);
        assert.doesNotMatch(
            await view.html(),
            /Claim order|Assign handler|Reassign handler/,
        );
    }
});

test('cancelled orders hide handler controls even with stale options and selections', async (t) => {
    const view = orderHarness(
        t,
        {
            assigned_handler_id: null,
            status: { value: 'cancelled', label: 'Cancelled' },
        },
        { assignOrders: true },
    );
    const html = await view.html();
    assert.doesNotMatch(html, /Claim order|Assign handler|Reassign handler/);
});

test('users without processing or assignment permissions cannot handle orders', async (t) => {
    const view = orderHarness(
        t,
        { assigned_handler_id: null, assigned_handler_name: null },
        { processOrders: false },
    );
    const html = await view.html();
    assert.doesNotMatch(
        html,
        /Claim order|Assign handler|Reassign handler|Update order status|Save reference/,
    );
});

test('cancellation review retains its processing hold notice without ownership instructions', async (t) => {
    const view = orderHarness(t, {
        cancellation: { status: { value: 'requested' } },
    });
    const html = await view.html();
    assert.equal(
        html.match(/Order handling is paused for cancellation review/g)?.length,
        1,
    );
    assert.doesNotMatch(
        html,
        /Update order status|Save reference|Only the assigned handler/,
    );
});
