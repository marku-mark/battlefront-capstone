<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, RotateCcw } from '@lucide/vue';
import ChatbotKnowledgeActivationController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeActivationController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import InputError from '@/components/InputError.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

defineProps({
    knowledge: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
            },
            {
                title: 'FAQ record',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Chatbot FAQ record" />
        <PageHeader :title="knowledge.question_pattern">
            <template #metadata>
                <Badge :variant="knowledge.is_active ? 'secondary' : 'outline'">
                    {{ knowledge.is_active ? 'Active' : 'Inactive' }}
                </Badge>
                <span>{{ knowledge.category_label }}</span>
                <p
                    v-if="!knowledge.eligible_for_faq"
                    class="text-destructive w-full"
                >
                    Needs review:
                    {{ knowledge.eligibility_reason }}
                </p>
            </template>
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="ChatbotKnowledgeController.index()">
                        <ArrowLeft />
                        Back to FAQs
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <section aria-labelledby="response-heading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-sm">
                        Approved content
                    </p>
                    <h2 id="response-heading" class="text-xl font-semibold">
                        Approved answer
                    </h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="
                                ChatbotKnowledgeController.edit(knowledge.id)
                            "
                        >
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
                    <DeactivationDialog
                        v-if="knowledge.is_active"
                        :name="knowledge.question_pattern"
                        :form="
                            ChatbotKnowledgeActivationController.form(
                                knowledge.id,
                            )
                        "
                        description="This record will remain available to administrators and can be reactivated later."
                    />
                    <Form
                        v-else
                        v-bind="
                            ChatbotKnowledgeActivationController.form(
                                knowledge.id,
                            )
                        "
                        v-slot="{ errors, processing }"
                    >
                        <input type="hidden" name="is_active" value="1" />
                        <InputError :message="errors.is_active" />
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                processing || !knowledge.eligible_for_faq
                            "
                        >
                            <Spinner v-if="processing" />
                            <RotateCcw v-else />
                            Reactivate
                        </Button>
                    </Form>
                </div>
            </div>

            <div class="surface-panel border-border bg-card border p-4 sm:p-6">
                <p class="text-sm leading-7 break-words whitespace-pre-wrap">
                    {{ knowledge.response_template }}
                </p>
            </div>
        </section>
    </main>
</template>
