<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import ChatbotKnowledgeForm from '@/components/ChatbotKnowledgeForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
            },
            {
                title: 'Add FAQ',
                href: ChatbotKnowledgeController.create(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Add chatbot FAQ" />
        <PageHeader
            title="Add chatbot FAQ"
            description="Check the question before saving."
        />

        <section class="surface-panel border-border bg-card border p-4 sm:p-8">
            <ChatbotKnowledgeForm
                :form="ChatbotKnowledgeController.store.form()"
                submit-label="Create FAQ"
            />
        </section>
    </main>
</template>
