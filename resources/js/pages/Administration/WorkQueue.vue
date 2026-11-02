<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import { Button } from '@/components/ui/button';
import { formatRelativeTime, formatDateTime } from '@/lib/dateTime';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
});
const taskLabels = {
    stock_adjustment: 'Stock request',
    damage: 'Damage report',
    supplier_return: 'Supplier return',
    customer_return: 'Customer return',
    customer_request: 'Customer request awaiting receipt',
    customer_resolution: 'Customer resolution fulfillment',
    customer_handover: 'Return to customer',
    refund: 'Refund payment',
    refund_reconciliation: 'Refund review',
    cancellation_review: 'Cancellation review',
    cancellation_refund: 'Cancellation refund',
    order: 'Order',
};
const kind = ref(props.filters.kind ?? 'all');
const responsible = ref(props.filters.responsible ?? '');
const olderThan = ref(String(props.filters.older_than ?? 'all'));
watch(
    () => props.filters,
    (filters) => {
        kind.value = filters.kind ?? 'all';
        responsible.value = filters.responsible ?? '';
        olderThan.value = String(filters.older_than ?? 'all');
    },
);
function applyFilters() {
    router.get(
        WorkQueueController.index(),
        {
            kind: kind.value === 'all' ? undefined : kind.value,
            responsible: responsible.value.trim() || undefined,
            older_than: olderThan.value === 'all' ? undefined : olderThan.value,
        },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tasks', href: WorkQueueController.index() }],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Tasks" />
        <PageHeader title="Tasks" description="Open tasks, oldest first." />
        <form
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            @submit.prevent="applyFilters"
        >
            <label class="grid max-w-full min-w-0 gap-1 text-sm"
                >Task type<select
                    v-model="kind"
                    class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="all">All tasks</option>
                    <option value="stock_adjustment">Stock request</option>
                    <option value="damage">Damage</option>
                    <option value="supplier_return">Supplier return</option>
                    <option value="customer_return">Customer return</option>
                    <option value="customer_resolution">
                        Customer resolution fulfillment
                    </option>
                    <option value="customer_request">
                        Customer request awaiting receipt
                    </option>
                    <option value="customer_handover">
                        Return to customer
                    </option>
                    <option value="refund">Refund payment</option>
                    <option value="refund_reconciliation">Refund review</option>
                    <option value="cancellation_review">
                        Cancellation review
                    </option>
                    <option value="cancellation_refund">
                        Cancellation refund
                    </option>
                    <option value="order">Order</option>
                    <option value="shipping">Shipping follow-up</option>
                </select></label
            >
            <label class="grid max-w-full min-w-0 gap-1 text-sm"
                >Assigned to<input
                    v-model="responsible"
                    class="border-input bg-background border p-2"
                    maxlength="255"
                    placeholder="Search name"
            /></label>
            <label class="grid max-w-full min-w-0 gap-1 text-sm"
                >Open for<select
                    v-model="olderThan"
                    class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="all">Any time</option>
                    <option value="1">Over 1 day</option>
                    <option value="7">Over 7 days</option>
                    <option value="30">Over 30 days</option>
                </select></label
            >
            <Button class="min-h-11 self-end" type="submit">
                Apply filters
            </Button>
        </form>
        <p class="text-muted-foreground text-sm">
            {{ entries.total }} open tasks
        </p>
        <div class="grid gap-3">
            <article
                v-for="entry in entries.data"
                :key="`${entry.kind}-${entry.id}`"
                class="surface-card border-border bg-card grid gap-3 border p-4 sm:grid-cols-[1fr_auto] sm:items-center"
            >
                <div>
                    <p class="text-primary text-xs font-semibold uppercase">
                        {{
                            taskLabels[entry.kind] ??
                            entry.kind.replaceAll('_', ' ')
                        }}
                    </p>
                    <h2 class="mt-1 font-semibold">{{ entry.reference }}</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ entry.required_action }} · Responsible:
                        {{ entry.responsible_name }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Opened {{ formatDateTime(entry.created_at) }} ·
                        {{ formatRelativeTime(entry.created_at) }}
                    </p>
                </div>
                <Link
                    :href="entry.href"
                    class="text-primary text-sm font-semibold underline"
                    >View task</Link
                >
            </article>
            <p
                v-if="entries.data.length === 0"
                class="border-border bg-card border p-6 text-sm"
            >
                No tasks need your attention.
            </p>
        </div>
        <nav
            v-if="entries.last_page > 1"
            class="flex justify-between text-sm"
            aria-label="Tasks pages"
        >
            <Link
                v-if="entries.prev_page_url"
                :href="entries.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span>
            <Link
                v-if="entries.next_page_url"
                :href="entries.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </nav>
    </main>
</template>
