<script setup>
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <div style="display: contents">
        <slot />
        <CustomerChatAssistant />
    </div>
</template>
