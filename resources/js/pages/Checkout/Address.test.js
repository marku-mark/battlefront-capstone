import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import {
    deliveryLocations as locations,
    emptyDeliveryAddress,
    findAddressNode as findNode,
    loadAddressComponent,
} from '../../lib/deliveryAddressTestHelpers.js';

function loadPage(inlineTemplate = false, errors = {}) {
    return loadAddressComponent('../pages/Checkout/Index.vue', inlineTemplate, {
        errors,
    });
}

function checkoutProps(prefill = {}) {
    return {
        cart: {
            items: [
                {
                    id: 11,
                    quantity: 1,
                    product: { name: 'Battlefront Mouse', price: '100.00' },
                    line_total: '100.00',
                },
            ],
            total: '100.00',
            total_quantity: 1,
            item_count: 1,
        },
        customer: {
            name: 'Customer',
            default_delivery_address: 'Saved address <script>unsafe</script>',
        },
        pickupLocation: { name: 'Sagay', address: 'Pickup address' },
        fulfillmentMethods: [
            { value: 'pickup', label: 'Pickup' },
            { value: 'delivery', label: 'Delivery' },
        ],
        paymentMethods: [
            {
                value: 'cash',
                label: 'Cash (Pay at Pickup)',
                description:
                    'Pay cash when collecting your products at the store.',
                available_for: ['pickup'],
                requires_proof: false,
            },
            {
                value: 'card_at_store',
                label: 'Debit/Credit Card (Pay at Store)',
                description:
                    'Pay through a physical debit/credit card terminal during pickup at the store. This is not an online payment gateway.',
                available_for: ['pickup'],
                requires_proof: false,
            },
            {
                value: 'gcash',
                label: 'GCash',
                available_for: ['pickup', 'delivery'],
                requires_proof: true,
                payment_account: {
                    account_name: 'Demo',
                    account_number: '09XX',
                    is_demo: true,
                },
            },
            {
                value: 'cod',
                label: 'Cash on Delivery (COD)',
                available_for: ['delivery'],
                requires_proof: false,
                payment_account: null,
            },
        ],
        paymongoHostedMethods: ['card', 'gcash', 'paymaya', 'qrph'],
        pickupQuote: {
            product_subtotal: '100.00',
            delivery_fee: '0.00',
            total: '100.00',
        },
        deliveryQuotes: locations.map((location) => ({
            destination: location.destination,
            product_subtotal: '100.00',
            delivery_fee: '80.00',
            total: '180.00',
            carrier: 'lbc',
            measurement_sources: { measured: 0, estimated: 1 },
            base_fee: '80.00',
            handling_surcharge: '0.00',
            preparation_days: 1,
            packing_expectation: 'Standard packing',
            is_demo: true,
            assumption_label:
                'Battlefront-configured demonstration estimates, not official LBC quotations.',
            notice: 'Battlefront estimates, not live LBC quotations or tracking.',
            estimated_delivery_start: '2026-10-13',
            estimated_delivery_end: '2026-10-14',
        })),
        deliveryLocations: locations,
        deliveryAddressPrefill: { ...emptyDeliveryAddress(), ...prefill },
    };
}

test('nationwide quotes discard stale responses and use server payment eligibility', async () => {
    const scope = Vue.effectScope();
    const originalFetch = globalThis.fetch;
    const pending = [];
    globalThis.fetch = (url) =>
        new Promise((resolve) => pending.push({ url, resolve }));
    const settle = async () => {
        await new Promise((resolve) => setImmediate(resolve));
        await Vue.nextTick();
    };
    try {
        const page = scope.run(() =>
            loadPage().setup(checkoutProps(), { expose() {} }),
        );
        page.chooseFulfillment('delivery');
        page.deliveryAddress.value.delivery_destination = '1804523000';
        await Vue.nextTick();
        assert.equal(page.quoteLoading.value, true);
        assert.equal(page.selectedQuote.value, null);
        page.deliveryAddress.value.delivery_destination = '1380300000';
        await Vue.nextTick();
        pending[1].resolve({
            ok: true,
            json: async () => ({
                data: {
                    available: false,
                    quote: null,
                    notice: 'Delivery is unavailable for this location.',
                },
            }),
        });
        await settle();
        pending[0].resolve({
            ok: true,
            json: async () => ({
                data: {
                    available: true,
                    quote: { destination: '1804523000', total: '180.00' },
                },
            }),
        });
        await settle();
        assert.equal(page.selectedQuote.value, null);
        assert.equal(
            page.quoteNotice.value,
            'Delivery is unavailable for this location.',
        );
        assert.equal(page.quoteError.value, '');
        page.deliveryAddress.value.delivery_destination = '1804523000';
        await Vue.nextTick();
        pending[2].resolve({
            ok: true,
            json: async () => ({
                data: {
                    available: true,
                    quote: {
                        destination: '1804523000',
                        total: '180.00',
                        payment_methods: ['cod'],
                    },
                },
            }),
        });
        await settle();
        assert.equal(page.selectedQuote.value.total, '180.00');
        assert.deepEqual(
            page.availablePaymentMethods.value.map((method) => method.value),
            ['cod'],
        );
        assert.equal(page.paymentMethod.value, 'cod');
        assert.equal(page.requiresPaymentProof.value, false);
        page.chooseFulfillment('pickup');
        await Vue.nextTick();
        assert.equal(page.selectedQuote.value.delivery_fee, '0.00');
    } finally {
        scope.stop();
        globalThis.fetch = originalFetch;
    }
});

function quoteLoadingHarness(t) {
    const scope = Vue.effectScope();
    t.after(() => scope.stop());
    const requests = [];
    t.mock.method(
        globalThis,
        'fetch',
        (url) => new Promise((resolve) => requests.push({ url, resolve })),
    );
    const render = scope.run(() =>
        loadPage(true).setup(checkoutProps(), { expose() {} }),
    );
    const root = () => render({}, []);
    const chooseFulfillment = (value) =>
        findNode(
            root(),
            (node) =>
                node.props?.name === 'fulfillment_method' &&
                node.props?.value === value,
        ).props.onChange();
    const setDestination = async (destination) => {
        findNode(root(), (node) => node.props?.locations === locations).props[
            'onUpdate:modelValue'
        ]({
            ...emptyDeliveryAddress(),
            delivery_destination: destination,
        });
        await Vue.nextTick();
    };
    const reply = async (index, data, ok = true) => {
        requests[index].resolve({ ok, json: async () => ({ data }) });
        await new Promise((resolve) => setImmediate(resolve));
        await Vue.nextTick();
    };

    return {
        root,
        chooseFulfillment,
        setDestination,
        reply,
        html: () => renderToString(root()),
    };
}

test('checkout shows a local skeleton for the current quote request and discards stale completion', async (t) => {
    const checkout = quoteLoadingHarness(t);
    assert.doesNotMatch(await checkout.html(), /delivery-quote-skeleton/);
    checkout.chooseFulfillment('delivery');
    assert.doesNotMatch(await checkout.html(), /delivery-quote-skeleton/);
    await checkout.setDestination('1804523000');

    let html = await checkout.html();
    assert.match(html, /role="status"[\s\S]*Refreshing delivery estimate/);
    assert.match(
        html,
        /data-slot="delivery-quote-skeleton" aria-hidden="true"/,
    );
    assert.doesNotMatch(html, /LBC delivery estimate/);

    await checkout.setDestination('1380300000');
    const quote = checkoutProps().deliveryQuotes[0];
    await checkout.reply(0, { quote: { ...quote, destination: '1804523000' } });
    assert.match(await checkout.html(), /delivery-quote-skeleton/);
    await checkout.reply(1, { quote: { ...quote, destination: '1380300000' } });
    html = await checkout.html();
    assert.match(html, /LBC delivery estimate/);
    assert.doesNotMatch(
        html,
        /delivery-quote-skeleton|Refreshing delivery estimate/,
    );

    await checkout.setDestination('1804523000');
    checkout.chooseFulfillment('pickup');
    await Vue.nextTick();
    await checkout.reply(2, { quote: { ...quote, destination: '1804523000' } });
    html = await checkout.html();
    assert.match(html, /Pickup address/);
    assert.doesNotMatch(html, /delivery-quote-skeleton|LBC delivery estimate/);
});

test('quote failures clear the skeleton and retry resolves to the existing unavailable notice', async (t) => {
    const checkout = quoteLoadingHarness(t);
    checkout.chooseFulfillment('delivery');
    await checkout.setDestination('1804523000');
    await checkout.reply(0, {}, false);

    let html = await checkout.html();
    assert.match(
        html,
        /role="alert"[\s\S]*Unable to refresh the delivery quote/,
    );
    assert.match(html, /Retry quote/);
    assert.doesNotMatch(html, /delivery-quote-skeleton/);

    const retry = findNode(
        checkout.root(),
        (node) =>
            node.type === 'button' &&
            typeof node.children === 'string' &&
            node.children.includes('Retry quote'),
    );
    const pending = retry.props.onClick();
    assert.match(await checkout.html(), /delivery-quote-skeleton/);
    await checkout.reply(1, {
        quote: null,
        notice: 'Delivery is unavailable for this location.',
    });
    await pending;
    html = await checkout.html();
    assert.match(html, /Delivery unavailable/);
    assert.match(html, /Delivery is unavailable for this location/);
    assert.doesNotMatch(html, /delivery-quote-skeleton|Retry quote/);
});

test('unconfigured delivery addresses allow subtotal-only checkout without a quoted ETA', async () => {
    const scope = Vue.effectScope();
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => ({
        ok: true,
        json: async () => ({
            data: {
                available: true,
                quote: {
                    destination: '1380300000',
                    destination_label: 'City of Makati',
                    pricing_configured: false,
                    pricing_status: 'not_configured',
                    order_placement_allowed: true,
                    payment_methods: ['gcash', 'maya', 'cod'],
                    product_subtotal: '100.00',
                    delivery_fee: '0.00',
                    total: '100.00',
                    notice: 'No delivery estimate is configured. Any delivery arrangement and charge must be confirmed separately.',
                    assumption_label:
                        'The 0.00 placeholder is not a free-delivery offer or an LBC quotation.',
                },
            },
        }),
    });

    try {
        const page = scope.run(() =>
            loadPage(true).setup(checkoutProps(), { expose() {} }),
        );
        const root = () => page({}, []);
        findNode(
            root(),
            (node) =>
                node.props?.name === 'fulfillment_method' &&
                node.props?.value === 'delivery',
        ).props.onChange();
        const fields = findNode(
            root(),
            (node) => node.props?.locations === locations,
        );
        fields.props['onUpdate:modelValue']({
            delivery_destination: '1380300000',
            delivery_barangay_code: '1380300001',
            delivery_address_details: 'Amihan 1, Bridge Area',
        });
        await new Promise((resolve) => setImmediate(resolve));
        await Vue.nextTick();

        let html = await renderToString(root());
        assert.match(html, /No delivery estimate configured/);
        assert.match(
            html,
            /delivery arrangement and any applicable charge separately/,
        );
        assert.match(html, /0\.00 placeholder is not a free-delivery offer/);
        assert.match(html, /value="gcash"/);
        assert.match(html, /value="cod"/);
        assert.doesNotMatch(
            html,
            /Manual delivery quotation required|Request a delivery quotation before ordering/,
        );
        assert.doesNotMatch(html, /pays the carrier at\s+dispatch/);
        assert.doesNotMatch(html, /Refresh quote|Retry quote/);
        assert.doesNotMatch(html, /Not quoted<\/dd>[\s\S]*?disabled/);
        assert.match(html, /Place order<\/button>/);
    } finally {
        scope.stop();
        globalThis.fetch = originalFetch;
    }
});

test('checkout selects the supplied destination quote and resets delivery values for pickup', () => {
    const scope = Vue.effectScope();
    try {
        const props = checkoutProps();
        const page = scope.run(() => loadPage().setup(props, { expose() {} }));
        page.chooseFulfillment('delivery');
        for (const location of locations) {
            page.deliveryAddress.value = {
                delivery_destination: location.destination,
                delivery_barangay_code: location.barangays[0].code,
                delivery_address_details: '12 Mabini Street',
            };
            assert.equal(
                page.selectedQuote.value,
                props.deliveryQuotes.find(
                    (quote) => quote.destination === location.destination,
                ),
            );
        }
        page.chooseFulfillment('pickup');
        assert.equal(page.deliveryAddress.value.delivery_destination, '');
        assert.equal(page.deliveryAddress.value.delivery_barangay_code, '');
        assert.equal(page.selectedQuote.value, props.pickupQuote);
    } finally {
        scope.stop();
    }
});

test('verified profile parts prefill checkout without copying the full profile into street details', () => {
    const scope = Vue.effectScope();
    try {
        const page = scope.run(() =>
            loadPage().setup(
                checkoutProps({
                    delivery_destination: 'Sagay City',
                    delivery_barangay_code: '1804523018',
                    delivery_address_details: '12 Mabini Street',
                }),
                { expose() {} },
            ),
        );
        page.chooseFulfillment('delivery');
        assert.deepEqual(page.deliveryAddress.value, {
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        });
    } finally {
        scope.stop();
    }
});

test('checkout renders shared structured fields and errors, and pickup submits no delivery inputs', async () => {
    const scope = Vue.effectScope();
    try {
        const render = scope.run(() =>
            loadPage(true, {
                delivery_barangay_code: 'Select a barangay.',
                delivery_address_details: 'Enter street details.',
            }).setup(checkoutProps(), { expose() {} }),
        );
        const root = () => render({}, []);
        const fulfillment = (value) =>
            findNode(
                root(),
                (node) =>
                    node.props?.name === 'fulfillment_method' &&
                    node.props?.value === value,
            );
        fulfillment('delivery').props.onChange();
        let html = await renderToString(root());
        assert.match(
            html,
            /Automatic checkout requires a configured delivery quote\./,
        );
        assert.match(html, /City \/ Municipality/);
        assert.match(html, /House \/ Building \/ Street \/ Landmark/);
        assert.match(html, /name="delivery_barangay_code"/);
        assert.match(html, /name="delivery_address_details"[^>]*required/);
        assert.match(html, /Select a barangay\./);
        assert.match(html, /Enter street details\./);
        assert.ok(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'));
        assert.doesNotMatch(html, /name="delivery_address"/);
        assert.doesNotMatch(html, /name="(?:region|province)"/);
        const fields = findNode(
            root(),
            (node) => node.props?.locations === locations,
        );
        fields.props['onUpdate:modelValue']({
            delivery_destination: 'Sagay City',
            delivery_barangay_code: '1804523018',
            delivery_address_details: '12 Mabini Street',
        });
        html = await renderToString(root());
        assert.match(html, /name="delivery_barangay_code" value="1804523018"/);
        fulfillment('pickup').props.onChange();
        html = await renderToString(root());
        assert.doesNotMatch(
            html,
            /name="delivery_(?:destination|barangay_code|address_details)"/,
        );
        assert.match(html, /Pickup address/);
    } finally {
        scope.stop();
    }
});

test('COD selection resets wallet evidence and switches to an eligible method for pickup', () => {
    const scope = Vue.effectScope();
    try {
        const page = scope.run(() =>
            loadPage().setup(checkoutProps(), { expose() {} }),
        );
        assert.equal(
            page.availablePaymentMethods.value.some(
                (method) => method.value === 'cod',
            ),
            false,
        );
        page.chooseFulfillment('delivery');
        assert.equal(page.requiresPaymentProof.value, true);
        const proofKey = page.paymentProofKey.value;
        page.choosePayment('cod');
        assert.equal(page.isCod.value, true);
        assert.equal(page.requiresPaymentProof.value, false);
        assert.equal(page.selectedPaymentAccount.value, null);
        assert.equal(page.paymentProofKey.value, proofKey + 1);
        page.chooseFulfillment('pickup');
        assert.equal(page.paymentMethod.value, 'cash');
        assert.equal(page.isCod.value, false);
    } finally {
        scope.stop();
    }
});

test('pickup checkout displays cash and physical card descriptions without requesting proof', async () => {
    const scope = Vue.effectScope();
    try {
        const render = scope.run(() =>
            loadPage(true).setup(checkoutProps(), { expose() {} }),
        );
        let html = await renderToString(render({}, []));
        assert.match(html, /Cash \(Pay at Pickup\)/);
        assert.match(html, /Debit\/Credit Card \(Pay at Store\)/);
        assert.match(html, /Pay cash when collecting/);
        const card = findNode(
            render({}, []),
            (node) =>
                node.props?.name === 'payment_method' &&
                node.props?.value === 'card_at_store',
        );
        card.props.onChange();
        html = await renderToString(render({}, []));
        assert.match(html, /physical debit\/credit card terminal/);
        assert.match(html, /not an online payment gateway/);
        assert.doesNotMatch(html, /name="payment_proof"/);
    } finally {
        scope.stop();
    }
});

test('COD checkout displays the server quote total with collection guidance and no proof input', async () => {
    const scope = Vue.effectScope();
    try {
        const render = scope.run(() =>
            loadPage(true).setup(
                checkoutProps({
                    delivery_destination: 'Sagay City',
                    delivery_barangay_code: '1804523018',
                    delivery_address_details: '12 Mabini Street',
                }),
                { expose() {} },
            ),
        );
        const root = () => render({}, []);
        findNode(
            root(),
            (node) =>
                node.props?.name === 'fulfillment_method' &&
                node.props?.value === 'delivery',
        ).props.onChange();
        findNode(
            root(),
            (node) =>
                node.props?.name === 'payment_method' &&
                node.props?.value === 'cod',
        ).props.onChange();
        const html = await renderToString(root());
        assert.match(html, /Total COD amount payable/);
        assert.match(html, /180\.00/);
        assert.match(html, /LBC collects the full amount at delivery/);
        assert.match(
            html,
            /records payment after LBC remits the full order amount/,
        );
        assert.match(html, /checkout does not create an LBC booking/);
        assert.match(html, /not official LBC quotations/);
        assert.doesNotMatch(html, /name="payment_proof"/);
        assert.doesNotMatch(html, /receiving account/);
    } finally {
        scope.stop();
    }
});
