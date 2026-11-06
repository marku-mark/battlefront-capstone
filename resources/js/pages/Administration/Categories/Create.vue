<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import CategoryForm from '@/components/CategoryForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categories', href: CategoryController.index() },
            { title: 'Add category', href: CategoryController.create() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Add category" />
        <PageHeader title="Add category" />

        <section class="surface-panel border-border bg-card border p-4 sm:p-8">
            <CategoryForm
                :form="CategoryController.store.form()"
                submit-label="Create category"
            />
        </section>
    </main>
</template>
