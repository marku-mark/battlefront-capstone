<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    PackageOpen,
    PackageSearch,
    ReceiptText,
    ShoppingCart,
    Store,
    Truck,
} from '@lucide/vue';
import { computed } from 'vue';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';
import { dashboard as dashboardRoute } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { index as orderIndex } from '@/routes/orders';
import { index as productIndex } from '@/routes/products';

const props = defineProps({
    dashboard: { type: Object, required: true },
    recommendations: { type: Array, default: () => [] },
    is_personalized: { type: Boolean, default: false },
    has_featured_fallback: { type: Boolean, default: false },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value) {
    return dateFormatter.format(new Date(value));
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboardRoute(),
            },
        ],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Customer dashboard" />

        <main
            class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-6 lg:p-10"
        >
            <section
                class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
                aria-labelledby="customer-dashboard-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1"
                    aria-hidden="true"
                />
                <div
                    class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="max-w-2xl">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Customer dashboard
                        </p>
                        <h1
                            id="customer-dashboard-heading"
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            Welcome back, {{ user.name }}
                        </h1>
                        <p class="text-muted-foreground mt-2 text-sm leading-6">
                            Continue shopping, check your cart, or track your
                            latest Battlefront order.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button as-child>
                            <Link :href="productIndex()">
                                <PackageSearch aria-hidden="true" />
                                Browse products
                            </Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="cartIndex()">
                                <ShoppingCart aria-hidden="true" />
                                View cart
                            </Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="orderIndex()">
                                <ReceiptText aria-hidden="true" />
                                View orders
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <section aria-labelledby="shopping-summary-heading">
                <h2 id="shopping-summary-heading" class="sr-only">
                    Shopping summary
                </h2>
                <dl
                    class="border-border bg-card grid border sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div
                        class="border-border border-b p-5 sm:border-r lg:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Products in cart
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ props.dashboard.summary.cart_items }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Distinct cart lines
                        </p>
                    </div>
                    <div
                        class="border-border border-b p-5 sm:border-r-0 lg:border-r lg:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Units in cart
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ props.dashboard.summary.cart_units }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Total quantity selected
                        </p>
                    </div>
                    <div
                        class="border-border border-b p-5 sm:border-r sm:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Active orders
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ props.dashboard.summary.active_orders }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Pending or being prepared
                        </p>
                    </div>
                    <div class="p-5">
                        <dt class="text-muted-foreground text-sm">
                            Order history
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ props.dashboard.summary.total_orders }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Orders placed with Battlefront
                        </p>
                    </div>
                </dl>
            </section>

            <RecommendationSection
                v-if="recommendations.length"
                :recommendations="recommendations"
                placement="dashboard"
                :title="
                    is_personalized
                        ? 'Recommended for you'
                        : has_featured_fallback
                          ? 'Popular and featured products'
                          : 'Popular products'
                "
                :description="
                    is_personalized
                        ? 'Suggestions based on your recent browsing, cart, and completed purchases.'
                        : 'Popular and available picks to help you continue shopping.'
                "
            />

            <section aria-labelledby="latest-order-heading">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-muted-foreground text-sm">
                            Purchase tracking
                        </p>
                        <h2
                            id="latest-order-heading"
                            class="text-xl font-semibold"
                        >
                            Latest order
                        </h2>
                    </div>
                    <Button
                        v-if="props.dashboard.latest_order"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                OrderController.show(
                                    props.dashboard.latest_order.id,
                                )
                            "
                        >
                            View details
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="props.dashboard.latest_order"
                    class="border-border bg-card border"
                >
                    <div
                        class="border-border flex flex-col gap-5 border-b p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6"
                    >
                        <div>
                            <p
                                class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                            >
                                Order reference
                            </p>
                            <h3 class="mt-1 text-2xl font-bold tabular-nums">
                                {{ props.dashboard.latest_order.reference }}
                            </h3>
                            <p
                                class="text-muted-foreground mt-2 flex items-center gap-2 text-sm"
                            >
                                <CalendarDays
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{
                                    formatDate(
                                        props.dashboard.latest_order.created_at,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Badge
                                variant="outline"
                                :class="
                                    orderStatusBadgeClass(
                                        props.dashboard.latest_order.status
                                            .value,
                                    )
                                "
                            >
                                {{ props.dashboard.latest_order.status.label }}
                            </Badge>
                            <Badge
                                variant="outline"
                                :class="
                                    orderStatusBadgeClass(
                                        props.dashboard.latest_order.payment
                                            .status.value,
                                    )
                                "
                            >
                                Payment
                                {{
                                    props.dashboard.latest_order.payment.status
                                        .label
                                }}
                            </Badge>
                        </div>
                    </div>

                    <dl class="grid gap-6 p-5 sm:grid-cols-3 sm:p-6">
                        <div class="flex gap-3">
                            <Truck
                                v-if="
                                    props.dashboard.latest_order.fulfillment
                                        .value === 'delivery'
                                "
                                class="text-muted-foreground mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <Store
                                v-else
                                class="text-muted-foreground mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <dt
                                    class="text-muted-foreground text-xs font-medium"
                                >
                                    Fulfillment
                                </dt>
                                <dd class="mt-1 font-semibold">
                                    {{
                                        props.dashboard.latest_order.fulfillment
                                            .label
                                    }}
                                </dd>
                            </div>
                        </div>
                        <div>
                            <dt
                                class="text-muted-foreground text-xs font-medium"
                            >
                                Payment method
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{
                                    props.dashboard.latest_order.payment.method
                                }}
                            </dd>
                        </div>
                        <div class="sm:text-right">
                            <dt
                                class="text-muted-foreground text-xs font-medium"
                            >
                                {{ props.dashboard.latest_order.item_count }}
                                {{
                                    props.dashboard.latest_order.item_count ===
                                    1
                                        ? 'product'
                                        : 'products'
                                }}
                                ·
                                {{
                                    props.dashboard.latest_order.total_quantity
                                }}
                                {{
                                    props.dashboard.latest_order
                                        .total_quantity === 1
                                        ? 'unit'
                                        : 'units'
                                }}
                            </dt>
                            <dd class="mt-1 text-2xl font-bold tabular-nums">
                                {{
                                    formatCurrency(
                                        props.dashboard.latest_order.total,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-else
                    class="border-border bg-card flex min-h-72 items-center justify-center border border-dashed p-8 text-center"
                >
                    <div class="max-w-md">
                        <span
                            class="border-border bg-secondary text-muted-foreground mx-auto flex size-14 items-center justify-center border"
                        >
                            <PackageOpen class="size-6" aria-hidden="true" />
                        </span>
                        <h3 class="mt-5 text-xl font-bold">No orders yet</h3>
                        <p class="text-muted-foreground mt-2 text-sm leading-6">
                            Your latest order and its fulfillment progress will
                            appear here after checkout.
                        </p>
                        <Button as-child class="mt-6">
                            <Link :href="productIndex()">
                                Browse products
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
