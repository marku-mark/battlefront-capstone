<script setup>
import { Button } from '@/components/ui/button';
defineProps({
    completion: Number,
    total: String,
    budget: [String, Number],
    budgetExceeded: Boolean,
    difference: String,
    missing: Array,
    checking: Boolean,
    validation: Object,
    error: String,
    statusLabel: Function,
});
defineEmits(['retry']);
</script>
<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">Build summary</h2>
            <span class="text-muted-foreground text-xs tabular-nums"
                >{{ completion }}% selected</span
            >
        </div>
        <div
            class="bg-muted mt-4 h-1.5 overflow-hidden rounded-full"
            role="progressbar"
            :aria-valuenow="completion"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Required component progress"
        >
            <div
                class="bg-primary h-full transition-all motion-reduce:transition-none"
                :style="{ width: `${completion}%` }"
            ></div>
        </div>
        <p v-if="missing.length" class="text-muted-foreground mt-4 text-sm">
            Still needed: {{ missing.join(', ') }}
        </p>
        <div class="border-border my-5 border-y py-5">
            <p class="text-muted-foreground text-sm">Estimated total</p>
            <p
                class="mt-2 min-w-0 text-2xl font-bold tracking-tight break-words tabular-nums sm:text-3xl"
            >
                {{ total }}
            </p>
            <p
                v-if="budget !== '' && budget !== null"
                class="mt-2 text-sm"
                :class="
                    budgetExceeded
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{ difference }}
                {{ budgetExceeded ? 'over budget' : 'remaining in budget' }}
            </p>
        </div>
        <div aria-live="polite" :aria-busy="checking" class="mb-5 text-sm">
            <p v-if="checking" class="text-muted-foreground">
                Checking selected parts…
            </p>
            <div v-else-if="error">
                <p class="text-destructive">{{ error }}</p>
                <Button
                    size="sm"
                    variant="outline"
                    class="mt-2"
                    @click="$emit('retry')"
                    >Try again</Button
                >
            </div>
            <template v-else-if="validation">
                <p class="font-semibold">
                    {{
                        validation.selection_compatible
                            ? 'Selected parts compatible'
                            : 'Selection needs attention'
                    }}
                </p>
                <p
                    v-if="!validation.complete"
                    class="text-muted-foreground mt-2"
                >
                    Selected parts only. This is not a verified complete PC.
                </p>
                <p
                    v-if="validation.can_purchase && !validation.complete"
                    class="mt-2"
                >
                    You can buy these selected parts.
                </p>
                <ul
                    v-if="validation.purchase_blockers?.length"
                    class="mt-2 space-y-1"
                >
                    <li
                        v-for="reason in validation.purchase_blockers"
                        :key="reason"
                    >
                        {{ reason }}
                    </li>
                </ul>
                <p
                    v-else-if="!validation.selection_compatible"
                    class="text-muted-foreground mt-2"
                >
                    Some selected parts still need verification. Open a part's
                    choices to change or remove it.
                </p>
            </template>
            <p v-else class="text-muted-foreground">
                Select parts to begin your build.
            </p>
        </div>
        <p class="text-muted-foreground text-xs">
            Saving does not reserve stock. Prices, availability and
            compatibility are checked again before checkout.
        </p>
        <slot />
    </div>
</template>
