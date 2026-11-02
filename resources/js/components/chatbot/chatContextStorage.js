const key = 'battlefront_chat_context';
const lifetime = 15 * 60 * 1000;
const transcriptKey = 'battlefront_chat_transcript';

export function safePresentation(value) {
    if (!value || typeof value.intro !== 'string') return null;
    const text = (value) => typeof value === 'string' && value.length <= 12000;
    return {
        intro: value.intro.slice(0, 12000),
        products: (Array.isArray(value.products) ? value.products : [])
            .filter(
                (product) =>
                    product &&
                    Number.isInteger(product.id) &&
                    product.id > 0 &&
                    text(product.name) &&
                    Number.isFinite(Number(product.price)) &&
                    text(product.stock),
            )
            .slice(0, 6),
        follow_up: text(value.follow_up) ? value.follow_up : '',
        query: text(value.query) ? value.query.slice(0, 200) : '',
        actions: (Array.isArray(value.actions) ? value.actions : [])
            .filter(
                (action) =>
                    action &&
                    [
                        'product',
                        'catalog',
                        'branches',
                        'orders',
                        'builder',
                        'login',
                    ].includes(action.kind) &&
                    text(action.label),
            )
            .slice(0, 8),
        choices: (Array.isArray(value.choices) ? value.choices : [])
            .filter(
                (choice) =>
                    choice &&
                    text(choice.label) &&
                    typeof choice.question === 'string' &&
                    choice.question.length <= 2000,
            )
            .slice(0, 6),
        facts: (Array.isArray(value.facts) ? value.facts : [])
            .filter((fact) => fact && text(fact.label) && text(fact.value))
            .slice(0, 8),
    };
}

export function clearChatTranscript(storage) {
    try {
        storage?.removeItem(transcriptKey);
    } catch {
        // Browser storage is optional.
    }
}

export function readChatTranscript(storage, identity, now = Date.now()) {
    try {
        const raw = storage?.getItem(transcriptKey);
        if (!raw) return null;
        if (raw.length > 200000) throw new Error('oversized transcript');
        const saved = JSON.parse(raw);
        if (
            !identity ||
            saved.version !== 1 ||
            saved.identity !== identity ||
            !Number.isFinite(saved.expiresAt) ||
            saved.expiresAt <= now ||
            saved.expiresAt > now + lifetime ||
            !Array.isArray(saved.messages) ||
            saved.messages.length > 60 ||
            saved.messages.some(
                (message) =>
                    !['customer', 'assistant'].includes(message.role) ||
                    typeof message.content !== 'string' ||
                    message.content.length > 12000,
            ) ||
            typeof saved.draft !== 'string' ||
            saved.draft.length > 2000
        ) {
            throw new Error('invalid transcript');
        }
        saved.messages = saved.messages.map((message, index) => ({
            id: index + 1,
            role: message.role,
            content: message.content,
            source: message.source === 'gemini' ? 'gemini' : 'fallback',
            presentation: safePresentation(message.presentation),
        }));
        return saved;
    } catch {
        clearChatTranscript(storage);
        return null;
    }
}

export function writeChatTranscript(storage, identity, snapshot, expiresAt) {
    try {
        if (
            !identity ||
            !Number.isFinite(expiresAt) ||
            expiresAt <= Date.now()
        ) {
            clearChatTranscript(storage);
            return;
        }
        const privateInput = (text) =>
            /\b(?:password|passcode|otp|pin|secret|api[ _-]?key)\s*(?::|=|is)?\s*\S*\d\S*|\b(?:password|passcode|api[ _-]?key)\s*[:=]\s*\S+|\bBearer\s+\S+|[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:\+63|0)9[\d -]{9,}/iu.test(
                text,
            );
        const messages = snapshot.messages.slice(-60).map((message) =>
            message.role === 'customer' && privateInput(message.content)
                ? {
                      ...message,
                      content:
                          'Question omitted from saved history for privacy.',
                  }
                : message,
        );
        const saved = JSON.stringify({
            ...snapshot,
            messages,
            draft: privateInput(snapshot.draft) ? '' : snapshot.draft,
            version: 1,
            identity,
            expiresAt,
        });
        if (saved.length > 200000) {
            clearChatTranscript(storage);
            return;
        }
        storage?.setItem(transcriptKey, saved);
    } catch {
        clearChatTranscript(storage);
    }
}

export function clearChatContext(storage) {
    try {
        storage?.removeItem(key);
    } catch {
        // Storage can be disabled by the browser; current-page chat still works.
    }
}

export function readChatContext(storage, identity, now = Date.now()) {
    try {
        const saved = JSON.parse(storage?.getItem(key) ?? 'null');
        if (
            !identity ||
            saved?.identity !== identity ||
            typeof saved.token !== 'string' ||
            !saved.token ||
            saved.token.length > 16384 ||
            !Number.isFinite(saved.expiresAt) ||
            saved.expiresAt <= now ||
            saved.expiresAt > now + lifetime
        ) {
            clearChatContext(storage);
            return null;
        }
        return saved.token;
    } catch {
        clearChatContext(storage);
        return null;
    }
}

export function writeChatContext(storage, identity, token, now = Date.now()) {
    if (
        !identity ||
        typeof token !== 'string' ||
        !token ||
        token.length > 16384
    ) {
        clearChatContext(storage);
        return;
    }
    try {
        storage?.setItem(
            key,
            JSON.stringify({ identity, token, expiresAt: now + lifetime }),
        );
    } catch {
        clearChatContext(storage);
    }
}
