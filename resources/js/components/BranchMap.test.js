import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { findAddressNode } from '../lib/deliveryAddressTestHelpers.js';
import {
    compileSkeletonTestComponent,
    loadSkeletonComponent,
} from '../lib/skeletonTestHelpers.js';

function mapHarness(
    loadLeaflet,
    branches = [{ city: 'Sagay', latitude: 10.9, longitude: 123.4 }],
) {
    let mounted;
    const component = compileSkeletonTestComponent(
        readFileSync(new URL('./BranchMap.vue', import.meta.url), 'utf8'),
        {
            vue: {
                ...Vue,
                onMounted: (callback) => {
                    mounted = callback;
                },
                onBeforeUnmount() {},
            },
            '@lucide/vue': { MapPinned: { render: () => Vue.h('svg') } },
            '@/components/ui/skeleton': {
                Skeleton: loadSkeletonComponent('ui/skeleton/Skeleton.vue'),
            },
            loadLeaflet,
        },
        (code) =>
            code
                .replace("import 'leaflet/dist/leaflet.css';", '')
                .replace("import('leaflet')", 'modules.loadLeaflet()'),
    );
    const render = component.setup({ branches }, { expose() {} });
    const root = () => render({}, []);
    const mapNode = findAddressNode(
        root(),
        (node) =>
            node.props?.['aria-label'] ===
            'Map of approved Battlefront branch locations',
    );
    mapNode.ref.r.value = {};

    return { mount: () => mounted(), html: () => renderToString(root()) };
}

test('branch map skeleton stays inside the fixed map height until initialization completes', async (t) => {
    const originalDocument = globalThis.document;
    globalThis.document = { createElement: () => ({ append() {} }) };
    t.after(() => {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    });
    let resolve;
    const pending = new Promise((done) => {
        resolve = done;
    });
    const map = mapHarness(() => pending);
    const mounted = map.mount();

    let html = await map.html();
    assert.match(
        html,
        /aria-label="Map of approved Battlefront branch locations" aria-busy="true"/,
    );
    assert.match(html, /min-h-96/);
    assert.match(html, /class="h-96 w-full"/);
    assert.match(html, /data-slot="skeleton"/);
    assert.match(html, /role="status"[\s\S]*Loading branch map/);

    const layer = {
        addTo() {
            return this;
        },
        bindTooltip() {
            return this;
        },
        bindPopup() {
            return this;
        },
    };
    resolve({
        default: {
            map: () => ({ fitBounds() {} }),
            tileLayer: () => layer,
            circleMarker: () => layer,
        },
    });
    await mounted;
    html = await map.html();
    assert.match(html, /aria-busy="false"/);
    assert.match(html, /class="h-96 w-full"/);
    assert.doesNotMatch(
        html,
        /data-slot="skeleton"|Loading branch map|not currently available/,
    );
});

test('branch map failure replaces the skeleton with the existing unavailable message', async () => {
    const map = mapHarness(() => Promise.reject(new Error('Map unavailable')));
    await map.mount();

    const html = await map.html();
    assert.match(html, /branch map is not currently available/);
    assert.match(html, /Confirmed branch details remain available below/);
    assert.match(html, /aria-busy="false"/);
    assert.doesNotMatch(html, /data-slot="skeleton"|Loading branch map/);
});

test('branch maps without coordinates show unavailable content without importing the map library', async () => {
    let imports = 0;
    const map = mapHarness(() => {
        imports++;
    }, []);
    await map.mount();

    assert.equal(imports, 0);
    assert.match(await map.html(), /branch map is not currently available/);
    assert.doesNotMatch(await map.html(), /data-slot="skeleton"/);
});

test('branch map pins use only complete valid coordinates and escape managed text', async (t) => {
    const originalDocument = globalThis.document;
    const nodes = [];
    globalThis.document = {
        createElement: () => {
            const node = { append() {} };
            nodes.push(node);
            return node;
        },
    };
    t.after(() => {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    });
    const pins = [];
    const layer = {
        addTo() {
            return this;
        },
        bindTooltip() {
            return this;
        },
        bindPopup() {
            return this;
        },
    };
    const map = mapHarness(
        async () => ({
            default: {
                map: () => ({ fitBounds() {} }),
                tileLayer: () => layer,
                circleMarker: (coordinates) => {
                    pins.push(coordinates);
                    return layer;
                },
            },
        }),
        [
            {
                city: '<script>Untrusted city</script>',
                address: '<img src=x onerror=alert(1)>',
                latitude: '10.1234567',
                longitude: '123.7654321',
            },
            { city: 'Zero', latitude: 0, longitude: 0 },
            { city: 'No pin', latitude: null, longitude: null },
            { city: 'Partial', latitude: 10, longitude: null },
            { city: 'Blank', latitude: '', longitude: '' },
            { city: 'Whitespace', latitude: ' ', longitude: ' ' },
            { city: 'Missing' },
            { city: 'Outside range', latitude: 91, longitude: 123 },
            { city: 'Invalid', latitude: 'north', longitude: 123 },
        ],
    );

    await map.mount();

    assert.deepEqual(pins, [
        [10.1234567, 123.7654321],
        [0, 0],
    ]);
    assert.ok(
        nodes.some(
            (node) => node.textContent === '<script>Untrusted city</script>',
        ),
    );
    assert.ok(
        nodes.some(
            (node) => node.textContent === '<img src=x onerror=alert(1)>',
        ),
    );
    assert.ok(nodes.every((node) => !('innerHTML' in node)));
    assert.match(await map.html(), /2 locations/);
});
