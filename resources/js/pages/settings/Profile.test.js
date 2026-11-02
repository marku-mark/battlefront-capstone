import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import {
    deliveryLocations,
    emptyDeliveryAddress,
    findAddressNode,
    loadAddressComponent,
} from '../../lib/deliveryAddressTestHelpers.js';

function profileProps(overrides = {}) {
    return {
        emailVerified: true,
        administrationRoleCode: null,
        canManageDefaultDeliveryAddress: true,
        defaultDeliveryContactNumber: '',
        defaultDeliveryAddress: 'Saved legacy address <script>unsafe</script>',
        deliveryLocations,
        defaultDeliveryAddressPrefill: emptyDeliveryAddress(),
        searchRecommendationsEnabled: true,
        productViewRecommendationsEnabled: true,
        personalizedRecommendationsEnabled: true,
        ...overrides,
    };
}

for (const [role, administrationRoleCode, label] of [
    ['customer', null, 'Customer'],
    ['administrator', 'owner', 'Owner'],
    ['administrator', 'manager', 'Manager'],
    ['administrator', 'staff', 'Staff'],
    ['administrator', null, 'Administrator'],
]) {
    test(`profile displays the assigned ${label} role as read-only account information`, async () => {
        const html = await renderToString(
            Vue.h(
                loadAddressComponent('../pages/settings/Profile.vue', true, {
                    user: {
                        name: 'Profile User',
                        email: 'profile@example.test',
                        role,
                    },
                }),
                profileProps({ administrationRoleCode }),
            ),
        );

        assert.match(
            html,
            new RegExp(`<dt[^>]*>Account Role:</dt>\\s*<dd[^>]*>${label}</dd>`),
        );
        assert.doesNotMatch(
            html,
            /<(?:input|select)[^>]*name="(?:role|administration_role_id|email_verified_at|emailVerified)"/,
        );
    });
}

for (const emailVerified of [true, false]) {
    test(`profile displays email verification as ${emailVerified ? 'Yes' : 'No'}`, async () => {
        const html = await renderToString(
            Vue.h(
                loadAddressComponent('../pages/settings/Profile.vue', true),
                profileProps({ emailVerified }),
            ),
        );

        assert.match(
            html,
            new RegExp(
                `<dt[^>]*>Email Verified:</dt>\\s*<dd[^>]*>\\s*${emailVerified ? 'Yes' : 'No'}\\s*</dd>`,
            ),
        );
    });
}

test('ambiguous defaults start in keep mode and address edits or clearing set explicit save intent', () => {
    const props = Vue.reactive(
        profileProps({
            defaultDeliveryAddressPrefill: {
                ...emptyDeliveryAddress(),
                delivery_destination: 'Sagay City',
            },
        }),
    );
    const page = loadAddressComponent('../pages/settings/Profile.vue').setup(
        props,
        { expose() {} },
    );
    assert.equal(page.defaultAddressAction.value, 'keep');
    assert.equal(page.defaultAddress.value.delivery_address_details, '');
    page.updateDefaultAddress({
        delivery_destination: 'Sagay City',
        delivery_barangay_code: '1804523018',
        delivery_address_details: '12 Mabini Street',
    });
    assert.equal(page.defaultAddressAction.value, 'save');
    assert.equal(
        props.defaultDeliveryAddress,
        'Saved legacy address <script>unsafe</script>',
    );
    page.clearDefaultAddress();
    assert.equal(page.defaultAddressAction.value, 'clear');
    assert.deepEqual(page.defaultAddress.value, emptyDeliveryAddress());
    props.defaultDeliveryAddress = null;
    props.defaultDeliveryAddressPrefill = emptyDeliveryAddress();
    page.profileSaved();
    assert.equal(page.defaultAddressAction.value, 'keep');
    assert.equal(page.hasAddress.value, false);
});

test('profile shows optional shared fields and keeps name and recommendation controls', async () => {
    const component = loadAddressComponent(
        '../pages/settings/Profile.vue',
        true,
    );
    const html = await renderToString(Vue.h(component, profileProps()));
    assert.match(html, /Default delivery address \(optional\)/);
    assert.match(html, /name="default_delivery_contact_number"/);
    assert.match(html, /name="default_delivery_address_action" value="keep"/);
    assert.match(html, /name="default_delivery_destination"/);
    assert.match(html, /name="default_delivery_barangay_code"/);
    assert.match(html, /name="default_delivery_address_details"/);
    assert.doesNotMatch(html, /<textarea[^>]*\srequired/);
    assert.doesNotMatch(html, /name="default_delivery_address"/);
    assert.match(html, /name="name"/);
    assert.match(html, /name="email"/);
    assert.match(html, /name="personalized_recommendations_enabled"/);
    assert.match(html, /name="search_recommendations_enabled"/);
    assert.match(html, /name="product_view_recommendations_enabled"/);
    assert.match(html, /Clear saved address/);
    assert.ok(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'));
});

for (const canManageDefaultDeliveryAddress of [true, false]) {
    test(`profile email is read-only with an accessible explanation for ${canManageDefaultDeliveryAddress ? 'customers' : 'administrators'}`, async () => {
        let form;
        const html = await renderToString(
            Vue.h(
                loadAddressComponent('../pages/settings/Profile.vue', true, {
                    user: {
                        name: 'Profile User',
                        email: 'profile@example.test',
                    },
                    captureForm: (node) => {
                        form = node;
                    },
                }),
                profileProps({ canManageDefaultDeliveryAddress }),
            ),
        );
        const email = findAddressNode(
            form,
            (node) => node.props?.name === 'email',
        );
        const name = findAddressNode(
            form,
            (node) => node.props?.name === 'name',
        );

        assert.equal(email.props['default-value'], 'profile@example.test');
        assert.ok(Object.hasOwn(email.props, 'readonly'));
        assert.equal(email.props['aria-describedby'], 'email-description');
        assert.equal(name.props['default-value'], 'Profile User');
        assert.equal(Object.hasOwn(name.props, 'readonly'), false);
        assert.equal(Object.hasOwn(name.props, 'disabled'), false);
        assert.match(html, /id="email-description"/);
        assert.match(html, /Your account email address cannot be changed\./);
    });
}

test('editing a profile address requires details and clearing remains pending until Save succeeds', async () => {
    const scope = Vue.effectScope();
    try {
        let form;
        const render = scope.run(() =>
            loadAddressComponent('../pages/settings/Profile.vue', true, {
                captureForm: (node) => {
                    form = node;
                },
            }).setup(profileProps(), { expose() {} }),
        );
        const htmlForCurrentState = () =>
            renderToString(
                Vue.h({
                    render: () => render({}, []),
                }),
            );
        await htmlForCurrentState();
        const fields = findAddressNode(
            form,
            (node) => node.props?.['name-prefix'] === 'default_delivery',
        );
        fields.props['onUpdate:modelValue']({
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        });
        let html = await htmlForCurrentState();
        assert.match(
            html,
            /name="default_delivery_address_action" value="save"/,
        );
        assert.match(html, /<textarea[^>]*\srequired/);
        findAddressNode(
            form,
            (node) => node.props?.type === 'button' && node.props?.onClick,
        ).props.onClick();
        html = await htmlForCurrentState();
        assert.match(
            html,
            /name="default_delivery_address_action" value="clear"/,
        );
        assert.match(
            html,
            /Your saved address will be cleared when you save\./,
        );
        assert.doesNotMatch(html, /<textarea[^>]*\srequired/);
    } finally {
        scope.stop();
    }
});

test('administrator profile excludes address and recommendation controls', async () => {
    const html = await renderToString(
        Vue.h(
            loadAddressComponent('../pages/settings/Profile.vue', true),
            profileProps({ canManageDefaultDeliveryAddress: false }),
        ),
    );
    assert.doesNotMatch(html, /name="default_delivery_/);
    assert.doesNotMatch(html, /Clear saved address/);
    assert.doesNotMatch(html, /name="personalized_recommendations_enabled"/);
    assert.match(html, /name="name"/);
    assert.match(html, /name="email"/);
});
