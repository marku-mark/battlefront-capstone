<script setup>
import { Form, Head, Link, useHttp } from '@inertiajs/vue3';
import {
    ChevronDown,
    Eye,
    MessageSquareText,
    Pencil,
    Plus,
    RotateCcw,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import ChatbotKnowledgeActivationController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeActivationController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgePreviewController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgePreviewController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import InputError from '@/components/InputError.vue';
import SearchField from '@/components/SearchField.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const props = defineProps({
    knowledge: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const previewOpen = ref(false);
const preview = useHttp({ message: '' });
const previewResult = ref(null);
const previewError = ref('');
const testedQuestion = ref('');
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters.q,
    currentSearch: () => props.filters.q,
    route: ChatbotKnowledgeController.index,
});
watch(
    () => props.filters.q,
    (query) => {
        search.value = query ?? '';
    },
);
const categoryLabels = {
    faq: 'FAQ',
    product: 'Product',
    store: 'Store',
    order: 'Order',
    unsupported: 'Unsupported',
};

async function testQuestion() {
    if (preview.processing) {
        return;
    }

    previewResult.value = null;
    previewError.value = '';
    testedQuestion.value = preview.message.trim();
    preview.clearErrors();

    try {
        previewResult.value = await preview.post(
            ChatbotKnowledgePreviewController.url(),
        );
    } catch {
        previewError.value = 'Unable to test this question. Please try again.';
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Chatbot FAQs" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <MessageSquareText class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Customer support content
                        </p>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            Chatbot FAQs
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            Manage approved support FAQs. Live facts and
                            application rules are answered automatically.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="ChatbotKnowledgeController.create()">
                        <Plus />
                        Add FAQ
                    </Link>
                </Button>
            </div>

            <SearchField
                id="knowledge-search"
                v-model="search"
                class="mt-5 max-w-xl"
                label="Search FAQs"
                placeholder="Question or response"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
        </section>

        <Collapsible
            v-model:open="previewOpen"
            class="border-border bg-card border"
        >
            <h2>
                <CollapsibleTrigger as-child>
                    <button
                        type="button"
                        class="focus-visible:ring-ring flex w-full items-center justify-between gap-4 p-6 text-left focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span class="text-lg font-semibold"
                            >Test chatbot routing</span
                        >
                        <ChevronDown
                            class="size-5 shrink-0"
                            :class="{ 'rotate-180': previewOpen }"
                            aria-hidden="true"
                        />
                    </button>
                </CollapsibleTrigger>
            </h2>
            <CollapsibleContent class="px-6 pb-6">
                <p class="text-muted-foreground max-w-3xl text-sm leading-6">
                    Enter a customer question to see its category and matching
                    active eligible FAQs across all records. This checks routing
                    and saved FAQs only; it does not retrieve live product,
                    branch, or customer order data, or generate a chatbot reply.
                </p>
                <form class="mt-5 space-y-3" @submit.prevent="testQuestion">
                    <Label for="knowledge-preview-message"
                        >Customer question</Label
                    >
                    <Input
                        id="knowledge-preview-message"
                        v-model="preview.message"
                        maxlength="1000"
                        placeholder="Enter a customer question…"
                        :disabled="preview.processing"
                        :aria-invalid="Boolean(preview.errors.message)"
                        aria-describedby="knowledge-preview-error"
                        required
                    />
                    <InputError
                        id="knowledge-preview-error"
                        :message="preview.errors.message"
                    />
                    <Button type="submit" :disabled="preview.processing">
                        <Spinner v-if="preview.processing" />
                        {{ preview.processing ? 'Testing…' : 'Test question' }}
                    </Button>
                </form>
                <p
                    v-if="previewError"
                    role="alert"
                    class="text-destructive mt-4 text-sm"
                >
                    {{ previewError }}
                </p>
                <div
                    class="mt-5 space-y-3 text-sm"
                    aria-live="polite"
                    :aria-busy="preview.processing"
                >
                    <template v-if="previewResult">
                        <p class="text-muted-foreground">
                            Results for: {{ testedQuestion }}
                        </p>
                        <p v-if="previewResult.category">
                            Selected category:
                            <strong>{{
                                categoryLabels[previewResult.category]
                            }}</strong>
                        </p>
                        <p>{{ previewResult.explanation }}</p>
                        <template v-if="previewResult.matches.length">
                            <h3 class="pt-2 font-semibold">Matched FAQs</h3>
                            <ol class="space-y-4">
                                <li
                                    v-for="match in previewResult.matches"
                                    :key="match.id"
                                    class="border-border border-t pt-4"
                                >
                                    <div
                                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="font-semibold">
                                                {{ match.question_pattern }}
                                            </p>
                                            <p
                                                class="text-muted-foreground mt-1"
                                            >
                                                {{
                                                    match.exact
                                                        ? 'Exact wording'
                                                        : 'Related wording'
                                                }}
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            as-child
                                            class="shrink-0 self-start"
                                        >
                                            <Link
                                                :href="
                                                    ChatbotKnowledgeController.show(
                                                        match.id,
                                                    )
                                                "
                                            >
                                                <Eye aria-hidden="true" />
                                                View record
                                            </Link>
                                        </Button>
                                    </div>
                                    <p class="mt-3 whitespace-pre-wrap">
                                        {{ match.response_template }}
                                    </p>
                                </li>
                            </ol>
                        </template>
                    </template>
                </div>
            </CollapsibleContent>
        </Collapsible>

        <section aria-labelledby="knowledge-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ knowledge.total }} records
                </p>
                <h2 id="knowledge-list-heading" class="text-xl font-semibold">
                    FAQ and legacy records
                </h2>
            </div>

            <div
                v-if="knowledge.data.length === 0"
                class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
            >
                <div>
                    <MessageSquareText
                        class="text-muted-foreground mx-auto size-9"
                    />
                    <p class="mt-3 font-medium">No chatbot FAQs available</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Add approved support guidance to begin managing FAQs.
                    </p>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="item in knowledge.data"
                    :key="item.id"
                    class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="outline">{{
                                item.category_label
                            }}</Badge>
                            <Badge
                                :variant="
                                    item.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ item.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <Badge
                                v-if="!item.eligible_for_faq"
                                variant="outline"
                                >Needs review</Badge
                            >
                        </div>
                        <p
                            v-if="!item.eligible_for_faq"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            {{ item.eligibility_reason }}
                        </p>
                        <h3 class="mt-3 font-semibold">
                            {{ item.question_pattern }}
                        </h3>
                        <p
                            class="text-muted-foreground mt-2 line-clamp-2 max-w-3xl text-sm leading-6 whitespace-pre-line"
                        >
                            {{ item.response_template }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="ChatbotKnowledgeController.show(item.id)"
                            >
                                <Eye />
                                View
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="ChatbotKnowledgeController.edit(item.id)"
                            >
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <DeactivationDialog
                            v-if="item.is_active"
                            :name="item.question_pattern"
                            :form="
                                ChatbotKnowledgeActivationController.form(
                                    item.id,
                                )
                            "
                            description="This record will remain available to administrators and can be reactivated later."
                        />
                        <Form
                            v-else
                            v-bind="
                                ChatbotKnowledgeActivationController.form(
                                    item.id,
                                )
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ errors, processing }"
                        >
                            <input type="hidden" name="is_active" value="1" />
                            <InputError :message="errors.is_active" />
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing || !item.eligible_for_faq"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Reactivate
                            </Button>
                        </Form>
                    </div>
                </article>
            </div>

            <CatalogPagination
                :current-page="knowledge.current_page"
                :last-page="knowledge.last_page"
                :route="ChatbotKnowledgeController.index"
                label="Chatbot FAQs pages"
            />
        </section>
    </main>
</template>
