<script setup>
import { Link, useHttp, usePage } from '@inertiajs/vue3';
import { LoaderCircle, MessageSquareText, Send, X } from '@lucide/vue';
import { nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import ProductCatalogController from '@/actions/App/Http/Controllers/ProductCatalogController';
import BranchController from '@/actions/App/Http/Controllers/BranchController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import PcBuilderController from '@/actions/App/Http/Controllers/PcBuilderController';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { quickQuestions } from './quickQuestions';
import {
    clearChatContext,
    readChatContext,
    writeChatContext,
    clearChatTranscript,
    readChatTranscript,
    writeChatTranscript,
    safePresentation,
} from './chatContextStorage';

const inquiry = useHttp({ message: '', context_token: null });
const page = usePage();
const messages = ref([]);
const isOpen = ref(false);
const pendingMessage = ref('');
const inputError = ref('');
const requestError = ref('');
const waitingLonger = ref(false);
let waitingTimer;
const conversation = ref(null);
const composer = ref(null);
const launcher = ref(null);
const isMounted = ref(false);
const announceMessages = ref(false);
const quickMenu = ref('main');
const showQuickQuestions = ref(true);
let readerScrolledBack = false;
let savedScrollTop = 0;
let nextMessageId = 1;
let conversationVersion = 0;
let transcriptExpiresAt = 0;
let expiryTimer;
let restoring = true;

onMounted(() => {
    isMounted.value = true;
    if (page.props.chatbotMemory?.identity) {
        inquiry.context_token = readChatContext(
            contextStorage(),
            page.props.chatbotMemory.identity,
        );
    } else {
        clearChatContext(contextStorage());
    }
    const saved = readChatTranscript(
        contextStorage(),
        page.props.chatbotMemory?.identity,
    );
    if (saved) {
        messages.value = saved.messages;
        nextMessageId =
            Math.max(
                0,
                ...saved.messages.map((message) => Number(message.id) || 0),
            ) + 1;
        inquiry.message = saved.draft;
        isOpen.value = saved.isOpen === true;
        savedScrollTop = Number.isFinite(saved.scrollTop) ? saved.scrollTop : 0;
        showQuickQuestions.value = messages.value.length === 0;
        transcriptExpiresAt = saved.expiresAt;
        if (saved.pending)
            requestError.value =
                'Your previous question was interrupted. Please send it again.';
        void nextTick(() => {
            if (conversation.value)
                conversation.value.scrollTop = savedScrollTop;
        });
        scheduleExpiry();
    }
    restoring = false;
    void nextTick(() => {
        announceMessages.value = true;
    });
    window.addEventListener('pagehide', persistTranscript);
});

onBeforeUnmount(() => {
    clearTimeout(waitingTimer);
    persistTranscript();
    conversationVersion++;
    clearTimeout(expiryTimer);
    window.removeEventListener('pagehide', persistTranscript);
});

watch(pendingMessage, (message) => {
    clearTimeout(waitingTimer);
    waitingLonger.value = false;
    if (message) {
        waitingTimer = setTimeout(() => {
            waitingLonger.value = true;
        }, 5000);
    }
});

function scheduleExpiry() {
    clearTimeout(expiryTimer);
    expiryTimer = setTimeout(
        startNewChat,
        Math.max(0, transcriptExpiresAt - Date.now()),
    );
}

function persistTranscript() {
    if (restoring) return;
    if (!transcriptExpiresAt && inquiry.message) {
        transcriptExpiresAt = Date.now() + 15 * 60 * 1000;
        scheduleExpiry();
    }
    if (!transcriptExpiresAt) return;
    writeChatTranscript(
        contextStorage(),
        page.props.chatbotMemory?.identity,
        {
            messages: messages.value,
            draft: pendingMessage.value || inquiry.message,
            pending: Boolean(pendingMessage.value),
            isOpen: isOpen.value,
            scrollTop: conversation.value?.scrollTop ?? savedScrollTop,
        },
        transcriptExpiresAt,
    );
}

watch(
    [messages, () => inquiry.message, isOpen, pendingMessage],
    persistTranscript,
    { deep: true },
);

function contextStorage() {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

function persistContext() {
    writeChatContext(
        contextStorage(),
        page.props.chatbotMemory?.identity,
        inquiry.context_token,
    );
}

function startNewChat() {
    clearChatContext(contextStorage());
    clearChatTranscript(contextStorage());
    clearTimeout(expiryTimer);
    transcriptExpiresAt = 0;
    conversationVersion++;
    messages.value = [];
    inquiry.message = '';
    inquiry.context_token = null;
    inquiry.clearErrors();
    inputError.value = '';
    requestError.value = '';
    pendingMessage.value = '';
    quickMenu.value = 'main';
    showQuickQuestions.value = true;
    readerScrolledBack = false;
    savedScrollTop = 0;
}

watch(
    [
        () => page.props.auth?.user?.id ?? null,
        () => page.props.chatbotMemory?.identity,
    ],
    startNewChat,
);

async function openPanel() {
    isOpen.value = true;
    await nextTick();
    composer.value?.$el?.focus();
    if (conversation.value) {
        conversation.value.scrollTop = savedScrollTop;
    }
}

async function closePanel() {
    savedScrollTop = conversation.value?.scrollTop ?? 0;
    isOpen.value = false;
    await nextTick();
    launcher.value?.$el?.focus();
}

function handleComposerKeydown(event) {
    if (event.key === 'Enter' && !event.isComposing && !event.shiftKey) {
        event.preventDefault();
        void submitMessage();
    }
}

async function scrollToLatest() {
    await nextTick();

    if (conversation.value) {
        conversation.value.scrollTop = conversation.value.scrollHeight;
    }
}

function trackReadingPosition() {
    const panel = conversation.value;
    if (inquiry.processing && panel) {
        readerScrolledBack =
            panel.scrollHeight - panel.scrollTop - panel.clientHeight > 48;
    }
}

async function showReply(messageId) {
    await nextTick();
    const panel = conversation.value;
    const reply = panel?.querySelector(`[data-message-id="${messageId}"]`);
    if (panel && reply && !readerScrolledBack) {
        panel.scrollTop +=
            reply.getBoundingClientRect().top -
            panel.getBoundingClientRect().top -
            16;
    }
}

async function chooseQuickQuestion(item) {
    if (inquiry.processing) return;
    if (item.menu) {
        quickMenu.value = item.menu;
        return;
    }
    await submitMessage(item.question);
}

function messageParagraphs(message) {
    const paragraphs = (message.presentation?.intro ?? message.content).split(/\n\s*\n/);
    const actions = message.presentation?.actions ?? [];
    return paragraphs.map((text, index) => ({
        text,
        actions: actions.filter((action) => action.kind === 'product'
            && text.includes(action.label)
            && !paragraphs.slice(index + 1).some((paragraph) => paragraph.includes(action.label))),
    }));
}

function remainingActions(message) {
    const placed = messageParagraphs(message).flatMap((paragraph) => paragraph.actions);
    return (message.presentation?.actions ?? []).filter((action) => !placed.includes(action));
}

function actionUrl(action) {
    switch (action.kind) {
        case 'product':
            return Number.isInteger(action.id) && action.id > 0
                ? ProductCatalogController.show.url(action.id)
                : null;
        case 'catalog':
            return ProductCatalogController.index.url({
                query: {
                    q: typeof action.query === 'string' ? action.query : '',
                },
            });
        case 'branches':
            return BranchController.index.url();
        case 'orders':
            return OrderController.index.url();
        case 'builder':
            return PcBuilderController.index.url();
        case 'login':
            return login.url();
        default:
            return null;
    }
}

async function submitMessage(quickQuestion = null, freshContext = true) {
    if (inquiry.processing || pendingMessage.value) {
        return;
    }

    if (transcriptExpiresAt && transcriptExpiresAt <= Date.now())
        startNewChat();
    transcriptExpiresAt = Date.now() + 15 * 60 * 1000;
    scheduleExpiry();

    inputError.value = '';
    requestError.value = '';
    inquiry.clearErrors();

    const draft = inquiry.message;
    const previousToken = inquiry.context_token;
    const message = (quickQuestion ?? draft).trim();

    if (!message) {
        inputError.value = 'Please enter a question.';
        return;
    }

    pendingMessage.value = message;
    readerScrolledBack = false;
    const submittedVersion = conversationVersion;
    let receivedReply = false;
    let timedOut = false;
    const requestTimer = setTimeout(() => {
        timedOut = true;
        inquiry.cancel();
        if (submittedVersion === conversationVersion) {
            requestError.value = 'The connection is taking too long. Your question is saved; please try again.';
            pendingMessage.value = '';
        }
    }, 30000);
    if (quickQuestion !== null) {
        inquiry.message = message;
        if (freshContext) inquiry.context_token = null;
    }
    void scrollToLatest();

    try {
        const response = await inquiry.post(ChatbotController.store.url(), {
            onHttpException: (httpResponse) => {
                if (
                    submittedVersion === conversationVersion &&
                    httpResponse.status === 429
                ) {
                    requestError.value = JSON.parse(httpResponse.data).message;
                }
            },
        });

        if (timedOut || !response || submittedVersion !== conversationVersion) {
            return;
        }

        const replyId = nextMessageId + 1;
        receivedReply = true;
        messages.value.push(
            { id: nextMessageId++, role: 'customer', content: message },
            {
                id: nextMessageId++,
                role: 'assistant',
                content: response.message,
                presentation: safePresentation(response.presentation),
                source: response.source,
            },
        );
        inquiry.message = quickQuestion !== null ? draft : '';
        inquiry.context_token = response.context_token ?? null;
        persistContext();
        showQuickQuestions.value = false;
        void showReply(replyId);
    } catch {
        if (submittedVersion === conversationVersion) {
            requestError.value ||=
                'Unable to send your question. Please try again.';
        }
    } finally {
        clearTimeout(requestTimer);
        if (submittedVersion === conversationVersion) {
            if (quickQuestion !== null) {
                inquiry.message = draft;
                if (!receivedReply) inquiry.context_token = previousToken;
            }
            pendingMessage.value = '';
        }
    }
}
</script>

<template>
    <Teleport v-if="isMounted" to="body">
        <div
            data-customer-chat
            class="fixed z-40 sm:right-6 sm:bottom-6"
            :class="
                isOpen
                    ? 'inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] flex justify-end sm:inset-x-auto sm:w-96'
                    : 'pointer-events-none inset-x-0 bottom-0 flex h-(--mobile-support-height) items-center justify-end px-4 pb-[env(safe-area-inset-bottom)] sm:inset-x-auto sm:h-auto sm:p-0'
            "
        >
            <Button
                v-if="!isOpen"
                ref="launcher"
                type="button"
                size="lg"
                class="shadow-card pointer-events-auto size-12 min-h-12 rounded-full p-0 sm:w-auto sm:px-5"
                aria-controls="battlefront-chat-panel"
                aria-expanded="false"
                @click="openPanel"
            >
                <MessageSquareText class="size-5" aria-hidden="true" />
                <span class="sr-only sm:not-sr-only">Ask Battlefront</span>
            </Button>

            <section
                v-else
                id="battlefront-chat-panel"
                class="border-border bg-card text-card-foreground surface-panel flex h-[min(36rem,calc(100dvh-2rem-env(safe-area-inset-bottom)))] w-full max-w-96 flex-col overflow-hidden border"
                aria-label="Battlefront customer support assistant"
                @keydown.esc="closePanel"
            >
                <header
                    class="border-border flex shrink-0 items-center gap-3 border-b px-4 py-3"
                >
                    <span
                        class="bg-secondary text-primary-ink flex size-9 shrink-0 items-center justify-center rounded-md"
                    >
                        <MessageSquareText class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">
                            Battlefront assistant
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Customer support
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="inquiry.processing"
                        @click="startNewChat"
                    >
                        New chat
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Close chat"
                        @click="closePanel"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </Button>
                </header>

                <div
                    ref="conversation"
                    class="min-h-0 flex-1 overflow-y-auto px-4 py-4"
                    :aria-busy="inquiry.processing"
                    @scroll="trackReadingPosition"
                >
                    <div
                        v-if="messages.length === 0 && !inquiry.processing"
                        class="flex flex-col items-center justify-center text-center"
                    >
                        <MessageSquareText
                            class="text-primary-ink size-8"
                            aria-hidden="true"
                        />
                        <h2 class="mt-3 font-semibold">
                            Hello! I'm the Battlefront assistant.
                        </h2>
                        <p
                            class="text-muted-foreground mt-2 max-w-xs text-sm leading-5"
                        >
                            Choose a question below or type your own.
                        </p>
                    </div>

                    <ol
                        v-else
                        class="flex flex-col gap-3"
                        role="log"
                        :aria-live="announceMessages ? 'polite' : 'off'"
                        aria-relevant="additions"
                        aria-label="Chatbot messages"
                    >
                        <li
                            v-for="message in messages"
                            :key="message.id"
                            :data-message-id="message.id"
                            class="flex min-w-0"
                            :class="
                                message.role === 'customer'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <article
                                class="max-w-[88%] min-w-0 rounded-md border px-3 py-2"
                                :class="
                                    message.role === 'customer'
                                        ? 'border-primary/30 bg-primary/10'
                                        : message.source === 'fallback'
                                          ? 'border-border bg-secondary/60'
                                          : 'border-border bg-background'
                                "
                            >
                                <p
                                    class="text-muted-foreground text-xs font-semibold"
                                >
                                    {{
                                        message.role === 'customer'
                                            ? 'You'
                                            : 'Battlefront assistant'
                                    }}
                                </p>
                                <div
                                    class="mt-1 space-y-3 text-sm leading-6 [overflow-wrap:anywhere]"
                                >
                                    <div
                                        v-for="(entry, index) in messageParagraphs(message)"
                                        :key="index"
                                    >
                                        <ul
                                            v-if="
                                                entry.text
                                                    .trim()
                                                    .startsWith('- ')
                                            "
                                            class="list-disc space-y-1 pl-4"
                                        >
                                            <li
                                                v-for="(
                                                    line, lineIndex
                                                ) in entry.text
                                                    .trim()
                                                    .split('\n')"
                                                :key="lineIndex"
                                            >
                                                {{ line.replace(/^-\s*/, '') }}
                                            </li>
                                        </ul>
                                        <p v-else class="whitespace-pre-wrap">
                                            {{ entry.text }}
                                        </p>
                                        <Link
                                            v-for="action in entry.actions"
                                            :key="action.id"
                                            :href="actionUrl(action)"
                                            class="text-primary-ink focus-visible:ring-ring block underline focus-visible:ring-2"
                                        >{{ action.label }}</Link>
                                    </div>
                                    <template v-if="message.presentation">
                                        <dl
                                            v-if="
                                                message.presentation.facts
                                                    ?.length
                                            "
                                            class="space-y-2"
                                        >
                                            <div
                                                v-for="(fact, index) in message
                                                    .presentation.facts"
                                                :key="`fact-${index}`"
                                            >
                                                <dt class="font-semibold">
                                                    {{ fact.label }}
                                                </dt>
                                                <dd>{{ fact.value }}</dd>
                                            </div>
                                        </dl>
                                        <div
                                            v-for="product in message
                                                .presentation.products"
                                            :key="product.id"
                                            class="border-border bg-card rounded-md border p-3"
                                        >
                                            <p class="font-semibold">
                                                {{ product.name }}
                                            </p>
                                            <p>
                                                PHP
                                                {{
                                                    Number(
                                                        product.price,
                                                    ).toLocaleString('en-PH', {
                                                        minimumFractionDigits: 2,
                                                        maximumFractionDigits: 2,
                                                    })
                                                }}
                                                · {{ product.stock }}
                                            </p>
                                            <p
                                                v-if="product.notice"
                                                class="text-muted-foreground"
                                            >
                                                {{ product.notice }}
                                            </p>
                                            <Link
                                                :href="ProductCatalogController.show.url(product.id)"
                                                class="text-primary-ink focus-visible:ring-ring block underline focus-visible:ring-2"
                                            >View product</Link>
                                        </div>
                                        <p>
                                            {{ message.presentation.follow_up }}
                                        </p>
                                        <Link
                                            v-if="message.presentation.query"
                                            :href="
                                                ProductCatalogController.index.url(
                                                    {
                                                        query: {
                                                            q: message
                                                                .presentation
                                                                .query,
                                                        },
                                                    },
                                                )
                                            "
                                            class="text-primary-ink focus-visible:ring-ring underline focus-visible:ring-2"
                                            >Browse matching products</Link
                                        >
                                        <template
                                            v-for="(action, index) in remainingActions(message)"
                                            :key="`action-${index}`"
                                        >
                                                <Link
                                                    v-if="actionUrl(action)"
                                                    :href="actionUrl(action)"
                                                    class="text-primary-ink focus-visible:ring-ring block underline focus-visible:ring-2"
                                                >{{ action.label }}</Link>
                                        </template>
                                        <Button
                                            v-for="(choice, index) in message
                                                .presentation.choices ?? []"
                                            :key="`choice-${index}`"
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-auto text-left whitespace-normal"
                                            :disabled="inquiry.processing"
                                            @click="
                                                submitMessage(
                                                    choice.question,
                                                    false,
                                                )
                                            "
                                            >{{ choice.label }}</Button
                                        >
                                    </template>
                                </div>
                            </article>
                        </li>
                        <li v-if="inquiry.processing" class="flex justify-end">
                            <div
                                class="border-primary/30 bg-primary/10 max-w-[88%] rounded-md border px-3 py-2 text-sm wrap-break-word whitespace-pre-wrap"
                            >
                                {{ pendingMessage }}
                            </div>
                        </li>
                        <li
                            v-if="inquiry.processing"
                            class="flex justify-start"
                        >
                            <div
                                class="border-border bg-background flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                role="status"
                            >
                                <LoaderCircle
                                    class="text-primary-ink size-4 animate-spin motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                                {{ waitingLonger ? 'Still checking your question…' : 'Responding…' }}
                            </div>
                        </li>
                    </ol>
                    <div
                        v-if="showQuickQuestions && !inquiry.processing"
                        id="battlefront-quick-questions"
                        class="mt-4"
                        role="group"
                        aria-label="Quick questions"
                    >
                        <Button
                            v-if="quickMenu !== 'main'"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="quickMenu = 'main'"
                            >Back to topics</Button
                        >
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="item in quickQuestions[quickMenu]"
                                :key="item.label"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="min-h-10 max-w-full text-left whitespace-normal"
                                @click="chooseQuickQuestion(item)"
                                >{{ item.label }}</Button
                            >
                        </div>
                    </div>
                </div>

                <form
                    class="border-border bg-card shrink-0 border-t p-3"
                    @submit.prevent="submitMessage()"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="inquiry.processing"
                        :aria-expanded="showQuickQuestions"
                        aria-controls="battlefront-quick-questions"
                        @click="
                            showQuickQuestions = !showQuickQuestions;
                            quickMenu = 'main';
                        "
                        >Quick questions</Button
                    >
                    <label
                        for="floating-chatbot-message"
                        class="text-sm font-semibold"
                        >Your question</label
                    >
                    <Textarea
                        id="floating-chatbot-message"
                        ref="composer"
                        v-model="inquiry.message"
                        class="mt-2 max-h-28 min-h-18 resize-y"
                        placeholder="Ask about a product, order, or store…"
                        :disabled="inquiry.processing"
                        :aria-invalid="
                            Boolean(inputError || inquiry.errors.message)
                        "
                        aria-describedby="floating-chatbot-error"
                        @input="inputError = ''"
                        @keydown="handleComposerKeydown"
                    />
                    <p
                        v-if="inputError || inquiry.errors.message"
                        id="floating-chatbot-error"
                        class="text-destructive mt-2 text-sm"
                        role="alert"
                    >
                        {{ inputError || inquiry.errors.message }}
                    </p>
                    <p
                        v-if="requestError"
                        class="text-destructive mt-2 text-sm"
                        role="alert"
                    >
                        {{ requestError }}
                    </p>
                    <div class="mt-2 flex items-center justify-end gap-2">
                        <Button
                            v-if="requestError && inquiry.message.trim()"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="inquiry.processing"
                            @click="submitMessage()"
                        >Retry</Button>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="inquiry.processing"
                        >
                            <LoaderCircle
                                v-if="inquiry.processing"
                                class="size-4 animate-spin motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                            <Send v-else class="size-4" aria-hidden="true" />
                            {{ inquiry.processing ? 'Sending…' : 'Send' }}
                        </Button>
                    </div>
                </form>
            </section>
        </div>
    </Teleport>
</template>
