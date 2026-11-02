import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clearChatContext,
    readChatContext,
    writeChatContext,
    readChatTranscript,
    writeChatTranscript,
    safePresentation,
} from './chatContextStorage.js';

test('transcript restores messages and draft only within its identity and original expiry', () => {
    const browser = storage();
    const now = Date.now();
    writeChatTranscript(
        browser,
        'guest-a',
        {
            messages: [{ role: 'assistant', content: 'Hello' }],
            draft: 'SSD',
            isOpen: true,
        },
        now + 60000,
    );
    assert.equal(readChatTranscript(browser, 'guest-a', now).draft, 'SSD');
    assert.equal(readChatTranscript(browser, 'guest-a', now + 60000), null);
    writeChatTranscript(
        browser,
        'guest-a',
        { messages: [], draft: '' },
        now + 60000,
    );
    assert.equal(readChatTranscript(browser, 'customer-b', now), null);
});

test('unsafe actions and malformed presentation arrays are discarded', () => {
    const presentation = safePresentation({
        intro: '<script>unsafe</script>',
        products: {},
        actions: [{ kind: 'url', label: 'Click', url: 'javascript:alert(1)' }],
        choices: null,
    });
    assert.equal(presentation.intro, '<script>unsafe</script>');
    assert.deepEqual(presentation.actions, []);
    assert.deepEqual(presentation.products, []);
    assert.deepEqual(presentation.choices, []);
});

test('saved drafts and customer messages omit submitted secrets while keeping public replies', () => {
    const browser = storage();
    const now = Date.now();
    writeChatTranscript(
        browser,
        'customer-a',
        {
            messages: [
                { role: 'customer', content: 'My OTP is 123456' },
                {
                    role: 'assistant',
                    content: 'Contact store@example.test for help.',
                },
            ],
            draft: 'password: sample123',
            pending: true,
            isOpen: true,
        },
        now + 60000,
    );
    const saved = readChatTranscript(browser, 'customer-a', now);
    assert.equal(saved.draft, '');
    assert.ok(!saved.messages[0].content.includes('123456'));
    assert.equal(
        saved.messages[1].content,
        'Contact store@example.test for help.',
    );
    assert.equal(saved.pending, true);
    assert.equal(saved.isOpen, true);
});

test('oversized and corrupted transcripts are cleared and denied storage remains optional', () => {
    const browser = storage();
    browser.setItem('battlefront_chat_transcript', '{bad json');
    assert.equal(readChatTranscript(browser, 'guest'), null);
    assert.equal(browser.entries.size, 0);
    const denied = {
        getItem() {
            throw new Error('denied');
        },
        setItem() {
            throw new Error('denied');
        },
        removeItem() {
            throw new Error('denied');
        },
    };
    assert.doesNotThrow(() =>
        writeChatTranscript(
            denied,
            'guest',
            { messages: [], draft: '' },
            Date.now() + 60000,
        ),
    );
    assert.equal(readChatTranscript(denied, 'guest'), null);
});

function storage() {
    const entries = new Map();
    return {
        entries,
        getItem: (key) => entries.get(key) ?? null,
        setItem: (key, value) => entries.set(key, value),
        removeItem: (key) => entries.delete(key),
    };
}

test('reload restores only an opaque token for the same identity before expiry', () => {
    const browser = storage();
    writeChatContext(browser, 'session-a', 'encrypted-context', 1000);
    assert.equal(
        readChatContext(browser, 'session-a', 2000),
        'encrypted-context',
    );
    const saved = JSON.parse([...browser.entries.values()][0]);
    assert.deepEqual(Object.keys(saved), ['identity', 'token', 'expiresAt']);
    assert.equal(saved.expiresAt, 901000);
    assert.equal(readChatContext(browser, 'session-a', 901000), null);
    assert.equal(browser.entries.size, 0);
});

test('account changes and New chat remove the previous token', () => {
    const browser = storage();
    writeChatContext(browser, 'session-a', 'encrypted-context', 1000);
    assert.equal(readChatContext(browser, 'session-b', 1000), null);
    assert.equal(browser.entries.size, 0);
    writeChatContext(browser, 'session-b', 'new-context', 1000);
    clearChatContext(browser);
    assert.equal(readChatContext(browser, 'session-b', 1000), null);
});

test('disabled memory and invalid server tokens clear stored context', () => {
    for (const token of [null, '', 'x'.repeat(16385)]) {
        const browser = storage();
        writeChatContext(browser, 'session-a', 'encrypted-context', 1000);
        writeChatContext(browser, 'session-a', token, 1000);
        assert.equal(browser.entries.size, 0);
    }
    const browser = storage();
    writeChatContext(browser, 'session-a', 'encrypted-context', 1000);
    writeChatContext(browser, null, 'encrypted-context', 1000);
    assert.equal(browser.entries.size, 0);
});

test('corrupt storage and denied browser storage fail without breaking chat', () => {
    const browser = storage();
    browser.setItem('battlefront_chat_context', '{bad json');
    assert.equal(readChatContext(browser, 'session-a', 1000), null);
    const unavailable = {
        getItem() {
            throw new Error('denied');
        },
        setItem() {
            throw new Error('denied');
        },
        removeItem() {
            throw new Error('denied');
        },
    };
    assert.doesNotThrow(() =>
        writeChatContext(unavailable, 'session-a', 'token'),
    );
    assert.equal(readChatContext(unavailable, 'session-a'), null);
    assert.equal(readChatContext(null, 'session-a'), null);
});
