<script setup>
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';

defineProps({
    period_days: { type: Number, required: true },
    summary: { type: Object, required: true },
    popular_components: { type: Array, required: true },
    popular_combinations: { type: Array, required: true },
    ordered_builds: { type: Array, required: true },
});

function formatPrice(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value ?? 0));
}
</script>

<template>
    <Head title="PC Builder analytics" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <PageHeader
            title="PC Builder analytics"
            heading-id="pc-build-analytics-heading"
        />
        <p class="text-muted-foreground text-sm">
            Activity summary for the last {{ period_days }} days. Builds count
            after a customer saves or adds a complete configuration to the cart.
        </p>

        <section
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="PC Builder summary"
        >
            <article
                v-for="[label, value] in [
                    ['Saved builds', summary.saved_builds],
                    ['Added to cart', summary.added_to_cart],
                    [
                        'Saved to cart conversion',
                        `${summary.conversion_percent}%`,
                    ],
                    ['Abandoned saved builds', summary.abandoned_builds],
                    ['Ordered configurations', summary.ordered_configurations],
                    [
                        'Gross PC Builder revenue',
                        formatPrice(summary.gross_revenue),
                    ],
                    ['Compatibility warnings', summary.compatibility_warnings],
                ]"
                :key="label"
                class="surface-panel border-border bg-card rounded-lg border p-4"
            >
                <p class="text-muted-foreground text-sm">{{ label }}</p>
                <p class="mt-2 text-2xl font-semibold">{{ value }}</p>
            </article>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="surface-panel border-border bg-card rounded-lg border"
                aria-labelledby="popular-components-heading"
            >
                <h2
                    id="popular-components-heading"
                    class="border-border border-b p-4 font-semibold"
                >
                    Popular selected components
                </h2>
                <ul class="divide-border divide-y">
                    <li
                        v-for="item in popular_components"
                        :key="item.product_name"
                        class="flex justify-between gap-4 p-4 text-sm"
                    >
                        <span>{{ item.product_name }}</span
                        ><span class="font-medium">{{ item.selections }}</span>
                    </li>
                    <li
                        v-if="popular_components.length === 0"
                        class="text-muted-foreground p-6 text-sm"
                    >
                        No saved build activity in this period.
                    </li>
                </ul>
            </section>
            <section
                class="surface-panel border-border bg-card rounded-lg border"
                aria-labelledby="popular-combinations-heading"
            >
                <h2
                    id="popular-combinations-heading"
                    class="border-border border-b p-4 font-semibold"
                >
                    Popular CPU and GPU combinations
                </h2>
                <ul class="divide-border divide-y">
                    <li
                        v-for="(item, index) in popular_combinations"
                        :key="`${item.processor}-${item.graphics_card}-${index}`"
                        class="p-4 text-sm"
                    >
                        <p class="font-medium">
                            {{ item.processor }} + {{ item.graphics_card }}
                        </p>
                        <p class="text-muted-foreground mt-1">
                            {{ item.saved_builds }} saved builds
                        </p>
                    </li>
                    <li
                        v-if="popular_combinations.length === 0"
                        class="text-muted-foreground p-6 text-sm"
                    >
                        No saved CPU and GPU combinations yet.
                    </li>
                </ul>
            </section>
        </div>

        <section
            class="surface-panel border-border bg-card rounded-lg border"
            aria-labelledby="ordered-builds-heading"
        >
            <h2
                id="ordered-builds-heading"
                class="border-border border-b p-4 font-semibold"
            >
                Ordered PC configurations
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="p-4">Build name</th>
                            <th class="p-4">Orders</th>
                            <th class="p-4">Gross item revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr
                            v-for="(build, index) in ordered_builds"
                            :key="`${build.name}-${index}`"
                        >
                            <td class="p-4">{{ build.name }}</td>
                            <td class="p-4">{{ build.orders_count }}</td>
                            <td class="p-4">
                                {{ formatPrice(build.gross_revenue) }}
                            </td>
                        </tr>
                        <tr v-if="ordered_builds.length === 0">
                            <td colspan="3" class="text-muted-foreground p-6">
                                No PC Builder orders in this period.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted-foreground px-4 pb-4 text-xs">
                Revenue excludes cancelled orders and reflects original
                component line totals; recorded refunds are not netted in this
                report.
            </p>
        </section>
    </main>
</template>
