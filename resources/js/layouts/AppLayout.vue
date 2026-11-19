<script setup>
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';

const { breadcrumbs = [] } = defineProps({
    breadcrumbs: { type: Array },
});
</script>

<template>
    <AppSidebarLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppSidebarLayout>
</template>
