import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as Vue from 'vue';

function searchHarness(t, { initialSearch = 'laptop', filters = {} } = {}) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    const disposed = [];
    const appliedSearch = Vue.ref(initialSearch);
    const scope = Vue.effectScope();
    const modules = {
        vue: { ...Vue, onBeforeUnmount: (callback) => disposed.push(callback) },
        '@inertiajs/vue3': {
            router: {
                visit: (route, options) => {
                    const cancel = t.mock.fn();
                    requests.push({ route, options, cancel });
                    options.onCancelToken({ cancel });
                    options.onStart();
                },
            },
        },
    };
    const code = readFileSync(
        new URL('./useDebouncedSearch.js', import.meta.url),
        'utf8',
    )
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, moduleName) =>
                `const { ${names} } = modules[${JSON.stringify(moduleName)}];`,
        )
        .replace('export function', 'function');
    const useDebouncedSearch = new Function(
        'modules',
        code + '\nreturn useDebouncedSearch;',
    )(modules);
    const search = scope.run(() =>
        useDebouncedSearch({
            initialSearch,
            currentSearch: () => appliedSearch.value,
            route: ({ query }) => {
                const params = new URLSearchParams(
                    Object.entries(query).filter(
                        ([, value]) => value !== undefined,
                    ),
                );
                return {
                    url: `/products${params.size ? `?${params}` : ''}`,
                    method: 'get',
                };
            },
            query: () => filters,
            reset: ['products'],
            preserveScroll: false,
        }),
    );
    t.after(() => {
        disposed.forEach((callback) => callback());
        scope.stop();
    });
    return { ...search, appliedSearch, requests, disposed };
}

test('clearing an applied search immediately requests a URL without q or the old page', async (t) => {
    const h = searchHarness(t);
    h.clearSearch();
    await Vue.nextTick();

    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].route.url, '/products');
    assert.equal(
        new URL(
            h.requests[0].route.url,
            'http://catalog.test',
        ).searchParams.has('q'),
        false,
    );
    assert.deepEqual(h.requests[0].options.reset, ['products']);
    assert.equal(h.requests[0].options.replace, true);
    assert.equal(h.requests[0].options.preserveScroll, false);
    h.appliedSearch.value = null;
    h.requests[0].options.onFinish();
    t.mock.timers.tick(400);
    assert.equal(h.requests.length, 1);
    assert.equal(h.search.value, '');
});

test('deleting all search text clears immediately and preserves selected catalog filters', async (t) => {
    const h = searchHarness(t, {
        filters: { category_id: 7, brand: 'Atlas', tag_id: 9 },
    });
    h.search.value = '   ';
    await Vue.nextTick();

    assert.equal(h.requests.length, 1);
    const params = new URL(h.requests[0].route.url, 'http://catalog.test')
        .searchParams;
    assert.equal(params.has('q'), false);
    assert.equal(params.get('category_id'), '7');
    assert.equal(params.get('brand'), 'Atlas');
    assert.equal(params.get('tag_id'), '9');
});

test('typing remains debounced and clearing discards a pending replacement search', async (t) => {
    const h = searchHarness(t);
    h.search.value = 'keyboard';
    await Vue.nextTick();
    t.mock.timers.tick(399);
    assert.equal(h.requests.length, 0);
    h.clearSearch();
    await Vue.nextTick();

    assert.deepEqual(
        h.requests.map(({ route }) => route.url),
        ['/products'],
    );
    t.mock.timers.tick(1000);
    assert.equal(h.requests.length, 1);
});

test('stale request callbacks cannot finish or uncancel a newer clear request', async (t) => {
    const h = searchHarness(t);
    h.search.value = 'keyboard';
    await Vue.nextTick();
    t.mock.timers.tick(400);
    const oldRequest = h.requests[0];
    h.clearSearch();
    await Vue.nextTick();
    t.mock.timers.tick(400);
    const clearRequest = h.requests[1];
    assert.equal(oldRequest.cancel.mock.callCount(), 1);
    assert.equal(clearRequest.route.url, '/products');
    assert.equal(h.isSearching.value, true);

    oldRequest.options.onFinish();
    assert.equal(h.isSearching.value, true);
    h.cancelPendingSearch();
    assert.equal(clearRequest.cancel.mock.callCount(), 1);
    assert.equal(h.isSearching.value, false);
});

test('returning to an already unfiltered state cancels stale search without another request', async (t) => {
    const h = searchHarness(t, { initialSearch: null });
    h.search.value = 'laptop';
    await Vue.nextTick();
    t.mock.timers.tick(400);
    h.clearSearch();
    await Vue.nextTick();
    t.mock.timers.tick(400);

    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].cancel.mock.callCount(), 1);
    assert.equal(h.isSearching.value, false);
});

test('unmount cancels pending search and prevents a delayed visit', async (t) => {
    const h = searchHarness(t);
    h.search.value = 'keyboard';
    await Vue.nextTick();
    h.disposed.forEach((callback) => callback());
    t.mock.timers.tick(1000);

    assert.equal(h.requests.length, 0);
});
