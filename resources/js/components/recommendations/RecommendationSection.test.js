import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';

function sectionHarness(t, { userId = null } = {}) {
    const mounted = [];
    const disposed = [];
    const requests = [];
    const observers = [];
    const listeners = new Map();
    const timers = new Map();
    const local = new Map();
    const session = new Map();
    const storage = (map) => ({
        getItem: (key) => map.get(key) ?? null,
        setItem: (key, value) => map.set(key, value),
        removeItem: (key) => map.delete(key),
    });
    const document = {
        visibilityState: 'visible',
        cookie: '',
        addEventListener: (key, callback) => listeners.set(key, callback),
        removeEventListener: (key) => listeners.delete(key),
    };
    const page = Vue.reactive({
        props: {
            auth: { user: userId ? { id: userId } : null },
            guest_recommendation_scope: 'guest-one',
        },
    });
    let timerId = 0;
    class Observer {
        constructor(callback) {
            this.callback = callback;
            this.active = true;
            observers.push(this);
        }
        observe() {}
        disconnect() {
            this.active = false;
        }
    }
    const globals = {
        document,
        window: {
            IntersectionObserver: Observer,
            setTimeout: (callback) => {
                timers.set(++timerId, callback);
                return timerId;
            },
            clearTimeout: (id) => timers.delete(id),
        },
        IntersectionObserver: Observer,
        localStorage: storage(local),
        sessionStorage: storage(session),
        fetch: async (url, options) => {
            requests.push({ url, data: JSON.parse(options.body) });
            return { ok: true };
        },
    };
    const originals = Object.fromEntries(
        Object.keys(globals).map((key) => [
            key,
            Object.getOwnPropertyDescriptor(globalThis, key),
        ]),
    );
    for (const [key, value] of Object.entries(globals))
        Object.defineProperty(globalThis, key, { value, configurable: true });
    const props = Vue.reactive({
        recommendations: [
            {
                product: { id: 1 },
                reasons: [{ code: 'matched_recent_searches' }],
            },
        ],
        placement: 'catalog',
    });
    const modules = new Proxy(
        {
            vue: {
                ...Vue,
                onMounted: (callback) => mounted.push(callback),
                onBeforeUnmount: (callback) => disposed.push(callback),
            },
            '@inertiajs/vue3': { usePage: () => page },
            '@/routes/recommendations/interactions': {
                store: {
                    post: () => ({
                        url: '/recommendations/interactions',
                        method: 'post',
                    }),
                },
            },
        },
        { get: (target, key) => target[key] ?? {} },
    );
    const { descriptor } = parse(
        readFileSync(
            new URL('./RecommendationSection.vue', import.meta.url),
            'utf8',
        ),
    );
    const script = compileScript(descriptor, {
        id: 'recommendation-section-test',
    });
    const code = script.content
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, module) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(module)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, module) =>
                `const ${name} = modules[${JSON.stringify(module)}].default;`,
        )
        .replace('export default', 'return');
    const scope = Vue.effectScope();
    const section = scope.run(() =>
        new Function('modules', code)(modules).setup(props, { expose() {} }),
    );
    const card = {
        dataset: { recommendationPosition: '1', recommendationProductId: '1' },
    };
    section.recommendationCards.value = [card];
    t.after(async () => {
        disposed.forEach((callback) => callback());
        scope.stop();
        await Vue.nextTick();
        for (const [key, original] of Object.entries(originals)) {
            if (original) Object.defineProperty(globalThis, key, original);
            else delete globalThis[key];
        }
    });
    return {
        section,
        props,
        page,
        local,
        session,
        requests,
        observers,
        timers,
        document,
        listeners,
        card,
        mounted,
        disposed,
    };
}

test('impressions require visible dwell and one observer survives initial storage loading', async (t) => {
    const h = sectionHarness(t);
    h.mounted.forEach((callback) => callback());
    await Vue.nextTick();
    await Vue.nextTick();
    assert.equal(h.observers.filter((observer) => observer.active).length, 1);
    h.observers
        .at(-1)
        .callback([
            { target: h.card, isIntersecting: true, intersectionRatio: 0.5 },
        ]);
    h.document.visibilityState = 'hidden';
    h.listeners.get('visibilitychange')();
    assert.equal(h.timers.size, 0);
    assert.equal(h.requests.length, 0);
    h.document.visibilityState = 'visible';
    h.listeners.get('visibilitychange')();
    await Vue.nextTick();
    h.observers
        .at(-1)
        .callback([
            { target: h.card, isIntersecting: true, intersectionRatio: 0.5 },
        ]);
    [...h.timers.values()].forEach((callback) => callback());
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].data.event_type, 'impression');
    assert.equal(h.requests[0].data.placement, 'catalog');
    assert.equal(h.requests[0].data.reason_code, 'matched_recent_searches');
});

test('guest dismissals follow the current profile and do not transfer to another guest or customer', async (t) => {
    const h = sectionHarness(t);
    h.mounted.forEach((callback) => callback());
    await Vue.nextTick();
    h.section.dismissRecommendation(h.props.recommendations[0], 'dismiss');
    assert.equal(h.section.visibleRecommendations.value.length, 0);
    assert.equal(h.local.size, 0);
    h.page.props.guest_recommendation_scope = 'guest-two';
    await Vue.nextTick();
    assert.equal(h.section.visibleRecommendations.value.length, 1);
    h.page.props.auth.user = { id: 42 };
    await Vue.nextTick();
    assert.equal(h.section.visibleRecommendations.value.length, 1);
});

test('expired customer dismissals are pruned and current dismissals survive reload', async (t) => {
    const h = sectionHarness(t, { userId: 42 });
    const key = 'battlefront:dismissed-recommendations:v2:customer:42';
    h.local.set(key, JSON.stringify({ 1: Date.now() - 91 * 86400000 }));
    h.mounted.forEach((callback) => callback());
    await Vue.nextTick();
    assert.equal(h.section.visibleRecommendations.value.length, 1);
    h.section.dismissRecommendation(h.props.recommendations[0], 'report_wrong');
    h.section.loadDismissedRecommendations();
    assert.equal(h.section.visibleRecommendations.value.length, 0);
    assert.equal(h.requests[0].data.event_type, 'report_wrong');
});

test('unmount clears timers and prevents a pending observer setup from restarting', async (t) => {
    const h = sectionHarness(t);
    h.mounted.forEach((callback) => callback());
    h.disposed.forEach((callback) => callback());
    await Vue.nextTick();
    assert.equal(h.observers.filter((observer) => observer.active).length, 0);
    assert.equal(h.timers.size, 0);
    assert.equal(h.listeners.size, 0);
});

for (const placement of ['catalog', 'dashboard']) {
    test(`${placement} cards submit click hide and report events with the original reason and position`, (t) => {
        const h = sectionHarness(t, { userId: 42 });
        h.props.placement = placement;
        const recommendation = h.props.recommendations[0];
        h.section.recordInteraction(recommendation, 'click', 1);
        h.section.dismissRecommendation(recommendation, 'dismiss');
        h.section.dismissedProductIds.value = new Set();
        h.section.dismissRecommendation(recommendation, 'report_wrong');

        assert.deepEqual(
            h.requests.map(({ data }) => data.event_type),
            ['click', 'dismiss', 'report_wrong'],
        );
        for (const { url, data } of h.requests) {
            assert.equal(url, '/recommendations/interactions');
            assert.equal(data.placement, placement);
            assert.equal(data.position, 1);
            assert.equal(data.product_id, 1);
            assert.equal(data.reason_code, 'matched_recent_searches');
        }
    });
}
