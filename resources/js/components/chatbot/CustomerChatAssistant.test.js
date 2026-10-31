import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { quickQuestions } from './quickQuestions.js';

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

function functionSource(name) {
    const declaration = script.scriptSetupAst.find(
        (node) => node.type === 'FunctionDeclaration' && node.id.name === name,
    );
    return descriptor.scriptSetup.content.slice(
        declaration.start,
        declaration.end,
    );
}

test('New chat clears transcript, token, errors and saved reading position', () => {
    const context = {
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
            scrollToLatest() {},
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
            scrollToLatest() {},
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
        'Shift+Enter submits instead of inserting a new line',
        { key: 'Enter', shiftKey: true },
        true,
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
