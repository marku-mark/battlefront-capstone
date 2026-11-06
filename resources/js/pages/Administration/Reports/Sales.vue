<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import {
    CalendarRange,
    ChartNoAxesCombined,
    CircleDollarSign,
    Download,
    FileDown,
    Printer,
    ReceiptText,
    ShoppingBasket,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import {
    csv,
    pdf,
    printReport,
    reset,
} from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import RecommendationEngagement from '@/components/recommendations/RecommendationEngagement.vue';
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
import { useChartTheme } from '@/composables/useChartTheme';
import { formatCurrency } from '@/lib/currency';

ChartJS.register(
    ArcElement,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
);

const props = defineProps({
    filters: { type: Object, required: true },
    kpis: { type: Object, required: true },
    comparison: { type: Object, required: true },
    timeline: { type: Object, required: true },
    top_products: { type: Object, required: true },
    categories: { type: Object, required: true },
    products: { type: Object, required: true },
    active_filters: { type: Object, required: true },
    recommendation_engagement: { type: Object, required: true },
    refund_measures: { type: Object, required: true },
    sale_transactions: { type: Object, required: true },
});

const page = usePage();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const period = ref(props.filters.period);
const selectedProduct = ref(props.active_filters.product);
const selectedCategory = ref(props.active_filters.category);
const isCrossFiltering = ref(false);
const isApplyingFilters = ref(false);
const isResettingFilters = ref(false);
const isUpdatingReport = computed(
    () =>
        isCrossFiltering.value ||
        isApplyingFilters.value ||
        isResettingFilters.value,
);
const exportActions = computed(() => {
    if (page.props.auth.can?.exportReports !== true) {
        return [];
    }

    const query = {
        from: props.filters.from,
        to: props.filters.to,
        period: props.filters.period,
        product_id: props.filters.product_id ?? undefined,
        category_id: props.filters.category_id ?? undefined,
    };

    return [
        {
            label: 'Print report',
            href: printReport.url({ query }),
            icon: Printer,
            print: true,
        },
        { label: 'Export PDF', href: pdf.url({ query }), icon: FileDown },
        { label: 'Export CSV', href: csv.url({ query }), icon: Download },
    ];
});
const numberFormatter = new Intl.NumberFormat('en-PH');
const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeZone: 'UTC',
});
const presets = [7, 30, 90];
const todayDate = formatDateInput(new Date());
const hasSales = computed(() => props.kpis.total_sales > 0);
const chartColors = useChartTheme();
const hasCrossFilter = computed(
    () => selectedProduct.value !== null || selectedCategory.value !== null,
);

watch(
    () => props.filters,
    (filters) => {
        from.value = filters.from;
        to.value = filters.to;
        period.value = filters.period;
    },
);

watch(
    () => props.active_filters,
    (filters) => {
        selectedProduct.value = filters.product;
        selectedCategory.value = filters.category;
    },
);

const rangeLabel = computed(
    () =>
        `${dateFormatter.format(parseDate(props.filters.from))} – ${dateFormatter.format(parseDate(props.filters.to))}`,
);

const kpiCards = computed(() => [
    {
        label: 'Completed orders',
        value: numberFormatter.format(props.kpis.total_sales),
        detail: `${props.comparison.total_sales.toLocaleString('en-PH')} in the previous ${props.comparison.from}–${props.comparison.to}`,
        icon: ReceiptText,
    },
    {
        label: 'Product sales revenue',
        value: formatCurrency(props.kpis.total_revenue),
        detail: `${
            hasCrossFilter.value
                ? 'Purchase-time item revenue in this selection'
                : 'Product sales excluding delivery charges'
        } · ${formatCurrency(props.comparison.total_revenue)} in the previous range`,
        icon: CircleDollarSign,
    },
    {
        label: 'Average order value',
        value: formatCurrency(props.kpis.average_order_value),
        detail: 'Product revenue per completed sale',
        icon: ChartNoAxesCombined,
    },
    {
        label: 'Items sold',
        value: numberFormatter.format(props.kpis.total_items_sold),
        detail: 'Units across completed orders',
        icon: ShoppingBasket,
    },
    ...(page.props.auth?.can?.viewProfitReports &&
    props.kpis.gross_profit !== undefined
        ? [
              {
                  label: 'Net product sales',
                  value: formatCurrency(props.kpis.net_sales),
                  detail: `Approved returns: ${formatCurrency(props.kpis.sales_returns)}`,
                  icon: CircleDollarSign,
              },
              {
                  label: 'Cost of goods sold',
                  value: formatCurrency(props.kpis.cost_of_goods_sold),
                  detail: `Recovered from restocked returns: ${formatCurrency(props.kpis.cost_recovered)}`,
                  icon: ShoppingBasket,
              },
              {
                  label: 'Gross profit',
                  value: props.kpis.cost_data_complete
                      ? formatCurrency(props.kpis.gross_profit)
                      : 'Unavailable',
                  detail:
                      props.kpis.gross_margin === null
                          ? 'Margin unavailable'
                          : `Gross margin: ${props.kpis.gross_margin}%`,
                  icon: ChartNoAxesCombined,
              },
          ]
        : []),
]);

const timelineData = computed(() => ({
    labels: props.timeline.labels,
    datasets: [
        {
            label: 'Product revenue',
            data: props.timeline.revenue,
            borderColor: chartColors.value.highlight,
            backgroundColor: 'rgba(239, 27, 27, 0.12)',
            pointBackgroundColor: chartColors.value.highlight,
            fill: true,
            tension: 0.25,
            yAxisID: 'revenue',
        },
        {
            label: 'Sales',
            data: props.timeline.sales,
            borderColor: chartColors.value.text,
            backgroundColor: chartColors.value.text,
            pointBackgroundColor: chartColors.value.text,
            tension: 0.25,
            yAxisID: 'sales',
        },
        ...(page.props.auth?.can?.viewProfitReports &&
        props.timeline.gross_profit_before_returns !== undefined
            ? [
                  {
                      label: 'Gross profit before returns',
                      data: props.timeline.gross_profit_before_returns,
                      borderColor: '#22C55E',
                      backgroundColor: 'rgba(34, 197, 94, 0.12)',
                      pointBackgroundColor: '#22C55E',
                      tension: 0.25,
                      yAxisID: 'revenue',
                  },
              ]
            : []),
    ],
}));

const topProductsData = computed(() => ({
    labels: props.top_products.labels,
    datasets: [
        {
            label: 'Units sold',
            data: props.top_products.quantities,
            backgroundColor: props.top_products.ids.map((id) => {
                if (selectedProduct.value === null) {
                    return chartColors.value.primary;
                }

                return selectedProduct.value.id === id
                    ? chartColors.value.highlight
                    : 'rgba(156, 163, 175, 0.32)';
            }),
            borderColor: props.top_products.ids.map((id) =>
                selectedProduct.value?.id === id
                    ? '#F8FAFC'
                    : chartColors.value.border,
            ),
            borderWidth: props.top_products.ids.map((id) =>
                selectedProduct.value?.id === id ? 3 : 1,
            ),
            hoverBackgroundColor: chartColors.value.highlight,
        },
    ],
}));

const categoryData = computed(() => ({
    labels: props.categories.labels,
    datasets: [
        {
            label: 'Item revenue',
            data: props.categories.revenue,
            backgroundColor: props.categories.ids.map((id) => {
                if (selectedCategory.value === null) {
                    return chartColors.value.primary;
                }

                return selectedCategory.value.id === id
                    ? chartColors.value.highlight
                    : 'rgba(156, 163, 175, 0.32)';
            }),
            borderColor: props.categories.ids.map((id) =>
                selectedCategory.value?.id === id
                    ? '#F8FAFC'
                    : chartColors.value.surface,
            ),
            borderWidth: props.categories.ids.map((id) =>
                selectedCategory.value?.id === id ? 3 : 2,
            ),
            hoverBackgroundColor: chartColors.value.highlight,
        },
    ],
}));

const baseChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        tooltip: {
            backgroundColor: chartColors.value.surface,
            titleColor: chartColors.value.foreground,
            bodyColor: chartColors.value.foreground,
            borderColor: chartColors.value.border,
            borderWidth: 1,
        },
        legend: {
            labels: { color: chartColors.value.text, usePointStyle: true },
        },
    },
    scales: {
        x: {
            ticks: { color: chartColors.value.text },
            grid: { color: chartColors.value.border },
        },
        y: {
            beginAtZero: true,
            ticks: { color: chartColors.value.text, precision: 0 },
            grid: { color: chartColors.value.border },
        },
    },
}));

const timelineOptions = computed(() => ({
    ...baseChartOptions.value,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
        ...baseChartOptions.value.plugins,
        tooltip: {
            ...baseChartOptions.value.plugins.tooltip,
            callbacks: {
                label(context) {
                    if (
                        context.dataset.label === 'Gross profit before returns'
                    ) {
                        return `${context.dataset.label}: ${context.raw === null ? 'Unavailable' : formatCurrency(context.raw)}`;
                    }

                    return context.dataset.yAxisID === 'revenue'
                        ? `Product revenue: ${formatCurrency(context.raw)}`
                        : `Sales: ${numberFormatter.format(context.raw)}`;
                },
            },
        },
    },
    scales: {
        x: baseChartOptions.value.scales.x,
        revenue: {
            beginAtZero: true,
            position: 'left',
            ticks: {
                color: chartColors.value.text,
                callback: (value) => formatCurrency(value),
            },
            grid: { color: chartColors.value.border },
        },
        sales: {
            beginAtZero: true,
            position: 'right',
            ticks: { color: chartColors.value.text, precision: 0 },
            grid: { drawOnChartArea: false },
        },
    },
}));

const topProductsOptions = computed(() => ({
    ...baseChartOptions.value,
    indexAxis: 'y',
    onClick(_event, elements) {
        if (elements.length === 0 || isUpdatingReport.value) {
            return;
        }

        const index = elements[0].index;
        toggleProduct({
            id: props.top_products.ids[index],
            name: props.top_products.labels[index],
        });
    },
    onHover(event, elements) {
        if (event.native?.target) {
            event.native.target.style.cursor =
                elements.length > 0 ? 'pointer' : 'default';
        }
    },
}));

const categoryOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    onClick(_event, elements) {
        if (elements.length === 0 || isUpdatingReport.value) {
            return;
        }

        const index = elements[0].index;
        toggleCategory({
            id: props.categories.ids[index],
            name: props.categories.labels[index],
        });
    },
    onHover(event, elements) {
        if (event.native?.target) {
            event.native.target.style.cursor =
                elements.length > 0 ? 'pointer' : 'default';
        }
    },
    plugins: {
        legend: {
            position: 'bottom',
            labels: { color: chartColors.value.text, usePointStyle: true },
        },
        tooltip: {
            ...baseChartOptions.value.plugins.tooltip,
            callbacks: {
                label: (context) =>
                    `${context.label}: ${formatCurrency(context.raw)}`,
            },
        },
    },
}));

function parseDate(value) {
    return new Date(`${value}T00:00:00Z`);
}

function formatDateInput(date) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(
        date,
    );
}

function submitFilters() {
    router.visit(
        SalesReportController.index({
            query: {
                from: from.value,
                to: to.value,
                period: period.value,
                product_id: selectedProduct.value?.id,
                category_id: selectedCategory.value?.id,
            },
        }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                isApplyingFilters.value = true;
            },
            onFinish: () => {
                isApplyingFilters.value = false;
            },
        },
    );
}

function toggleProduct(product) {
    const nextProduct =
        selectedProduct.value?.id === product.id ? null : product;

    updateCrossFilters(nextProduct, selectedCategory.value);
}

function guardExport(event) {
    if (isUpdatingReport.value) {
        event.preventDefault();
    }
}

function toggleCategory(category) {
    const nextCategory =
        selectedCategory.value?.id === category.id ? null : category;

    updateCrossFilters(selectedProduct.value, nextCategory);
}

function clearCrossFilters() {
    updateCrossFilters(null, null);
}

function resetFilters() {
    router.visit(reset(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => {
            isResettingFilters.value = true;
        },
        onFinish: () => {
            isResettingFilters.value = false;
        },
    });
}

function updateCrossFilters(product, category) {
    const previousProduct = selectedProduct.value;
    const previousCategory = selectedCategory.value;
    selectedProduct.value = product;
    selectedCategory.value = category;

    router.visit(
        SalesReportController.index({
            query: {
                from: props.filters.from,
                to: props.filters.to,
                period: props.filters.period,
                product_id: product?.id,
                category_id: category?.id,
            },
        }),
        {
            only: [
                'filters',
                'active_filters',
                'kpis',
                'comparison',
                'timeline',
                'top_products',
                'categories',
                'products',
                'sale_transactions',
                'refund_measures',
            ],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                isCrossFiltering.value = true;
            },
            onError: () => {
                selectedProduct.value = previousProduct;
                selectedCategory.value = previousCategory;
            },
            onFinish: () => {
                isCrossFiltering.value = false;
            },
        },
    );
}

function applyPreset(days) {
    const end = new Date(`${formatDateInput(new Date())}T00:00:00Z`);
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - (days - 1));
    from.value = formatDateInput(start);
    to.value = formatDateInput(end);
    submitFilters();
}

function applyCalendarPreset(preset) {
    const today = formatDateInput(new Date());
    const [year, month] = today.split('-').map(Number);
    const monthStart = new Date(Date.UTC(year, month - 1, 1));
    const previousMonthEnd = new Date(monthStart.getTime() - 86_400_000);

    if (preset === 'today') {
        from.value = today;
        to.value = today;
    } else if (preset === 'this_month') {
        from.value = formatDateInput(monthStart);
        to.value = today;
    } else {
        from.value = formatDateInput(
            new Date(
                Date.UTC(
                    previousMonthEnd.getUTCFullYear(),
                    previousMonthEnd.getUTCMonth(),
                    1,
                ),
            ),
        );
        to.value = formatDateInput(previousMonthEnd);
    }

    submitFilters();
}

function productPageRoute(options) {
    return SalesReportController.index({
        query: {
            from: props.filters.from,
            to: props.filters.to,
            period: props.filters.period,
            product_id: selectedProduct.value?.id,
            category_id: selectedCategory.value?.id,
            page: options.query.page,
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Sales reports',
                href: SalesReportController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:gap-8 lg:p-10"
    >
        <Head title="Sales reports" />
        <section
            class="border-border bg-card relative overflow-hidden border p-4 sm:p-8"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-2xl">
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Administration · Sagay operations
                    </p>
                    <h1
                        class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        Sales dashboard
                    </h1>
                    <p class="text-muted-foreground mt-3 leading-7">
                        Monitor sales, product performance, and recommendation
                        engagement for {{ rangeLabel }}.
                    </p>
                </div>
                <div
                    class="text-muted-foreground flex items-center gap-2 text-sm"
                >
                    <CalendarRange class="text-primary size-4" />
                    {{ rangeLabel }} · Philippine time
                </div>
            </div>
            <div
                v-if="page.props.auth.can?.exportReports"
                class="mt-5 flex flex-wrap gap-2"
                role="group"
                aria-label="Sales report print and export"
            >
                <Button
                    v-for="action in exportActions"
                    :key="action.label"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="action.href"
                        :target="action.print ? '_blank' : undefined"
                        :rel="action.print ? 'noopener' : undefined"
                        :aria-disabled="isUpdatingReport"
                        :tabindex="isUpdatingReport ? -1 : undefined"
                        :class="{ 'opacity-50': isUpdatingReport }"
                        @click="guardExport"
                    >
                        <component :is="action.icon" aria-hidden="true" />
                        {{ action.label }}
                        <span v-if="action.print" class="sr-only"
                            >(opens in a new tab)</span
                        >
                    </a>
                </Button>
            </div>
        </section>

        <section
            class="border-border bg-card border p-5"
            aria-labelledby="sales-filter-heading"
        >
            <div class="flex items-start gap-3">
                <span
                    class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                >
                    <CalendarRange class="size-4" />
                </span>
                <div>
                    <h2 id="sales-filter-heading" class="font-semibold">
                        Reporting period
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Both the start and end dates are included. Choose how
                        the timeline groups recorded sales.
                    </p>
                </div>
            </div>

            <form
                class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end"
                @submit.prevent="submitFilters"
            >
                <div class="grid gap-2">
                    <Label for="sales-report-from">Start date</Label>
                    <Input
                        id="sales-report-from"
                        v-model="from"
                        type="date"
                        :max="to || todayDate"
                    />
                    <p
                        v-if="page.props.errors.from"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.from }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="sales-report-to">End date</Label>
                    <Input
                        id="sales-report-to"
                        v-model="to"
                        type="date"
                        :min="from"
                        :max="todayDate"
                    />
                    <p
                        v-if="page.props.errors.to"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.to }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="sales-report-period">Group timeline by</Label>
                    <Select v-model="period">
                        <SelectTrigger id="sales-report-period" class="w-full">
                            <SelectValue placeholder="Select a period" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="day">Day</SelectItem>
                            <SelectItem value="week">Week</SelectItem>
                            <SelectItem value="month">Month</SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="page.props.errors.period"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.period }}
                    </p>
                </div>
                <Button type="submit" :disabled="isUpdatingReport">
                    Apply range
                </Button>
            </form>

            <div
                class="border-border mt-5 flex flex-wrap items-center gap-2 border-t pt-4"
            >
                <span
                    class="text-muted-foreground mr-1 text-xs font-medium tracking-wide uppercase"
                >
                    Quick ranges
                </span>
                <Button
                    v-for="days in presets"
                    :key="days"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="isUpdatingReport"
                    @click="applyPreset(days)"
                >
                    Last {{ days }} days
                </Button>
                <Button
                    v-for="preset in ['today', 'this_month', 'previous_month']"
                    :key="preset"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="isUpdatingReport"
                    @click="applyCalendarPreset(preset)"
                >
                    {{
                        {
                            today: 'Today',
                            this_month: 'This month',
                            previous_month: 'Previous month',
                        }[preset]
                    }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="isUpdatingReport"
                    @click="resetFilters"
                >
                    Reset to default
                </Button>
            </div>
            <p
                v-if="page.props.errors.product_id"
                class="text-destructive mt-3 text-sm"
                role="alert"
            >
                {{ page.props.errors.product_id }}
            </p>
            <p
                v-if="page.props.errors.category_id"
                class="text-destructive mt-3 text-sm"
                role="alert"
            >
                {{ page.props.errors.category_id }}
            </p>
        </section>

        <section
            v-if="hasCrossFilter"
            class="border-border bg-card flex flex-wrap items-center gap-2 border px-4 py-3"
            aria-label="Active sales report filters"
        >
            <span
                class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
            >
                Filtered by
            </span>
            <Button
                v-if="selectedProduct"
                type="button"
                variant="outline"
                size="sm"
                class="h-auto min-h-8 min-w-0 py-2 wrap-anywhere whitespace-normal"
                :disabled="isUpdatingReport"
                @click="toggleProduct(selectedProduct)"
            >
                Product: {{ selectedProduct.name }}
                <X />
            </Button>
            <Button
                v-if="selectedCategory"
                type="button"
                variant="outline"
                size="sm"
                class="h-auto min-h-8 min-w-0 py-2 wrap-anywhere whitespace-normal"
                :disabled="isUpdatingReport"
                @click="toggleCategory(selectedCategory)"
            >
                Category: {{ selectedCategory.name }}
                <X />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="isUpdatingReport"
                @click="clearCrossFilters"
            >
                Clear filters
            </Button>
            <span
                v-if="isCrossFiltering"
                class="text-muted-foreground text-xs"
                role="status"
                aria-live="polite"
            >
                Updating dashboard…
            </span>
        </section>

        <section aria-labelledby="sales-kpis-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">{{ rangeLabel }}</p>
                <h2 id="sales-kpis-heading" class="text-xl font-semibold">
                    Sales summary
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Product revenue excludes third-party delivery charges. The
                    comparison uses the previous range of equal length. Refund
                    details are included in the on-screen and PDF reports; CSV
                    exports contain product rows only.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="border-border bg-card min-w-0 border p-5"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-muted-foreground text-sm">
                                {{ card.label }}
                            </p>
                            <p class="mt-2 text-2xl font-bold tracking-tight">
                                {{ card.value }}
                            </p>
                        </div>
                        <span
                            class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                        >
                            <component :is="card.icon" class="size-5" />
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-4 text-xs">
                        {{ card.detail }}
                    </p>
                </article>
            </div>
        </section>

        <section
            v-if="!hasSales"
            class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
        >
            <div class="max-w-md">
                <ChartNoAxesCombined
                    class="text-muted-foreground mx-auto size-9"
                />
                <h2 class="mt-3 font-semibold">
                    {{
                        hasCrossFilter
                            ? 'No recorded sales match these filters'
                            : 'No recorded sales in this range'
                    }}
                </h2>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    The summary remains at zero. Clear a chart filter or select
                    another date range to review other recorded sales.
                </p>
            </div>
        </section>

        <template v-else>
            <section
                class="border-border bg-card border p-5"
                aria-labelledby="sales-trend-heading"
            >
                <div>
                    <p class="text-muted-foreground text-sm">
                        Purchase-time product revenue
                    </p>
                    <h2 id="sales-trend-heading" class="text-xl font-semibold">
                        Revenue and sales over time
                    </h2>
                </div>
                <div class="mt-6 h-80">
                    <Line :data="timelineData" :options="timelineOptions" />
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section
                    class="border-border bg-card min-w-0 border p-5"
                    aria-labelledby="top-products-heading"
                >
                    <p class="text-muted-foreground text-sm">
                        Ranked by units · Select a bar to filter
                    </p>
                    <h2 id="top-products-heading" class="text-xl font-semibold">
                        Top-selling products
                    </h2>
                    <div class="mt-6 h-80">
                        <Bar
                            :data="topProductsData"
                            :options="topProductsOptions"
                            aria-label="Top-selling products. Select a product bar to filter the dashboard."
                            role="img"
                        >
                            Product chart unavailable.
                        </Bar>
                    </div>
                </section>
                <section
                    class="border-border bg-card min-w-0 border p-5"
                    aria-labelledby="category-sales-heading"
                >
                    <p class="text-muted-foreground text-sm">
                        Historical item revenue · Select a slice to filter
                    </p>
                    <h2
                        id="category-sales-heading"
                        class="text-xl font-semibold"
                    >
                        Sales by current category
                    </h2>
                    <div class="mt-6 h-80">
                        <Doughnut
                            :data="categoryData"
                            :options="categoryOptions"
                            aria-label="Sales by current category. Select a category slice to filter the dashboard."
                            role="img"
                        >
                            Category chart unavailable.
                        </Doughnut>
                    </div>
                </section>
            </div>
        </template>

        <section aria-labelledby="product-report-heading">
            <div
                class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="text-muted-foreground text-sm">
                        {{ products.total }}
                        {{ products.total === 1 ? 'product' : 'products' }}
                    </p>
                    <h2
                        id="product-report-heading"
                        class="text-xl font-semibold"
                    >
                        Product performance report
                    </h2>
                </div>
                <p class="text-muted-foreground text-xs">
                    Revenue uses purchase-time item prices.
                </p>
            </div>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card border p-8 text-center"
            >
                <ShoppingBasket class="text-muted-foreground mx-auto size-8" />
                <p class="mt-3 font-medium">No product sales to report</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    Product aggregates will appear when this range contains
                    sales.
                </p>
            </div>

            <div
                v-else
                class="border-border bg-card overflow-x-auto border"
                tabindex="0"
                role="region"
                aria-label="Product performance table"
            >
                <table class="w-full min-w-3xl text-left text-sm">
                    <thead
                        class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Product</th>
                            <th class="px-5 py-3 font-medium">
                                Current category
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Sales
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Units sold
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Item revenue
                            </th>
                            <th
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-3 text-right font-medium"
                            >
                                Cost of goods sold
                            </th>
                            <th
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-3 text-right font-medium"
                            >
                                Gross profit before returns
                            </th>
                            <th
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-3 text-right font-medium"
                            >
                                Margin before returns
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr v-for="product in products.data" :key="product.id">
                            <td class="px-5 py-4 font-medium">
                                <button
                                    type="button"
                                    class="hover:text-primary focus-visible:ring-ring text-left transition-colors focus-visible:ring-2 focus-visible:outline-none max-md:min-h-11"
                                    :aria-pressed="
                                        selectedProduct?.id === product.id
                                    "
                                    :disabled="isUpdatingReport"
                                    @click="toggleProduct(product)"
                                >
                                    {{ product.name }}
                                </button>
                            </td>
                            <td class="text-muted-foreground px-5 py-4">
                                {{ product.category }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(product.sales_count)
                                }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(
                                        product.quantity_sold,
                                    )
                                }}
                            </td>
                            <td class="px-5 py-4 text-right font-medium">
                                {{ formatCurrency(product.item_revenue) }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    product.cost_of_goods_sold === undefined
                                        ? '—'
                                        : formatCurrency(
                                              product.cost_of_goods_sold,
                                          )
                                }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    product.gross_profit_before_returns === null
                                        ? 'Unavailable'
                                        : formatCurrency(
                                              product.gross_profit_before_returns,
                                          )
                                }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    product.gross_margin_before_returns === null
                                        ? '—'
                                        : `${product.gross_margin_before_returns}%`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <CatalogPagination
                :current-page="products.current_page"
                :last-page="products.last_page"
                :route="productPageRoute"
                label="Product report pages"
            />
        </section>

        <section aria-labelledby="category-report-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    Current product classification
                </p>
                <h2 id="category-report-heading" class="text-xl font-semibold">
                    Category report
                </h2>
            </div>
            <div
                v-if="categories.rows.length === 0"
                class="border-border bg-card border p-8 text-center"
            >
                <p class="font-medium">No category sales to report</p>
            </div>
            <div
                v-else
                class="border-border bg-card overflow-x-auto border"
                tabindex="0"
                role="region"
                aria-label="Category performance table"
            >
                <table class="w-full min-w-2xl text-left text-sm">
                    <thead
                        class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Category</th>
                            <th class="px-5 py-3 text-right font-medium">
                                Sales
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Units sold
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Item revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr
                            v-for="category in categories.rows"
                            :key="category.id"
                        >
                            <td class="px-5 py-4 font-medium">
                                <button
                                    type="button"
                                    class="hover:text-primary focus-visible:ring-ring text-left transition-colors focus-visible:ring-2 focus-visible:outline-none max-md:min-h-11"
                                    :aria-pressed="
                                        selectedCategory?.id === category.id
                                    "
                                    :disabled="isUpdatingReport"
                                    @click="toggleCategory(category)"
                                >
                                    {{ category.name }}
                                </button>
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(category.sales_count)
                                }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(
                                        category.quantity_sold,
                                    )
                                }}
                            </td>
                            <td class="px-5 py-4 text-right font-medium">
                                {{ formatCurrency(category.item_revenue) }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    category.cost_of_goods_sold === undefined
                                        ? '—'
                                        : formatCurrency(
                                              category.cost_of_goods_sold,
                                          )
                                }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    category.gross_profit_before_returns ===
                                    null
                                        ? 'Unavailable'
                                        : formatCurrency(
                                              category.gross_profit_before_returns,
                                          )
                                }}
                            </td>
                            <td
                                v-if="page.props.auth?.can?.viewProfitReports"
                                class="px-5 py-4 text-right"
                            >
                                {{
                                    category.gross_margin_before_returns ===
                                    null
                                        ? '—'
                                        : `${category.gross_margin_before_returns}%`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            aria-labelledby="refund-measures-heading"
            class="border-border bg-card border p-5"
        >
            <h2 id="refund-measures-heading" class="text-xl font-semibold">
                Sales and refunds by event date
            </h2>
            <p class="text-muted-foreground mt-1 text-sm">
                Sales use sale dates; refund payments count after an independent
                review verifies them. Outstanding balance includes verified
                payments through {{ refund_measures.cutoff }}.
            </p>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="measure in [
                        {
                            label: 'Gross item sales',
                            value: refund_measures.gross_item_sales,
                        },
                        {
                            label: 'Verified refund payments',
                            value: refund_measures.refund_payments,
                        },
                        {
                            label: 'Gross less refunds in period',
                            value: refund_measures.gross_less_refunds,
                        },
                        {
                            label: 'Outstanding approved refunds at cutoff',
                            value: refund_measures.outstanding_at_cutoff,
                        },
                    ]"
                    :key="measure.label"
                    class="border-border border p-4"
                >
                    <p class="text-muted-foreground text-xs">
                        {{ measure.label }}
                    </p>
                    <p class="mt-2 text-xl font-bold">
                        {{ formatCurrency(measure.value) }}
                    </p>
                </div>
            </div>
            <details class="border-border mt-6 border">
                <summary
                    class="hover:bg-accent/50 focus-visible:ring-ring cursor-pointer px-4 py-3 font-medium focus-visible:ring-2 focus-visible:outline-none"
                >
                    View sale and refund records
                </summary>
                <div class="border-border border-t p-4">
                    <h3 class="font-semibold">Sale source records</h3>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="sale in sale_transactions.data"
                            :key="sale.id"
                            class="border-border flex flex-wrap items-center justify-between gap-2 border p-3 text-sm"
                        >
                            <div>
                                <strong>Sale #{{ sale.id }}</strong> ·
                                {{ formatCurrency(sale.product_revenue) }} ·
                                {{ sale.sale_date }}
                            </div>
                            <Link
                                v-if="page.props.auth.can?.viewOrders"
                                :href="OrderController.show(sale.order_id)"
                                class="text-primary underline"
                                >Order BF-{{
                                    String(sale.order_id).padStart(6, '0')
                                }}</Link
                            >
                            <span v-else
                                >Order BF-{{
                                    String(sale.order_id).padStart(6, '0')
                                }}</span
                            >
                        </div>
                        <p
                            v-if="sale_transactions.data.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No sales in this period.
                        </p>
                    </div>
                    <nav
                        v-if="sale_transactions.last_page > 1"
                        class="mt-3 flex justify-between text-sm"
                        aria-label="Sale transaction pages"
                    >
                        <Link
                            v-if="sale_transactions.prev_page_url"
                            :href="sale_transactions.prev_page_url"
                            class="text-primary underline"
                            >Previous</Link
                        ><span v-else></span>
                        <Link
                            v-if="sale_transactions.next_page_url"
                            :href="sale_transactions.next_page_url"
                            class="text-primary underline"
                            >Next</Link
                        >
                    </nav>
                    <h3 class="mt-6 font-semibold">
                        Refund payment source records
                    </h3>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Only independently verified payment records appear here.
                        Disputed and voided records remain in their return case
                        history.
                    </p>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="payment in refund_measures.payments.data"
                            :key="payment.id"
                            class="border-border flex flex-wrap items-center justify-between gap-2 border p-3 text-sm"
                        >
                            <div>
                                <strong>Payment #{{ payment.id }}</strong> ·
                                {{ formatCurrency(payment.amount) }} ·
                                {{ payment.paid_at }}
                                <span class="text-muted-foreground">{{
                                    payment.external_reference
                                }}</span>
                            </div>
                            <Link
                                v-if="page.props.auth.can?.viewOrders"
                                :href="
                                    CustomerReturnController.index({
                                        query: {
                                            case: payment.customer_return_id,
                                        },
                                    })
                                "
                                class="text-primary underline"
                                >Return #{{ payment.customer_return_id }}</Link
                            >
                            <span v-else
                                >Return #{{ payment.customer_return_id }}</span
                            >
                        </div>
                        <p
                            v-if="refund_measures.payments.data.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No refund payments in this period.
                        </p>
                    </div>
                    <nav
                        v-if="refund_measures.payments.last_page > 1"
                        class="mt-3 flex justify-between text-sm"
                        aria-label="Refund payment pages"
                    >
                        <Link
                            v-if="refund_measures.payments.prev_page_url"
                            :href="refund_measures.payments.prev_page_url"
                            class="text-primary underline"
                            >Previous</Link
                        ><span v-else></span>
                        <Link
                            v-if="refund_measures.payments.next_page_url"
                            :href="refund_measures.payments.next_page_url"
                            class="text-primary underline"
                            >Next</Link
                        >
                    </nav>
                    <h3 class="mt-6 font-semibold">
                        Voided refund payment records
                    </h3>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="reversal in refund_measures.reversals.data"
                            :key="reversal.id"
                            class="border-border flex flex-wrap items-center justify-between gap-2 border p-3 text-sm"
                        >
                            <div>
                                <strong
                                    >Payment #{{
                                        reversal.customer_refund_payment_id
                                    }}</strong
                                >
                                · −{{ formatCurrency(reversal.amount) }} ·
                                {{ reversal.created_at }} ·
                                {{ reversal.reason }}
                            </div>
                            <Link
                                v-if="page.props.auth.can?.viewOrders"
                                :href="
                                    CustomerReturnController.index({
                                        query: {
                                            case: reversal.customer_return_id,
                                        },
                                    })
                                "
                                class="text-primary underline"
                                >Return #{{ reversal.customer_return_id }}</Link
                            >
                            <span v-else
                                >Return #{{ reversal.customer_return_id }}</span
                            >
                        </div>
                        <p
                            v-if="refund_measures.reversals.data.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No voided payment records in this period.
                        </p>
                    </div>
                    <h3 class="mt-6 font-semibold">
                        Outstanding approvals at cutoff
                    </h3>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="refund in refund_measures.outstanding_refunds
                                .data"
                            :key="refund.id"
                            class="border-border flex flex-wrap items-center justify-between gap-2 border p-3 text-sm"
                        >
                            <div>
                                <strong>Refund #{{ refund.id }}</strong> ·
                                Approved
                                {{ formatCurrency(refund.approved_amount) }} ·
                                Paid by cutoff
                                {{ formatCurrency(refund.paid_at_cutoff) }} ·
                                Outstanding
                                {{ formatCurrency(refund.outstanding_amount) }}
                            </div>
                            <Link
                                v-if="page.props.auth.can?.viewOrders"
                                :href="
                                    CustomerReturnController.index({
                                        query: {
                                            case: refund.customer_return_id,
                                        },
                                    })
                                "
                                class="text-primary underline"
                                >Return #{{ refund.customer_return_id }}</Link
                            >
                            <span v-else
                                >Return #{{ refund.customer_return_id }}</span
                            >
                        </div>
                        <p
                            v-if="
                                refund_measures.outstanding_refunds.data
                                    .length === 0
                            "
                            class="text-muted-foreground text-sm"
                        >
                            No approved refund balance remains at cutoff.
                        </p>
                    </div>
                    <nav
                        v-if="refund_measures.outstanding_refunds.last_page > 1"
                        class="mt-3 flex justify-between text-sm"
                        aria-label="Outstanding refund pages"
                    >
                        <Link
                            v-if="
                                refund_measures.outstanding_refunds
                                    .prev_page_url
                            "
                            :href="
                                refund_measures.outstanding_refunds
                                    .prev_page_url
                            "
                            class="text-primary underline"
                            >Previous</Link
                        ><span v-else></span>
                        <Link
                            v-if="
                                refund_measures.outstanding_refunds
                                    .next_page_url
                            "
                            :href="
                                refund_measures.outstanding_refunds
                                    .next_page_url
                            "
                            class="text-primary underline"
                            >Next</Link
                        >
                    </nav>
                </div>
            </details>
        </section>

        <RecommendationEngagement
            :engagement="recommendation_engagement"
            :range-label="rangeLabel"
        />
    </main>
</template>
