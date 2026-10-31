<script setup>
import { Button } from '@/components/ui/button';
defineProps({
    label: String,
    required: Boolean,
    items: Array,
    disabled: Boolean,
    multiple: Boolean,
});
defineEmits(['select', 'remove']);
</script>

<template>
    <section
        class="border-border grid gap-4 border-b py-6 last:border-0 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start xl:grid-cols-[9rem_minmax(0,1fr)_auto]"
    >
        <h3 class="text-sm font-semibold sm:pt-2">
            {{ label }}
            <span v-if="required" class="text-primary-ink" aria-label="required"
                >*</span
            >
        </h3>
        <div class="min-w-0">
            <div
                v-if="!items.length"
                class="border-border bg-muted/30 rounded-lg border border-dashed px-4 py-3"
            >
                <p class="text-muted-foreground text-sm">
                    No {{ label.toLowerCase() }} selected
                </p>
                <p class="text-muted-foreground mt-1 text-xs">
                    {{
                        required
                            ? 'Required for your build'
                            : 'Add if your setup needs it'
                    }}
                </p>
            </div>
            <ul v-else class="space-y-3">
                <li
                    v-for="item in items"
                    :key="item.key"
                    class="flex items-start gap-3"
                >
                    <img
                        v-if="item.image_url"
                        :src="item.image_url"
                        alt=""
                        class="bg-muted/30 h-16 w-16 shrink-0 rounded-md object-contain p-1"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-sm leading-relaxed font-medium break-words"
                        >
                            {{ item.name }}
                        </p>
                        <p class="mt-1 text-sm font-semibold tabular-nums">
                            {{ item.quantity }} × {{ item.formattedPrice }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ item.status }}
                        </p>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="disabled"
                            :aria-label="`Remove ${item.name}`"
                            @click="$emit('remove', item.product_id)"
                            >Remove</Button
                        >
                    </div>
                </li>
            </ul>
        </div>
        <Button
            variant="outline"
            class="min-h-11 w-full sm:col-start-2 sm:w-fit xl:col-start-3 xl:row-start-1"
            :disabled="disabled"
            :aria-label="`${items.length ? (multiple ? 'Add another' : 'Change') : 'Select'} ${label}`"
            @click="$emit('select', $event)"
            >{{
                items.length
                    ? multiple
                        ? 'Add another'
                        : 'Change part'
                    : 'Select part'
            }}</Button
        >
    </section>
</template>
