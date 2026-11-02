import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { pageHeaderModule } from '../../components/pageHeaderTestHelpers.js';
import { findAddressNode } from '../../lib/deliveryAddressTestHelpers.js';
import { compileSkeletonTestComponent } from '../../lib/skeletonTestHelpers.js';

const staffPermissions = [
    'orders.view',
    'orders.process',
    'inventory.view',
    'inventory.request',
    'returns.request',
    'returns.record_payment',
    'returns.dispatch',
];

function teamHarness(t, overrides = {}) {
    const props = Vue.reactive({
        users: { data: [], last_page: 1 },
        filters: {},
        availableRoles: ['staff', 'manager', 'owner'],
        permissions: [
            ...staffPermissions,
            'orders.assign',
            'inventory.approve',
            'inventory.reorder',
            'products.manage',
            'products.price',
            'products.cost',
            'catalog.delete',
            'customers.view',
            'reports.view',
            'reports.export',
            'reports.profit',
            'forecast.manage',
            'returns.approve',
            'returns.resolve',
            'returns.reconcile',
            'suppliers.manage',
            'branches.manage',
            'staff_reports.view',
            'audit.view',
            'users.manage',
            'permissions.manage',
            'audit.export',
        ].map((code) => ({ code, name: code })),
        grants: [
            { role: 'staff', permission: 'orders.view' },
            { role: 'staff', permission: 'products.price' },
            { role: 'manager', permission: 'orders.view' },
            { role: 'manager', permission: 'products.price' },
        ],
        defaultPermissions: {
            staff: [...staffPermissions],
            manager: ['orders.view', 'inventory.view', 'orders.assign'],
        },
        staffAllowedPermissions: [...staffPermissions],
        roleVersions: { staff: 3, manager: 8 },
        canManagePermissions: true,
        ...overrides,
    });
    const visits = [];
    const forms = new Map();
    const formComponent = {
        props: ['action', 'method'],
        render() {
            const form = Vue.h(
                'form',
                { action: this.action, method: this.method },
                this.$slots.default?.({ errors: {}, processing: false }),
            );
            forms.set(this.action, form);
            return form;
        },
    };
    const button = {
        render() {
            return Vue.h('button', this.$attrs, this.$slots.default?.());
        },
    };
    const primitive = {
        render() {
            return Vue.h('div', this.$slots.default?.());
        },
    };
    const component = compileSkeletonTestComponent(
        readFileSync(new URL('./Team.vue', import.meta.url), 'utf8'),
        {
            vue: Vue,
            '@inertiajs/vue3': {
                Form: formComponent,
                Head: primitive,
                Link: primitive,
                router: { get: (...args) => visits.push(args) },
            },
            '@/actions/App/Http/Controllers/Administration/TeamController': {
                default: {
                    index: () => ({
                        url: '/administration/team',
                        method: 'get',
                    }),
                    store: {
                        form: () => ({
                            action: '/administration/team',
                            method: 'post',
                        }),
                    },
                    update: {
                        form: () => ({
                            action: '/administration/team/member',
                            method: 'patch',
                        }),
                    },
                },
            },
            '@/actions/App/Http/Controllers/Administration/RolePermissionController':
                {
                    default: {
                        update: {
                            form: (role) => ({
                                action: `/administration/team/roles/${role}/permissions`,
                                method: 'put',
                            }),
                        },
                    },
                },
            '@/actions/App/Http/Controllers/Administration/OrderController': {
                default: {},
            },
            '@/actions/App/Http/Controllers/Administration/WorkQueueController':
                { default: {} },
            '@/components/PageHeader.vue': pageHeaderModule,
            '@/components/administration/InlineEditor.vue': {
                default: primitive,
            },
            '@/components/administration/TeamEmailVerification.vue': {
                default: primitive,
            },
            '@/components/InputError.vue': { default: primitive },
            '@/components/ui/button': { Button: button },
            '@/components/ui/input': { Input: primitive },
            '@/components/ui/label': { Label: primitive },
        },
    );
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const render = scope.run(() => component.setup(props, { expose() {} }));
    return {
        props,
        visits,
        html: () => renderToString(Vue.h({ render: () => render({}, []) })),
        form: (role) =>
            forms.get(`/administration/team/roles/${role}/permissions`),
    };
}

function selectedPermissions(html, role) {
    const form = html.match(
        new RegExp(
            `<form[^>]*action="/administration/team/roles/${role}/permissions"[^>]*>([\\s\\S]*?)</form>`,
        ),
    )?.[1];
    assert.ok(form, `${role} permission form is rendered`);
    return [...form.matchAll(/<input\b[^>]*>/g)]
        .map(([input]) => input)
        .filter(
            (input) =>
                input.includes('name="permissions[]"') &&
                /\schecked(?:[\s=>])/.test(input),
        )
        .map((input) => input.match(/value="([^"]+)"/)[1])
        .sort();
}

function defaultsButton(harness, role) {
    return findAddressNode(
        harness.form(role),
        (node) =>
            node.props?.['aria-label'] ===
            `Use default permissions for ${role === 'staff' ? 'Staff' : 'Manager'}`,
    );
}

test('saved eligible grants load and each editable role has its own defaults button', async (t) => {
    const harness = teamHarness(t);
    const html = await harness.html();

    assert.deepEqual(selectedPermissions(html, 'staff'), ['orders.view']);
    assert.deepEqual(selectedPermissions(html, 'manager'), [
        'orders.view',
        'products.price',
    ]);
    assert.match(html, /Changes affect all accounts assigned to that role/);
    assert.match(html, /only applied when you click Save permissions/);
    for (const role of ['staff', 'manager']) {
        assert.equal(defaultsButton(harness, role).props.type, 'button');
    }
    assert.equal(harness.form('owner'), undefined);
    assert.equal(harness.form('it'), undefined);
    assert.deepEqual(harness.visits, []);
});

test('default selections change only the selected role and require Save permissions', async (t) => {
    const harness = teamHarness(t);
    await harness.html();
    defaultsButton(harness, 'staff').props.onClick();
    let html = await harness.html();

    assert.deepEqual(
        selectedPermissions(html, 'staff'),
        [...staffPermissions].sort(),
    );
    assert.deepEqual(selectedPermissions(html, 'manager'), [
        'orders.view',
        'products.price',
    ]);
    defaultsButton(harness, 'manager').props.onClick();
    html = await harness.html();
    assert.deepEqual(selectedPermissions(html, 'manager'), [
        'inventory.view',
        'orders.assign',
        'orders.view',
    ]);
    assert.deepEqual(harness.props.defaultPermissions.staff, staffPermissions);
    assert.deepEqual(harness.visits, []);
    assert.match(html, /type="submit"[^>]*>Save permissions/);
    assert.match(html, /name="edit_version" value="3"/);
    assert.match(html, /name="edit_version" value="8"/);
});

test('default checkbox selections remain manually customizable', async (t) => {
    const harness = teamHarness(t);
    await harness.html();
    defaultsButton(harness, 'staff').props.onClick();
    await harness.html();
    const checkbox = findAddressNode(
        harness.form('staff'),
        (node) => node.props?.value === 'returns.dispatch',
    );
    checkbox.props['onUpdate:modelValue']([
        'inventory.view',
        'returns.dispatch',
    ]);
    const html = await harness.html();

    assert.deepEqual(selectedPermissions(html, 'staff'), [
        'inventory.view',
        'returns.dispatch',
    ]);
    assert.deepEqual(harness.props.defaultPermissions.staff, staffPermissions);
    assert.deepEqual(harness.visits, []);
});

test('unrelated prop refreshes preserve drafts while newer role versions reload current grants', async (t) => {
    const harness = teamHarness(t);
    await harness.html();
    defaultsButton(harness, 'staff').props.onClick();
    harness.props.roleVersions = { staff: 3, manager: 8 };
    await Vue.nextTick();
    let html = await harness.html();
    assert.deepEqual(
        selectedPermissions(html, 'staff'),
        [...staffPermissions].sort(),
    );

    harness.props.grants = [
        { role: 'staff', permission: 'inventory.view' },
        { role: 'staff', permission: 'products.price' },
    ];
    harness.props.roleVersions = { staff: 4, manager: 8 };
    await Vue.nextTick();
    html = await harness.html();
    assert.deepEqual(selectedPermissions(html, 'staff'), ['inventory.view']);
    assert.match(html, /name="edit_version" value="4"/);
    assert.deepEqual(harness.visits, []);
});

test('non-owners have no role access forms or default selection controls', async (t) => {
    const harness = teamHarness(t, {
        canManagePermissions: false,
        defaultPermissions: {},
    });
    const html = await harness.html();

    assert.doesNotMatch(
        html,
        /Role access|Use default permissions|Save permissions/,
    );
    assert.equal(harness.form('staff'), undefined);
    assert.equal(harness.form('manager'), undefined);
});

test('Staff sees exactly operational choices while Manager retains management choices and clear labels', async (t) => {
    const harness = teamHarness(t);
    const html = await harness.html();
    const formHtml = (role) =>
        html.match(
            new RegExp(
                `<form[^>]*action="/administration/team/roles/${role}/permissions"[^>]*>([\\s\\S]*?)</form>`,
            ),
        )[1];
    const choices = (role) =>
        [...formHtml(role).matchAll(/name="permissions\[\]" value="([^"]+)"/g)]
            .map((match) => match[1])
            .sort();

    assert.deepEqual(choices('staff'), [...staffPermissions].sort());
    assert.deepEqual(
        choices('manager'),
        harness.props.permissions
            .map((permission) => permission.code)
            .filter(
                (code) =>
                    ![
                        'users.manage',
                        'permissions.manage',
                        'audit.export',
                    ].includes(code),
            )
            .sort(),
    );
    assert.match(formHtml('manager'), /Manage branches/);
    assert.match(formHtml('manager'), /Manage acquisition costs/);
});

test('Staff default restoration filters disallowed values without submitting', async (t) => {
    const harness = teamHarness(t, {
        defaultPermissions: {
            staff: [...staffPermissions, 'products.price', 'branches.manage'],
        },
    });
    await harness.html();

    defaultsButton(harness, 'staff').props.onClick();

    assert.deepEqual(
        selectedPermissions(await harness.html(), 'staff'),
        [...staffPermissions].sort(),
    );
    assert.deepEqual(harness.visits, []);
    assert.equal(defaultsButton(harness, 'staff').props.type, 'button');
});
