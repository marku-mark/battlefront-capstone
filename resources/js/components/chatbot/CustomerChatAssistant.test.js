import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { quickQuestions } from './quickQuestions.js';
import { safePresentation } from './chatContextStorage.js';
import { reactive, watch, nextTick } from 'vue';

const source = readFileSync(
    new URL('./CustomerChatAssistant.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'chatbot-composer-test' });
const handler = script.scriptSetupAst.find(
    (node) =>
        node.type === 'FunctionDeclaration' &&
        node.id.name === 'handleComposerKeydown',
);
const handlerSource = descriptor.scriptSetup.content.slice(
    handler.start,
    handler.end,
);

test('ordinary Inertia visits retain history while identity changes reset it', async () => {
    const identityWatch = script.scriptSetupAst.find(
        (node) =>
            node.type === 'ExpressionStatement' &&
            node.expression?.callee?.name === 'watch' &&
            node.expression.arguments[1]?.name === 'startNewChat',
    );
    const page = reactive({
        props: { auth: { user: null }, chatbotMemory: { identity: 'guest-a' } },
    });
    let resets = 0;
    const stop = runInNewContext(
        descriptor.scriptSetup.content
            .slice(identityWatch.start, identityWatch.end)
            .replace(/;$/, ''),
        {
            watch,
            page,
            startNewChat: () => {
                resets++;
            },
        },
    );
    page.props = {
        auth: { user: null },
        chatbotMemory: { identity: 'guest-a' },
    };
    await nextTick();
    assert.equal(resets, 0);
    page.props = {
        auth: { user: { id: 7 } },
        chatbotMemory: { identity: 'customer-b' },
    };
    await nextTick();
    assert.equal(resets, 1);
    stop();
});

function functionSource(name) {
    const declaration = script.scriptSetupAst.find(
        (node) => node.type === 'FunctionDeclaration' && node.id.name === name,
    );
    return descriptor.scriptSetup.content.slice(
        declaration.start,
        declaration.end,
    );
}

test('slow requests show waiting feedback after five seconds and clear it on completion', () => {
    const pendingWatch = script.scriptSetupAst.find((node) =>
        node.type === 'ExpressionStatement' && node.expression?.callee?.name === 'watch'
        && node.expression.arguments[0]?.name === 'pendingMessage');
    let callback;
    let fire;
    const waitingLonger = { value: false };
    runInNewContext(descriptor.scriptSetup.content.slice(pendingWatch.start, pendingWatch.end), {
        pendingMessage: {}, waitingLonger, waitingTimer: null,
        watch: (_, handler) => { callback = handler; },
        clearTimeout: () => { fire = null; },
        setTimeout: (handler, delay) => { assert.equal(delay, 5000); fire = handler; return 1; },
    });
    callback('mouse');
    assert.equal(waitingLonger.value, false);
    fire();
    assert.equal(waitingLonger.value, true);
    callback('');
    assert.equal(waitingLonger.value, false);
    assert.equal(fire, null);
});

test('a pending submission prevents another request even before processing updates', async () => {
    let sent = 0;
    await runInNewContext(`(${functionSource('submitMessage')})`, {
        inquiry: { processing: false, post: () => { sent++; } },
        pendingMessage: { value: 'mouse' },
    })();
    assert.equal(sent, 0);
});

test('New chat clears transcript, token, errors and saved reading position', () => {
    const context = {
        clearChatContext() {},
        clearChatTranscript() {},
        clearTimeout() {},
        expiryTimer: null,
        transcriptExpiresAt: 0,
        contextStorage: () => null,
        conversationVersion: 1,
        quickMenu: { value: 'main' },
        showQuickQuestions: { value: true },
        savedScrollTop: 500,
        readerScrolledBack: true,
        messages: { value: [{ content: 'old reply' }] },
        inquiry: {
            message: 'old question',
            context_token: 'old-token',
            clearErrors() {},
        },
        inputError: { value: 'error' },
        requestError: { value: 'error' },
        pendingMessage: { value: 'pending' },
    };
    runInNewContext(
        `${functionSource('startNewChat')}; startNewChat();`,
        context,
    );
    assert.equal(context.messages.value.length, 0);
    assert.equal(context.inquiry.context_token, null);
    assert.equal(context.inquiry.message, '');
    assert.equal(context.requestError.value, '');
    assert.equal(context.pendingMessage.value, '');
    assert.equal(context.conversationVersion, 2);
    assert.equal(context.savedScrollTop, 0);
});

for (const failure of [false, true]) {
    test(`late ${failure ? 'errors' : 'replies'} cannot enter a reset conversation`, async () => {
        let finish;
        const response = new Promise((resolve, reject) => {
            finish = failure ? reject : resolve;
        });
        const context = {
            transcriptExpiresAt: 0,
            scheduleExpiry() {},
            safePresentation,
            conversationVersion: 1,
            quickMenu: { value: 'main' },
            showQuickQuestions: { value: true },
            nextMessageId: 1,
            readerScrolledBack: false,
            messages: { value: [] },
            pendingMessage: { value: '' },
            inputError: { value: '' },
            requestError: { value: '' },
            inquiry: {
                processing: false,
                message: 'monitor',
                clearErrors() {},
                post: () => response,
            },
            setTimeout,
            clearTimeout,
            scrollToLatest() {},
            persistContext() {},
            showReply() {},
            ChatbotController: { store: { url: () => '/chatbot' } },
        };
        const submit = runInNewContext(
            `(${functionSource('submitMessage')})`,
            context,
        );
        const request = submit();
        context.conversationVersion++;
        context.pendingMessage.value = '';
        finish(
            failure
                ? new Error('old request')
                : { message: 'old reply', context_token: 'old-token' },
        );
        await request;
        assert.equal(context.messages.value.length, 0);
        assert.equal(context.requestError.value, '');
        assert.equal(context.pendingMessage.value, '');
    });
}

test('category shortcuts navigate without submitting and processing blocks clicks', async () => {
    const submissions = [];
    const context = {
        inquiry: { processing: false },
        quickMenu: { value: 'main' },
        submitMessage: (question) => submissions.push(question),
    };
    const choose = runInNewContext(
        `(${functionSource('chooseQuickQuestion')})`,
        context,
    );
    await choose(quickQuestions.main.find((item) => item.menu === 'builder'));
    assert.equal(context.quickMenu.value, 'builder');
    assert.equal(submissions.length, 0);
    await choose(quickQuestions.builder[0]);
    assert.equal(submissions[0], 'How do I use PC Builder?');
    context.inquiry.processing = true;
    await choose(quickQuestions.builder[1]);
    assert.equal(submissions.length, 1);
});

for (const succeeds of [true, false]) {
    test(`quick question preserves draft and ${succeeds ? 'uses new' : 'restores old'} context`, async () => {
        let sent;
        const context = {
            transcriptExpiresAt: 0,
            scheduleExpiry() {},
            safePresentation,
            conversationVersion: 1,
            nextMessageId: 1,
            readerScrolledBack: false,
            messages: { value: [] },
            pendingMessage: { value: '' },
            inputError: { value: '' },
            requestError: { value: '' },
            showQuickQuestions: { value: true },
            inquiry: {
                processing: false,
                message: 'Unfinished question',
                context_token: 'old-token',
                clearErrors() {},
                post() {
                    sent = { message: this.message, token: this.context_token };
                    if (!succeeds) throw new Error('offline');
                    return {
                        message: 'Approved guidance.',
                        source: 'fallback',
                        context_token: 'new-token',
                    };
                },
            },
            setTimeout,
            clearTimeout,
            scrollToLatest() {},
            persistContext() {},
            showReply() {},
            ChatbotController: { store: { url: () => '/chatbot' } },
        };
        await runInNewContext(
            `(${functionSource('submitMessage')})`,
            context,
        )('What payment methods can I use?');
        assert.deepEqual(sent, {
            message: 'What payment methods can I use?',
            token: null,
        });
        assert.equal(context.inquiry.message, 'Unfinished question');
        assert.equal(
            context.inquiry.context_token,
            succeeds ? 'new-token' : 'old-token',
        );
        assert.equal(context.messages.value.length, succeeds ? 2 : 0);
        assert.equal(context.showQuickQuestions.value, !succeeds);
    });
}

for (const [name, event, shouldSubmit] of [
    ['Enter submits instead of inserting a new line', { key: 'Enter' }, true],
    [
        'Shift+Enter inserts a new line without submitting',
        { key: 'Enter', shiftKey: true },
        false,
    ],
    ['Ctrl+Enter still submits', { key: 'Enter', ctrlKey: true }, true],
    [
        'Enter during text composition does not submit',
        { key: 'Enter', isComposing: true },
        false,
    ],
    ['other keys keep their normal behavior', { key: 'a' }, false],
]) {
    test(name, () => {
        let submissions = 0;
        let prevented = false;
        const handleKeydown = runInNewContext(`(${handlerSource})`, {
            submitMessage: () => {
                submissions++;
            },
        });

        handleKeydown({
            ...event,
            preventDefault: () => {
                prevented = true;
            },
        });

        assert.equal(submissions, shouldSubmit ? 1 : 0);
        assert.equal(prevented, shouldSubmit);
    });
}
import { compileTemplate } from '@vue/compiler-sfc';

test('product cards compile with escaped Vue text and route helpers', () => {
    const compiled = compileTemplate({
        source: descriptor.template.content,
        filename: 'CustomerChatAssistant.vue',
        id: 'chatbot-cards',
    });
    assert.deepEqual(compiled.errors, []);
    assert.ok(compiled.code.includes('ProductCatalogController.show.url'));
    assert.ok(compiled.code.includes('toDisplayString(product.name)'));
    assert.ok(!compiled.code.includes('innerHTML'));
});

test('product links follow their full descriptions without duplicating the bottom link list', () => {
    const context = {};
    runInNewContext(`${functionSource('messageParagraphs')}; ${functionSource('remainingActions')}; this.paragraphs = messageParagraphs; this.remaining = remainingActions;`, context);
    for (const stock of ['Low stock at Sagay', 'Kaunti na lang ang stock sa Sagay', 'Gamay na lang ang stock sa Sagay', 'Diutay na lang ang stock sa Sagay']) {
        const first = { kind: 'product', id: 7, label: 'BIOSTAR H610M Motherboard' };
        const second = { kind: 'product', id: 8, label: 'Atlas SSD' };
        const browse = { kind: 'catalog', label: 'Browse products' };
        const message = { presentation: {
            intro: `Here are BIOSTAR H610M Motherboard and Atlas SSD.\n\nBIOSTAR H610M Motherboard — PHP 3,600.00. ${stock}.\n\nAtlas SSD — PHP 1,000.00.`,
            actions: [first, second, browse],
        } };
        const paragraphs = context.paragraphs(message);
        assert.equal(paragraphs[0].actions.length, 0);
        assert.equal(paragraphs[1].text, `BIOSTAR H610M Motherboard — PHP 3,600.00. ${stock}.`);
        assert.equal(paragraphs[1].actions[0], first);
        assert.equal(paragraphs[2].actions[0], second);
        assert.equal(context.remaining(message).length, 1);
        assert.equal(context.remaining(message)[0], browse);
    }
    assert.equal(context.paragraphs({ content: 'Hello' })[0].text, 'Hello');
    assert.match(descriptor.template.content, /v-for="action in entry.actions"[\s\S]*?>\{\{ action.label \}\}<\/Link>/);
    const url = runInNewContext(`(${functionSource('actionUrl')})`, {
        ProductCatalogController: { show: { url: (id) => `/products/${id}` } },
    });
    for (const name of ['Atlas SSD', 'Produkto', 'Mga produkto', '<script>']) {
        assert.equal(url({ kind: 'product', id: 7, label: name }), '/products/7');
    }
    assert.equal(url({ kind: 'product', id: -1 }), null);
});

test("timeout preserves question and rejects a late response", async () => {
let timeout;
        let finish;
        const response = new Promise((resolve, reject) => {
            finish = resolve;
        });
        const context = {
            transcriptExpiresAt: 0,
            scheduleExpiry() {},
            safePresentation,
            conversationVersion: 1,
            quickMenu: { value: 'main' },
            showQuickQuestions: { value: true },
            nextMessageId: 1,
            readerScrolledBack: false,
            messages: { value: [] },
            pendingMessage: { value: '' },
            inputError: { value: '' },
            requestError: { value: '' },
            inquiry: {
                processing: false,
                message: 'monitor',
                clearErrors() {},
                cancel() {},
                post: () => response,
            },
            setTimeout(callback, delay) { assert.equal(delay, 30000); timeout = callback; return 1; },
            clearTimeout() {},
            scrollToLatest() {},
            persistContext() {},
            showReply() {},
            ChatbotController: { store: { url: () => '/chatbot' } },
        };
        const submit = runInNewContext(
            `(${functionSource('submitMessage')})`,
            context,
        );
        const request = submit();
        timeout();
        finish({ message: "late", context_token: "late" });
        await request;
        assert.equal(context.messages.value.length, 0);
        assert.match(context.requestError.value, /taking too long/);
        assert.equal(context.inquiry.message, 'monitor');
        assert.equal(context.pendingMessage.value, '');
});
