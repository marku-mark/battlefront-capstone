import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import inertia from '@inertiajs/vite';
import * as Vue from 'vue';
import { compileSkeletonTestComponent } from './lib/skeletonTestHelpers.js';

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
            CustomerChatLayout: 'chat',
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
    const guest = { props: { auth: { user: null } } };
    const customer = { props: { auth: { user: { id: 1 }, can: { useCustomerCart: true } } } };
    const staff = { props: { auth: { user: { id: 2 }, can: { useCustomerCart: false } } } };
    for (const page of ['Welcome', 'Products/Index', 'Products/Show', 'Branches/Index', 'PcBuilder/Index']) {
        assert.equal(options.layout(page, guest), 'chat');
        assert.deepEqual(Array.from(options.layout(page, customer)), ['chat', 'app']);
    }
    assert.equal(options.layout('PcBuilder/Shared', guest), 'chat');
    assert.equal(options.layout('PcBuilder/Shared', customer), 'chat');
    assert.equal(options.layout('PcBuilder/Shared', staff), null);
    assert.deepEqual(Array.from(options.layout('settings/Profile', customer)), ['chat', 'app', 'settings']);
    assert.equal(options.layout('Products/Index', staff), 'app');
    assert.equal(options.layout('auth/Login', guest), 'auth');
    assert.equal(options.layout('auth/VerifyEmail', customer), 'auth');
});

test('navigation to Products and Our stores keeps the pending chat mounted', async (t) => {
    let submissions = 0;
    let sendQuestion;
    const reply = Promise.withResolvers();
    const chat = {
        setup() {
            const message = Vue.ref('');
            sendQuestion = async () => {
                submissions++;
                message.value = 'Waiting for a reply';
                message.value = await reply.promise;
            };
            return () => Vue.h('aside', message.value);
        },
    };
    const layout = compileSkeletonTestComponent(
        readFileSync(new URL('./layouts/CustomerChatLayout.vue', import.meta.url), 'utf8'),
        {
            vue: Vue,
            '@/components/chatbot/CustomerChatAssistant.vue': { default: chat },
        },
    );
    const createNode = (tag) => ({ tag, children: [], text: '' });
    const detach = (node) => {
        if (node.parent) {
            node.parent.children.splice(node.parent.children.indexOf(node), 1);
            node.parent = undefined;
        }
    };
    const renderer = Vue.createRenderer({
        createElement: createNode,
        createText: (text) => ({ ...createNode('#text'), text }),
        createComment: createNode,
        setText: (node, text) => { node.text = text; },
        setElementText: (node, text) => { node.text = text; },
        patchProp() {},
        parentNode: (node) => node.parent,
        nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1],
        insert(node, parent, anchor) {
            detach(node);
            node.parent = parent;
            parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, node);
        },
        remove: detach,
    });
    const components = { chat: layout };
    const name = Vue.ref('Welcome');
    const guestPage = { props: { auth: { user: null } } };
    const options = appOptions({});
    const root = createNode('root');
    const app = renderer.createApp({
        render: () => Vue.h(components[options.layout(name.value, guestPage)], null, {
            default: () => Vue.h('main', { key: name.value }, name.value),
        }),
    });
    app.mount(root);
    t.after(() => app.unmount());
    const pageContent = () => root.children[0].children;
    const originalChat = pageContent().find((node) => node.tag === 'aside');
    const request = sendQuestion();

    for (const destination of ['Products/Index', 'Branches/Index']) {
        name.value = destination;
        await Vue.nextTick();
        assert.equal(pageContent().find((node) => node.tag === 'main').text, destination);
        assert.deepEqual(pageContent().filter((node) => node.tag === 'aside'), [originalChat]);
        assert.equal(originalChat.text, 'Waiting for a reply');
    }

    reply.resolve('This is your reply');
    await request;
    await Vue.nextTick();
    assert.equal(submissions, 1);
    assert.equal(originalChat.text, 'This is your reply');
});
