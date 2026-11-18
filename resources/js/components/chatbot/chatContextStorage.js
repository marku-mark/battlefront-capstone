const key = 'battlefront_chat_context';
const lifetime = 15 * 60 * 1000;
const transcriptKey = 'battlefront_chat_transcript';

function privateInput(text) {
    return [
        /\b(?:password|passcode|senha|kontrasenyas|otp|pin|secret|credential|card number|cvv|api[ _-]?key)\s*(?::|=|\bis\b|\bko\b|\bnako\b)?\s*[\w@!#$%*.-]*\d[\w@!#$%*.-]*/iu,
        /\b(?:sk-|AIza)[a-z0-9_-]{12,}\b|\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b/iu,
        /\b(?:password|passcode|api[\s_-]*(?:key|secret|token)|access[\s_-]*token|internal[\s_-]*note)\s*(?::|=|\bis\b)\s*\S+/iu,
        /\bbearer\s+[a-z0-9._~+/=-]+/iu,
        /\bpayment-proofs[/\\]\S+/iu,
        /\bpayment[\s_-]*proof(?:[\s_-]*path)?\s*[:=]\s*\S+/iu,
        /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu,
        /(?<!\d)(?:\+63[\s-]?|0)9(?:[\s-]?\d){9}(?!\d)/u,
    ].some((pattern) => pattern.test(text));
}

function savedMessage(message) {
    return message.role === 'customer' && privateInput(message.content)
        ? {
              ...message,
              content: 'Question omitted from saved history for privacy.',
              presentation: null,
          }
        : message;
}

export function safePresentation(value, nested = false) {
    if (!value || typeof value.intro !== 'string') return null;
    const text = (value) => typeof value === 'string' && value.length <= 12000;
    return {
        ...(!nested && Array.isArray(value.sections) && value.sections.length > 0 ? {
            sections: value.sections.slice(0, 3)
                .filter((section) => section && text(section.heading) && typeof section.intro === 'string')
                .map((section) => ({ ...safePresentation(section, true), heading: section.heading })),
        } : {}),
        intro: value.intro.slice(0, 12000),
        clarification: value.clarification === true,
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
        const containsPrivateInput = privateInput(saved.draft) || saved.messages.some(
            (message) => message.role === 'customer' && privateInput(message.content),
        );
        saved.draft = privateInput(saved.draft) ? '' : saved.draft;
        saved.messages = saved.messages.map(savedMessage).map((message, index) => ({
            id: index + 1,
            role: message.role,
            content: message.content,
            source: message.source === 'gemini' ? 'gemini' : 'fallback',
            presentation: safePresentation(message.presentation),
        }));
        if (containsPrivateInput) {
            writeChatTranscript(storage, identity, saved, saved.expiresAt);
        }
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
        const messages = snapshot.messages.slice(-60).map(savedMessage);
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
