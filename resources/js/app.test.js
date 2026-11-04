import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import inertia from '@inertiajs/vite';

const source = readFileSync(new URL('./app.js', import.meta.url), 'utf8');

function appOptions(pageModules) {
    let options;
    runInNewContext(
        source
            .replace(/^import .*;\r?\n/gm, '')
            .replace('import.meta.env.VITE_APP_NAME', 'undefined')
            .replace("import.meta.glob('./pages/**/*.vue')", 'pageModules'),
        {
            pageModules,
            createInertiaApp: (value) => { options = value; },
            initializeFlashToast() {},
            initializeTheme() {},
            AppLayout: 'app',
            AuthLayout: 'auth',
            SettingsLayout: 'settings',
        },
    );
    return options;
}

test('page resolution loads only the requested page after bootstrap', async () => {
    const loaded = [];
    const welcome = { default: { name: 'Welcome' } };
    const options = appOptions({
        './pages/Welcome.vue': async () => { loaded.push('Welcome'); return welcome; },
        './pages/Administration/Forecasting.vue': async () => { loaded.push('Forecasting'); return {}; },
    });
    assert.deepEqual(loaded, []);
    assert.equal(await options.resolve('Welcome'), welcome);
    assert.deepEqual(loaded, ['Welcome']);
    await assert.rejects(options.resolve('Missing'), /Inertia page not found: Missing/);
});

test('explicit resolver prevents the Inertia plugin from injecting its whole-site page warmup', () => {
    assert.equal(inertia().transform(source, '/resources/js/app.js', { ssr: false }), null);
    const transformed = inertia().transform(source, '/resources/js/app.js', { ssr: true });
    assert.equal((transformed.match(/import\.meta\.glob\(/g) ?? []).length, 1);
});

test('lazy resolution preserves public and authenticated layout selection', () => {
    const options = appOptions({});
    assert.equal(options.layout('Welcome', { props: { auth: { user: null } } }), null);
    assert.equal(options.layout('Products/Index', { props: { auth: { user: { id: 1 } } } }), 'app');
    assert.equal(options.layout('auth/Login', { props: {} }), 'auth');
});
