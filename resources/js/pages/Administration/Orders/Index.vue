<script setup>
import PageHeader from '@/components/PageHeader.vue';
import OrdersDirectoryTabs from '@/components/OrdersDirectoryTabs.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    PackageCheck,
    Search,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';
import { orderDisplayStatus, orderStatusBadgeClass } from '@/lib/orderStatus';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    status_counts: { type: Object, required: true },
});

const gatewayState = ref(props.filters.gateway_state ?? 'all');
const paymentStatus = ref(props.filters.payment_status ?? 'all');
const paymentMethod = ref(props.filters.payment_method ?? 'all');
const fulfillmentMethod = ref(props.filters.fulfillment_method ?? 'all');

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: OrderController.index,
        query: selectedFilters,
    });

const tabs = computed(() => [
    { value: 'active', label: 'Active', count: props.status_counts.active },
    {
        value: 'completed',
        label: 'Completed',
        count: props.status_counts.completed,
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        count: props.status_counts.cancelled,
    },
]);

const viewCopy = computed(() => {
    if (props.filters.status === 'completed') {
        return {
            heading: 'Completed order history',
            emptyHeading: 'No completed orders found',
            emptyText:
                'Completed orders matching these filters will appear here.',
        };
    }

    if (props.filters.status === 'cancelled') {
        return {
            heading: 'Cancelled order history',
            emptyHeading: 'No cancelled orders found',
            emptyText:
                'Cancelled orders matching these filters will appear here.',
        };
    }

    return {
        heading: 'Processing queue',
        emptyHeading: 'No active orders found',
        emptyText:
            'Pending and processing orders matching these filters will appear here.',
    };
});

const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(() =>
    ['gateway_state', 'payment_status', 'payment_method', 'fulfillment_method'].some(
        (filter) => props.filters[filter] !== null,
    ),
);

function formatDate(value) {
    return formatDateTime(value);
}

function selectedValue(value) {
    return value === 'all' ? undefined : value;
}

function appliedFilters() {
    return {
        gateway_state: props.filters.gateway_state ?? undefined,
        payment_status: props.filters.payment_status ?? undefined,
        payment_method: props.filters.payment_method ?? undefined,
        fulfillment_method: props.filters.fulfillment_method ?? undefined,
    };
}

function selectedFilters() {
    return {
        status:
            props.filters.status === 'active'
                ? undefined
                : props.filters.status,
        gateway_state: selectedValue(gatewayState.value),
        payment_status: selectedValue(paymentStatus.value),
        payment_method: selectedValue(paymentMethod.value),
        fulfillment_method: selectedValue(fulfillmentMethod.value),
    };
}

function filtersAreCurrent() {
    const selected = selectedFilters();
    const applied = appliedFilters();

    return ['gateway_state', 'payment_status', 'payment_method', 'fulfillment_method'].every(
        (filter) =>
            String(selected[filter] ?? '') === String(applied[filter] ?? ''),
    );
}

function updateFilters() {
    if (filtersAreCurrent()) {
        return;
    }

    cancelPendingSearch();

    router.visit(
        OrderController.index({
            query: {
                q: search.value.trim() || undefined,
                ...selectedFilters(),
            },
        }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function clearFilters() {
    cancelPendingSearch();
    gatewayState.value = 'all';
    paymentStatus.value = 'all';
    paymentMethod.value = 'all';
    fulfillmentMethod.value = 'all';
}

function tabRoute(status) {
    return OrderController.index({
        query: {
            q: props.filters.q ?? undefined,
            status: status === 'active' ? undefined : status,
            payment_status: props.filters.payment_status ?? undefined,
            payment_method: props.filters.payment_method ?? undefined,
            fulfillment_method: props.filters.fulfillment_method ?? undefined,
        },
    });
}

function orderPage(options) {
    return OrderController.index({
        query: {
            ...props.filters,
            page: options.query.page,
        },
    });
}

watch([gatewayState, paymentStatus, paymentMethod, fulfillmentMethod], updateFilters);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Orders',
                href: OrderController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Orders" />
        <PageHeader title="Orders" />
        <OrdersDirectoryTabs active="orders" />

        <nav
            aria-label="Order views"
            class="border-border flex gap-1 overflow-x-auto border-t px-5"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.value"
                :href="tabRoute(tab.value)"
                preserve-scroll
                :aria-current="
                    filters.status === tab.value ? 'page' : undefined
                "
                class="text-muted-foreground hover:text-foreground relative inline-flex min-h-12 shrink-0 items-center gap-2 px-3 text-sm font-medium transition-colors"
                :class="{
                    'text-foreground after:bg-primary after:absolute after:right-3 after:bottom-0 after:left-3 after:h-0.5':
                        filters.status === tab.value,
                }"
            >
                {{ tab.label }}
                <span
                    class="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs tabular-nums"
                >
                    {{ tab.count }}
                </span>
            </Link>
        </nav>

        <section class="space-y-3" aria-labelledby="order-query-heading">
            <div class="mb-4">
                <Label for="gateway-state">Online payment recovery</Label>
                <Select v-model="gatewayState">
                    <SelectTrigger id="gateway-state"><SelectValue placeholder="All payment states" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All payment states</SelectItem>
                        <SelectItem v-for="option in filter_options.gateway_states" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="gatewayState === 'needs_attention'" class="text-muted-foreground mt-2 text-sm">Includes payment exceptions on completed and cancelled orders.</p>
            </div>
            <h2 id="order-query-heading" class="sr-only">Search and filters</h2>

            <div
                class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="admin-order-search">Search orders</Label>
                    <div class="relative">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        />
                        <Input
                            id="admin-order-search"
                            v-model="search"
                            class="pl-9"
                            maxlength="255"
                            placeholder="BF reference, customer, or recipient"
                            autocomplete="off"
                        />
                    </div>
                    <span class="sr-only" role="status">{{
                        isSearching ? 'Updating results...' : ''
                    }}</span>
                </div>
                <Button
                    v-if="hasSearchInput"
                    type="button"
                    variant="outline"
                    @click="clearSearch"
                >
                    <X />
                    Clear search
                </Button>
            </div>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="admin-order-payment-status">
                        Payment status
                    </Label>
                    <Select v-model="paymentStatus">
                        <SelectTrigger
                            id="admin-order-payment-status"
                            class="w-full"
                        >
                            <SelectValue placeholder="All payment statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                All payment statuses
                            </SelectItem>
                            <SelectItem
                                v-for="status in filter_options.payment_statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="admin-order-payment-method">
                        Payment method
                    </Label>
                    <Select v-model="paymentMethod">
                        <SelectTrigger
                            id="admin-order-payment-method"
                            class="w-full"
                        >
                            <SelectValue placeholder="All payment methods" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                All payment methods
                            </SelectItem>
                            <SelectItem
                                v-for="method in filter_options.payment_methods"
                                :key="method.value"
                                :value="method.value"
                            >
                                {{ method.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="admin-order-fulfillment-method">
                        Fulfillment
                    </Label>
                    <Select v-model="fulfillmentMethod">
                        <SelectTrigger
                            id="admin-order-fulfillment-method"
                            class="w-full"
                        >
                            <SelectValue
                                placeholder="All fulfillment methods"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                All fulfillment methods
                            </SelectItem>
                            <SelectItem
                                v-for="method in filter_options.fulfillment_methods"
                                :key="method.value"
                                :value="method.value"
                            >
                                {{ method.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <Button
                v-if="hasAppliedFilters"
                type="button"
                variant="ghost"
                size="sm"
                class="mt-4"
                @click="clearFilters"
            >
                <X />
                Clear filters
            </Button>
        </section>

        <section aria-labelledby="order-queue-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ orders.total }}
                    {{ orders.total === 1 ? 'order' : 'orders' }}
                </p>
                <h2 id="order-queue-heading" class="text-xl font-semibold">
                    {{ viewCopy.heading }}
                </h2>
            </div>

            <div
                v-if="orders.data.length === 0"
                class="surface-panel border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <PackageCheck
                        class="text-muted-foreground mx-auto size-8"
                    />
                    <p class="mt-3 font-medium">
                        {{ viewCopy.emptyHeading }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ viewCopy.emptyText }}
                    </p>
                </div>
            </div>

            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="order in orders.data"
                    :key="order.id"
                    class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_auto] lg:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold tabular-nums">
                                {{ order.reference }}
                            </h3>
                            <Badge
                                variant="outline"
                                :class="
                                    orderStatusBadgeClass(
                                        orderDisplayStatus(order).value,
                                    )
                                "
                            >
                                {{ orderDisplayStatus(order).label }}
                            </Badge>
                        </div>
                        <div
                            class="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <UserRound class="size-3.5" />
                                {{ order.customer.name }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <CalendarDays class="size-3.5" />
                                {{ formatDate(order.created_at) }}
                            </span>
                        </div>
                        <p class="text-muted-foreground mt-2 text-xs">
                            Recipient: {{ order.recipient_name }} &middot;
                            {{ order.fulfillment.operational_label }}
                        </p>
                    </div>

                    <div class="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <p class="text-muted-foreground">Payment</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    {{ order.payment.method.label }}<span v-if="order.payment.gateway" class="text-muted-foreground block text-xs">{{ order.payment.gateway.label }}</span>
                                </span>
                                <Badge
                                    variant="outline"
                                    :class="
                                        orderStatusBadgeClass(
                                            order.payment.status.value,
                                        )
                                    "
                                >
                                    {{ order.payment.status.label }}
                                </Badge>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-muted-foreground">
                                {{ order.total_quantity }} items
                            </p>
                            <p class="mt-1 font-semibold tabular-nums">
                                {{ formatCurrency(order.total) }}
                            </p>
                        </div>
                    </div>

                    <Button variant="outline" size="sm" as-child>
                        <Link :href="OrderController.show(order.id)">
                            Review order
                            <ArrowRight />
                        </Link>
                    </Button>
                </article>
            </div>

            <CatalogPagination
                :current-page="orders.current_page"
                :last-page="orders.last_page"
                :route="orderPage"
                label="Order pages"
            />
        </section>
    </main>
</template>
