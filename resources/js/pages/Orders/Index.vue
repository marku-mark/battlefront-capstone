<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    CreditCard,
    PackageOpen,
    Store,
    Truck,
} from '@lucide/vue';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';
import { orderDisplayStatus, orderStatusBadgeClass } from '@/lib/orderStatus';
import { index as productIndex } from '@/routes/products';

defineProps({
    orders: { type: Object, required: true },
});

function formatOrderDate(value) {
    return formatDateTime(value);
}

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
    <div class="contents">
        <Head title="My orders" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader title="My orders" heading-id="order-history-heading" />

            <section aria-labelledby="orders-heading">
                <div class="mb-4">
                    <p class="text-muted-foreground text-sm">
                        {{ orders.total }}
                        {{ orders.total === 1 ? 'order' : 'orders' }}
                    </p>
                    <h2 id="orders-heading" class="sr-only">Your orders</h2>
                </div>

                <div
                    v-if="orders.data.length === 0"
                    class="surface-panel border-border bg-card flex min-h-80 items-center justify-center border border-dashed p-8 text-center"
                >
                    <div class="max-w-md">
                        <span
                            class="border-border bg-secondary text-muted-foreground mx-auto flex size-14 items-center justify-center border"
                        >
                            <PackageOpen class="size-6" aria-hidden="true" />
                        </span>
                        <h3 class="mt-5 text-xl font-bold">No orders yet</h3>
                        <p class="text-muted-foreground mt-2 text-sm leading-6">
                            Completed checkouts will appear here with their
                            latest order and payment status.
                        </p>
                        <Button as-child class="mt-6">
                            <Link :href="productIndex()">
                                Browse products
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </div>

                <div v-else class="grid gap-4">
                    <article
                        v-for="order in orders.data"
                        :key="order.id"
                        class="surface-card border-border bg-card min-w-0 border p-4 sm:p-6"
                    >
                        <div
                            class="border-border flex flex-col gap-4 border-b pb-5 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                >
                                    Order reference
                                </p>
                                <h3 class="mt-1 text-xl font-bold tabular-nums">
                                    {{ order.reference }}
                                </h3>
                                <p
                                    class="text-muted-foreground mt-2 flex items-center gap-2 text-sm"
                                >
                                    <CalendarDays
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ formatOrderDate(order.created_at) }}
                                </p>
                            </div>

                            <Button variant="outline" size="sm" as-child>
                                <Link :href="OrderController.show(order.id)">
                                    View order
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                        </div>

                        <dl
                            class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div class="flex gap-3">
                                <Truck
                                    v-if="
                                        order.fulfillment.value === 'delivery'
                                    "
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <Store
                                    v-else
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Fulfillment
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ order.fulfillment.label }}
                                    </dd>
                                </div>
                            </div>

                            <div>
                                <dt
                                    class="text-muted-foreground text-xs font-medium"
                                >
                                    Order status
                                </dt>
                                <dd class="mt-2">
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
                                </dd>
                            </div>

                            <div class="flex gap-3">
                                <CreditCard
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Payment
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ order.payment.method.label }}
                                    </dd>
                                    <dd class="mt-2">
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
                                    </dd>
                                </div>
                            </div>

                            <div class="lg:text-right">
                                <dt
                                    class="text-muted-foreground text-xs font-medium"
                                >
                                    {{ order.item_count }}
                                    {{
                                        order.item_count === 1
                                            ? 'product'
                                            : 'products'
                                    }}
                                    / {{ order.total_quantity }}
                                    {{
                                        order.total_quantity === 1
                                            ? 'unit'
                                            : 'units'
                                    }}
                                </dt>
                                <dd class="mt-1 text-xl font-bold tabular-nums">
                                    {{ formatCurrency(order.total) }}
                                </dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <CatalogPagination
                    :current-page="orders.current_page"
                    :last-page="orders.last_page"
                    :route="OrderController.index"
                    label="Order history pages"
                />
            </section>
        </main>
    </div>
</template>
