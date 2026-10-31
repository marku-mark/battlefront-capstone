<script setup>
import { Head, Form, Link, usePage } from '@inertiajs/vue3';
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { Bar, Line } from 'vue-chartjs';
import { computed } from 'vue';
import WishlistReportController from '@/actions/App/Http/Controllers/Administration/WishlistReportController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatCurrency } from '@/lib/currency';

ChartJS.register(
    BarElement,
    CategoryScale,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
);
const props = defineProps({
    overview: Object,
    category_options: Array,
    filters: Object,
    products: Object,
    activity: Object,
    stock_demand: Object,
    increasing_interest: Array,
    coverage: Object,
    conversion: Object,
    thresholds: Object,
});
const page = usePage();
const chartColors = useChartTheme();
const hasActivity = computed(
    () => props.activity.additions > 0 || props.activity.removals > 0,
);
function productPageRoute({ query }) {
    return WishlistReportController.index({
        query: { ...props.filters, ...query },
    });
}
const trend = computed(() => ({
    labels: props.activity.daily.map((row) => row.date),
    datasets: [
        {
            label: 'Added',
            data: props.activity.daily.map((row) => row.additions),
            borderColor: chartColors.value.primary,
            backgroundColor: chartColors.value.primary,
            tension: 0.25,
        },
        {
            label: 'Removed',
            data: props.activity.daily.map((row) => row.removals),
            borderColor: chartColors.value.muted,
            backgroundColor: chartColors.value.muted,
            tension: 0.25,
        },
    ],
}));
const demand = computed(() => ({
    labels: props.stock_demand.out_of_stock.map((item) => item.name),
    datasets: [
        {
            label: 'Customers wanting out-of-stock products',
            data: props.stock_demand.out_of_stock.map(
                (item) => item.wishlist_customers,
            ),
            backgroundColor: chartColors.value.primary,
        },
    ],
}));
</script>

<template>
    <Head title="Wishlist analytics" />
    <main class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-muted-foreground text-sm">
                    Administration / Reports
                </p>
                <h1 class="text-2xl font-bold">Wishlist analytics</h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Product filters apply to the totals, activity, demand
                    panels, and export.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link :href="WishlistReportController.index()"
                        >Reset filters</Link
                    ></Button
                ><Button
                    v-if="page.props.auth?.can?.exportReports"
                    as-child
                    variant="outline"
                    ><a
                        :href="
                            WishlistReportController.csv.url({ query: filters })
                        "
                        download
                        >Export CSV</a
                    ></Button
                >
            </div>
        </header>
        <section class="grid gap-3 sm:grid-cols-3">
            <article
                v-for="metric in [
                    { label: 'Active saves', value: overview.entries },
                    { label: 'Customers', value: overview.customers },
                    { label: 'Unique products', value: overview.products },
                ]"
                :key="metric.label"
                class="surface-card border p-5"
            >
                <p class="text-muted-foreground text-sm">{{ metric.label }}</p>
                <p class="mt-2 text-2xl font-bold">
                    {{ metric.value.toLocaleString() }}
                </p>
            </article>
        </section>
        <Form
            method="get"
            :action="WishlistReportController.index().url"
            v-slot="{ processing, errors }"
            class="surface-card grid gap-3 border p-4 sm:grid-cols-2 lg:grid-cols-4"
        >
            <label class="space-y-1 text-sm"
                >From
                <Input
                    type="date"
                    name="from"
                    :model-value="filters.from" /></label
            ><label class="space-y-1 text-sm"
                >To <Input type="date" name="to" :model-value="filters.to"
            /></label>
            <p v-if="errors.from" role="alert" class="text-destructive text-sm">
                {{ errors.from }}
            </p>
            <p v-if="errors.to" role="alert" class="text-destructive text-sm">
                {{ errors.to }}
            </p>
            <label class="space-y-1 text-sm"
                >Search
                <Input
                    name="q"
                    :model-value="filters.q"
                    placeholder="Product name"
            /></label>
            <label class="space-y-1 text-sm"
                >Activity buckets
                <select
                    name="period"
                    :value="filters.period"
                    class="border-input bg-background h-10 w-full rounded-md border px-3"
                >
                    <option value="day">Daily</option>
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Stock
                <select
                    name="stock"
                    :value="filters.stock"
                    class="border-input bg-background h-10 w-full rounded-md border px-3"
                >
                    <option value="all">All</option>
                    <option value="in_stock">In stock</option>
                    <option value="low_stock">Low stock</option>
                    <option value="out_of_stock">Out of stock</option>
                    <option value="unavailable">Unavailable</option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Category
                <select
                    name="category_id"
                    :value="filters.category_id ?? ''"
                    class="border-input bg-background h-10 w-full rounded-md border px-3"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="category in category_options"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Sort by
                <select
                    name="sort"
                    :value="filters.sort"
                    class="border-input bg-background h-10 w-full rounded-md border px-3"
                >
                    <option value="customers">Wishlist customers</option>
                    <option value="interest">Recent interest</option>
                    <option value="name">Product name</option>
                    <option value="price">Price</option>
                    <option value="stock">Stock</option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Sort direction
                <select
                    name="direction"
                    :value="filters.direction"
                    class="border-input bg-background h-10 w-full rounded-md border px-3"
                >
                    <option value="desc">Descending</option>
                    <option value="asc">Ascending</option>
                </select></label
            >
            <label class="space-y-1 text-sm"
                >Minimum saves for demand flag
                <Input
                    type="number"
                    min="0"
                    name="minimum_saves"
                    :model-value="filters.minimum_saves"
            /></label>
            <label class="space-y-1 text-sm"
                >Minimum net additions
                <Input
                    type="number"
                    min="0"
                    name="minimum_net_additions"
                    :model-value="filters.minimum_net_additions"
            /></label>
            <label class="space-y-1 text-sm"
                >Minimum interest increase (%)
                <Input
                    type="number"
                    min="0"
                    name="minimum_increase_percent"
                    :model-value="filters.minimum_increase_percent"
            /></label>
            <Button type="submit" :disabled="processing">{{
                processing ? 'Applying…' : 'Apply filters'
            }}</Button>
        </Form>
        <p v-if="coverage.message" class="text-muted-foreground text-sm">
            {{ coverage.message }}
        </p>
        <p class="text-muted-foreground text-sm">{{ conversion.message }}</p>
        <section class="grid gap-6 lg:grid-cols-2">
            <article class="surface-card min-w-0 border p-4">
                <h2 class="mb-4 font-semibold">
                    Wishlist additions and removals
                </h2>
                <div
                    v-if="hasActivity"
                    role="img"
                    :aria-label="`${activity.additions} additions and ${activity.removals} removals for the selected period`"
                >
                    <Line
                        :data="trend"
                        :options="{
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { ticks: { maxTicksLimit: 8 } },
                                y: { beginAtZero: true },
                            },
                        }"
                        class="h-72"
                    />
                    <p class="sr-only">
                        {{ activity.additions }} additions and
                        {{ activity.removals }} removals for the selected
                        period.
                    </p>
                </div>
                <p v-else class="text-muted-foreground py-12 text-center">
                    No wishlist activity matches this period and these filters.
                </p>
            </article>
            <article class="surface-card min-w-0 border p-4">
                <h2 class="mb-4 font-semibold">Out-of-stock demand</h2>
                <div
                    v-if="stock_demand.out_of_stock.length"
                    role="img"
                    :aria-label="`Out-of-stock wishlist demand for ${stock_demand.out_of_stock.length} products`"
                >
                    <Bar
                        :data="demand"
                        :options="{
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: { y: { beginAtZero: true } },
                        }"
                        class="h-72"
                    />
                    <p class="sr-only">
                        {{
                            stock_demand.out_of_stock
                                .map(
                                    (item) =>
                                        `${item.name}: ${item.wishlist_customers} customers`,
                                )
                                .join('; ')
                        }}
                    </p>
                </div>
                <p v-else class="text-muted-foreground py-12 text-center">
                    No out-of-stock demand matches these filters.
                </p>
            </article>
        </section>
        <section class="surface-card overflow-x-auto border p-4">
            <h2 class="mb-4 font-semibold">Most wishlisted products</h2>
            <table class="w-full min-w-[850px] text-left text-sm">
                <caption class="sr-only">
                    Wishlist product demand for the selected filters
                </caption>
                <thead>
                    <tr class="border-b">
                        <th scope="col" class="p-2">Product</th>
                        <th scope="col" class="p-2">Category</th>
                        <th scope="col" class="p-2">Price</th>
                        <th scope="col" class="p-2">Customers</th>
                        <th scope="col" class="p-2">Stock</th>
                        <th scope="col" class="p-2">Availability</th>
                        <th scope="col" class="p-2">Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-b last:border-0"
                    >
                        <td class="p-2">
                            <div class="flex items-center gap-3">
                                <ProductImage
                                    :image-url="product.image_url"
                                    :product-name="product.name"
                                    class="size-12"
                                /><span>{{ product.name }}</span>
                            </div>
                        </td>
                        <td class="p-2">{{ product.category ?? '—' }}</td>
                        <td class="p-2">
                            {{
                                formatCurrency(
                                    product.discount_price ?? product.price,
                                )
                            }}
                        </td>
                        <td class="p-2">{{ product.wishlist_customers }}</td>
                        <td class="p-2">{{ product.stock_quantity ?? '—' }}</td>
                        <td class="p-2">
                            {{ product.availability.replaceAll('_', ' ') }}
                        </td>
                        <td class="p-2">
                            +{{ product.activity.additions }} / −{{
                                product.activity.removals
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="!products.data.length"
                class="text-muted-foreground py-8 text-center"
            >
                No products match these filters.
            </p>
            <CatalogPagination
                :current-page="products.current_page"
                :last-page="products.last_page"
                :route="productPageRoute"
                label="Wishlist report pages"
            />
        </section>
        <section class="grid gap-6 lg:grid-cols-2">
            <article class="surface-card border p-4">
                <h2 class="mb-3 font-semibold">Most wishlisted out-of-stock</h2>
                <p
                    v-for="item in stock_demand.out_of_stock"
                    :key="item.id"
                    class="border-b py-2 last:border-0"
                >
                    {{ item.name }}
                    <span class="text-muted-foreground"
                        >· {{ item.wishlist_customers }} customers</span
                    >
                </p>
                <p
                    v-if="!stock_demand.out_of_stock.length"
                    class="text-muted-foreground"
                >
                    No current demand.
                </p>
            </article>
            <article class="surface-card border p-4">
                <h2 class="mb-3 font-semibold">Low stock with demand</h2>
                <p
                    v-for="item in stock_demand.low_stock"
                    :key="item.id"
                    class="border-b py-2 last:border-0"
                >
                    {{ item.name }}
                    <span class="text-muted-foreground"
                        >· {{ item.wishlist_customers }} customers ·
                        {{ item.stock_quantity }} left</span
                    >
                </p>
                <p
                    v-if="!stock_demand.low_stock.length"
                    class="text-muted-foreground"
                >
                    No current demand.
                </p>
            </article>
        </section>
        <section class="surface-card border p-4">
            <h2 class="mb-3 font-semibold">Increasing customer interest</h2>
            <p
                v-if="!coverage.complete_for_period"
                class="text-muted-foreground mb-3 text-sm"
            >
                Interest comparisons need complete history for both selected
                periods.
            </p>
            <div
                v-for="item in increasing_interest"
                :key="item.id"
                class="border-b py-2 last:border-0"
            >
                {{ item.name }}
                <span class="text-muted-foreground"
                    >· {{ item.demand.increase_percent }}% interest change ·
                    {{ item.wishlist_customers }} customers</span
                >
            </div>
            <p v-if="!increasing_interest.length" class="text-muted-foreground">
                No products meet the configured demand indicators.
            </p>
        </section>
    </main>
</template>
