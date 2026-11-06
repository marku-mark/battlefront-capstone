<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';
import { Badge } from '@/components/ui/badge';

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
                title: 'Edit FAQ',
                href: ChatbotKnowledgeController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Edit chatbot FAQ" />
        <PageHeader title="Edit chatbot FAQ">
            <template #metadata>
                <Badge :variant="knowledge.is_active ? 'secondary' : 'outline'">
                    {{ knowledge.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </template>
        </PageHeader>

        <section class="surface-panel border-border bg-card border p-4 sm:p-8">
            <ChatbotKnowledgeForm
                :knowledge="knowledge"
                :form="ChatbotKnowledgeController.update.form(knowledge.id)"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
