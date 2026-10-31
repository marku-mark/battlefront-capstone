<script setup>
import { useHttp, usePage } from '@inertiajs/vue3';
import { LoaderCircle, MessageSquareText, Send, X } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import ChatbotController from '@/actions/App/Http/Controllers/ChatbotController';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { quickQuestions } from './quickQuestions';

const inquiry = useHttp({ message: '', context_token: null });
const page = usePage();
const messages = ref([]);
const isOpen = ref(false);
const pendingMessage = ref('');
const inputError = ref('');
const requestError = ref('');
const conversation = ref(null);
const composer = ref(null);
const launcher = ref(null);
const isMounted = ref(false);
const quickMenu = ref('main');
const showQuickQuestions = ref(true);
let readerScrolledBack = false;
let savedScrollTop = 0;
let nextMessageId = 1;
let conversationVersion = 0;

onMounted(() => {
    isMounted.value = true;
});

function startNewChat() {
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

watch(() => page.props.auth?.user?.id ?? null, startNewChat);

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
    if (event.key === 'Enter' && !event.isComposing) {
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

async function submitMessage(quickQuestion = null) {
    if (inquiry.processing) {
        return;
    }

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
    if (quickQuestion !== null) {
        inquiry.message = message;
        inquiry.context_token = null;
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

        if (!response || submittedVersion !== conversationVersion) {
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
                source: response.source,
            },
        );
        inquiry.message = quickQuestion !== null ? draft : '';
        inquiry.context_token = response.context_token ?? null;
        showQuickQuestions.value = false;
        void showReply(replyId);
    } catch {
        if (submittedVersion === conversationVersion) {
            requestError.value ||=
                'Unable to send your question. Please try again.';
        }
    } finally {
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
                            Hello! I’m the Battlefront assistant.
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
                        aria-live="polite"
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
                                    <p
                                        v-for="(
                                            paragraph, index
                                        ) in message.content.split(/\n\s*\n/)"
                                        :key="index"
                                        class="whitespace-pre-wrap"
                                    >
                                        {{ paragraph }}
                                    </p>
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
                                Responding…
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
