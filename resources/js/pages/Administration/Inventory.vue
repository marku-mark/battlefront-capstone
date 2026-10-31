<script setup>
import PageHeader from '@/components/PageHeader.vue';
import InlineEditor from '@/components/administration/InlineEditor.vue';
import StockAdjustmentSummary from '@/components/administration/StockAdjustmentSummary.vue';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ChevronLeft,
    ChevronRight,
    Save,
    Search,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import InputError from '@/components/InputError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/dateTime';

const requestReasonCodes = ref({});
const requestedQuantities = ref({});
const stockConfirmations = ref({});
const page = usePage();
const canAdjustStock = computed(() =>
    Boolean(page.props.auth.can?.adjustInventory),
);
const canChangeStock = computed(
    () =>
        canAdjustStock.value || page.props.auth.can?.requestInventoryAdjustment,
);

function resetStockConfirmation(event, productId) {
    if (event.target.name !== 'stock_adjustment_confirmed') {
        stockConfirmations.value[productId] = false;
    }
}

function stockSubmitted(productId) {
    if (canAdjustStock.value) {
        stockConfirmations.value[productId] = false;
        delete requestedQuantities.value[productId];
        delete requestReasonCodes.value[productId];
    }
}

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    low_stock_count: { type: Number, required: true },
    out_of_stock_count: { type: Number, required: true },
});

const categoryId = ref(String(props.filters.category_id ?? 'all'));
const stock = ref(props.filters.stock);

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: InventoryController.index,
        query: selectedFilters,
    });

const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(
    () => props.filters.category_id !== null || props.filters.stock !== 'all',
);
const hasActiveQuery = computed(
    () => Boolean(props.filters.q) || hasAppliedFilters.value,
);

function formatLastUpdated(value) {
    return value ? formatDateTime(value) : 'Not available';
}

function inventoryPage(page) {
    return InventoryController.index({
        query: {
            q: props.filters.q ?? undefined,
            category_id: props.filters.category_id ?? undefined,
            page,
            stock:
                props.filters.stock === 'all' ? undefined : props.filters.stock,
        },
    });
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        stock: props.filters.stock === 'all' ? undefined : props.filters.stock,
    };
}

function selectedFilters() {
    return {
        category_id: categoryId.value === 'all' ? undefined : categoryId.value,
        stock: stock.value === 'all' ? undefined : stock.value,
    };
}

function filtersAreCurrent() {
    const selected = selectedFilters();
    const applied = appliedFilters();

    return Object.keys(selected).every(
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
        InventoryController.index({
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
    categoryId.value = 'all';
    stock.value = 'all';
}

watch([categoryId, stock], updateFilters);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Inventory',
                href: InventoryController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Inventory" />
        <PageHeader title="Inventory" description="Sagay City stock" />

        <section aria-labelledby="inventory-ledger-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2
                    id="inventory-ledger-heading"
                    class="flex flex-wrap items-baseline gap-2 text-lg font-semibold"
                >
                    Stock
                    <span class="text-muted-foreground text-sm font-normal"
                        >{{ products.total }}
                        {{
                            products.total === 1 ? 'product' : 'products'
                        }}</span
                    >
                </h2>
                <div class="flex flex-wrap gap-3 text-sm">
                    <span>{{ low_stock_count }} low stock</span>
                    <span
                        :class="{ 'text-destructive': out_of_stock_count > 0 }"
                        >{{ out_of_stock_count }} out of stock</span
                    >
                </div>
            </div>
            <section
                class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]"
                aria-label="Inventory filters"
            >
                <div class="grid gap-2 sm:col-span-2 lg:col-span-1">
                    <Label for="inventory-search">Search products</Label>
                    <div class="flex gap-2">
                        <div class="relative min-w-0 flex-1">
                            <Search
                                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                                aria-hidden="true"
                            />
                            <Input
                                id="inventory-search"
                                v-model="search"
                                class="min-h-11 pl-9"
                                maxlength="255"
                                placeholder="Name, code, or brand"
                                autocomplete="off"
                            />
                        </div>
                        <Button
                            class="min-h-11"
                            v-if="hasSearchInput"
                            type="button"
                            variant="outline"
                            @click="clearSearch"
                            >Clear search</Button
                        >
                    </div>
                    <p
                        v-if="isSearching"
                        class="text-muted-foreground text-xs"
                        role="status"
                    >
                        Updating results...
                    </p>
                </div>
                <div class="grid content-start gap-2">
                    <Label for="inventory-category">Category</Label>
                    <Select v-model="categoryId">
                        <SelectTrigger
                            id="inventory-category"
                            class="min-h-11 w-full"
                            ><SelectValue placeholder="All categories"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            <SelectItem
                                v-for="category in filter_options.categories"
                                :key="category.id"
                                :value="String(category.id)"
                                >{{ category.name }}
                                {{
                                    category.is_active ? '' : '(Inactive)'
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid content-start gap-2">
                    <Label for="inventory-stock">Stock status</Label>
                    <Select v-model="stock">
                        <SelectTrigger
                            id="inventory-stock"
                            class="min-h-11 w-full"
                            ><SelectValue placeholder="All statuses"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="in_stock">In stock</SelectItem>
                            <SelectItem value="low_stock">Low stock</SelectItem>
                            <SelectItem value="out_of_stock"
                                >Out of stock</SelectItem
                            >
                            <SelectItem value="not_initialized"
                                >Stock not set</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div
                    v-if="hasAppliedFilters"
                    class="sm:col-span-2 lg:col-span-3"
                >
                    <Button
                        class="min-h-11"
                        type="button"
                        variant="outline"
                        @click="clearFilters"
                        ><X />Clear filters</Button
                    >
                </div>
            </section>

            <div
                v-if="products.data.length === 0"
                class="surface-panel border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <template v-if="hasActiveQuery">
                        <TriangleAlert
                            class="text-muted-foreground mx-auto size-8"
                        />
                        <p class="mt-3 font-medium">
                            No products match your search
                        </p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Try another search or clear the filters.
                        </p>
                    </template>
                    <template v-else>
                        <Boxes class="text-muted-foreground mx-auto size-8" />
                        <p class="mt-3 font-medium">No products available</p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Products will appear here after they are added to
                            the catalog.
                        </p>
                    </template>
                </div>
            </div>

            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="space-y-3 p-4 sm:p-5"
                >
                    <div
                        class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="min-w-0 font-semibold wrap-anywhere">
                                    {{ product.name }}
                                </h3>
                                <Badge
                                    :variant="
                                        product.is_active
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    >{{
                                        product.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}</Badge
                                >
                                <Badge
                                    v-if="
                                        product.stock_status === 'out_of_stock'
                                    "
                                    variant="outline"
                                    class="border-destructive/50 text-destructive"
                                    >Out of stock</Badge
                                >
                                <Badge
                                    v-else-if="
                                        product.stock_status === 'low_stock'
                                    "
                                    variant="outline"
                                    class="text-primary-ink"
                                    >Low stock</Badge
                                >
                            </div>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{ product.product_code
                                }}<span v-if="product.brand">
                                    · {{ product.brand }}</span
                                >
                                · {{ product.category }}
                            </p>
                            <p
                                v-if="product.inventory"
                                class="text-muted-foreground mt-1 text-xs"
                            >
                                Updated
                                {{
                                    formatLastUpdated(
                                        product.inventory.last_updated,
                                    )
                                }}
                            </p>
                        </div>
                        <dl
                            v-if="product.inventory"
                            class="grid grid-cols-3 gap-4 text-sm lg:gap-6"
                        >
                            <div>
                                <dt class="text-muted-foreground">Available</dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ product.inventory.quantity }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">On hold</dt>
                                <dd class="tabular-nums">
                                    {{ product.inventory.quarantined_quantity }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Reorder level
                                </dt>
                                <dd class="tabular-nums">
                                    {{ product.inventory.reorder_level }}
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-muted-foreground text-sm">
                            Stock not set
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <InlineEditor
                            v-if="product.inventory && canChangeStock"
                            :trigger-label="
                                (canAdjustStock
                                    ? 'Adjust stock for '
                                    : 'Request stock change for ') +
                                product.name
                            "
                            :label="
                                canAdjustStock
                                    ? 'Adjust stock'
                                    : 'Request stock change'
                            "
                            v-slot="{ reveal }"
                        >
                            <p
                                class="text-muted-foreground mb-3 text-sm"
                                role="note"
                            >
                                {{
                                    canAdjustStock
                                        ? 'Stock changes immediately after validation and confirmation.'
                                        : 'Manager or Owner approval is required before stock changes.'
                                }}
                            </p>
                            <Form
                                @error="reveal"
                                @input.capture="
                                    resetStockConfirmation($event, product.id)
                                "
                                @change.capture="
                                    resetStockConfirmation($event, product.id)
                                "
                                @success="stockSubmitted(product.id)"
                                :key="`stock-${product.id}-${product.inventory?.quantity}`"
                                v-if="product.inventory && canChangeStock"
                                v-bind="
                                    InventoryController.update.form(
                                        product.inventory.id,
                                    )
                                "
                                :options="{ preserveScroll: true }"
                                class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                                v-slot="{ errors, processing }"
                            >
                                <input
                                    type="hidden"
                                    name="observed_quantity"
                                    :value="product.inventory.quantity"
                                />
                                <div class="sm:col-span-3">
                                    <StockAdjustmentSummary
                                        :quantity-label="
                                            canAdjustStock
                                                ? 'Proposed'
                                                : 'Requested'
                                        "
                                        :current-quantity="
                                            product.inventory.quantity
                                        "
                                        :requested-quantity="
                                            requestedQuantities[product.id] ??
                                            product.inventory.quantity
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label
                                        :for="`quantity-${product.inventory.id}`"
                                    >
                                        New quantity
                                    </Label>
                                    <Input
                                        class="min-h-11"
                                        :id="`quantity-${product.inventory.id}`"
                                        name="quantity"
                                        type="number"
                                        min="0"
                                        max="4294967295"
                                        step="1"
                                        inputmode="numeric"
                                        :default-value="
                                            product.inventory.quantity
                                        "
                                        @update:model-value="
                                            requestedQuantities[product.id] =
                                                $event
                                        "
                                        :aria-invalid="Boolean(errors.quantity)"
                                        required
                                    />
                                    <InputError :message="errors.quantity" />
                                </div>

                                <div class="grid gap-2">
                                    <Label
                                        :for="`reorder-level-${product.inventory.id}`"
                                    >
                                        Reorder level
                                    </Label>
                                    <p
                                        :id="`reorder-level-${product.inventory.id}`"
                                        class="py-2 text-sm"
                                    >
                                        {{ product.inventory.reorder_level }}
                                    </p>
                                    <input
                                        type="hidden"
                                        name="reorder_level"
                                        :value="product.inventory.reorder_level"
                                    />
                                </div>

                                <div class="grid gap-2 sm:col-span-3">
                                    <Label
                                        :for="`reason-code-${product.inventory.id}`"
                                        >Reason</Label
                                    >
                                    <select
                                        :id="`reason-code-${product.inventory.id}`"
                                        name="reason_code"
                                        :value="
                                            requestReasonCodes[product.id] ?? ''
                                        "
                                        @change="
                                            requestReasonCodes[product.id] =
                                                $event.target.value
                                        "
                                        required
                                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <option value="">
                                            Select a reason
                                        </option>
                                        <option value="theft_loss">
                                            Theft or loss
                                        </option>
                                        <option value="found_stock">
                                            Found stock
                                        </option>
                                        <option value="count_error">
                                            Count correction
                                        </option>
                                        <option value="receiving_error">
                                            Receiving error
                                        </option>
                                        <option value="unit_of_measure_error">
                                            Wrong stock unit
                                        </option>
                                        <option value="other">Other</option>
                                    </select>
                                    <InputError :message="errors.reason_code" />
                                    <Label
                                        :for="`reason-${product.inventory.id}`"
                                        >{{
                                            requestReasonCodes[product.id] ===
                                                'other' ||
                                            (canAdjustStock &&
                                                requestReasonCodes[
                                                    product.id
                                                ] === 'theft_loss')
                                                ? 'Additional notes (required)'
                                                : 'Additional notes (optional)'
                                        }}</Label
                                    >
                                    <Input
                                        class="min-h-11"
                                        :id="`reason-${product.inventory.id}`"
                                        name="reason"
                                        :required="
                                            requestReasonCodes[product.id] ===
                                                'other' ||
                                            (canAdjustStock &&
                                                requestReasonCodes[
                                                    product.id
                                                ] === 'theft_loss')
                                        "
                                        minlength="5"
                                        maxlength="2000"
                                    />
                                    <InputError :message="errors.reason" />
                                    <p class="text-muted-foreground text-sm">
                                        For damaged stock, use
                                        <Link
                                            :href="
                                                DamageReportController.index()
                                            "
                                            class="text-primary-ink underline"
                                            >Damage Reports</Link
                                        >.
                                    </p>
                                </div>

                                <div
                                    v-if="canAdjustStock"
                                    class="grid gap-2 sm:col-span-3"
                                >
                                    <label
                                        class="flex items-start gap-2 text-sm"
                                    >
                                        <input
                                            v-model="
                                                stockConfirmations[product.id]
                                            "
                                            type="checkbox"
                                            name="stock_adjustment_confirmed"
                                            value="1"
                                            required
                                            class="mt-1"
                                        />
                                        I confirm the proposed quantity,
                                        difference, and reason above. Apply this
                                        stock change immediately.
                                    </label>
                                    <InputError
                                        :message="
                                            errors.stock_adjustment_confirmed
                                        "
                                    />
                                </div>

                                <Button
                                    class="min-h-11 sm:justify-self-start"
                                    type="submit"
                                    :disabled="
                                        processing ||
                                        (canAdjustStock &&
                                            !stockConfirmations[product.id])
                                    "
                                >
                                    <Spinner v-if="processing" />
                                    <Save v-else />
                                    {{
                                        canAdjustStock
                                            ? 'Adjust stock'
                                            : 'Request stock change'
                                    }}
                                </Button>
                            </Form>
                        </InlineEditor>
                        <InlineEditor
                            v-if="
                                product.inventory &&
                                $page.props.auth.can?.reorderInventory
                            "
                            :trigger-label="
                                'Edit reorder level for ' + product.name
                            "
                            label="Edit reorder level"
                            v-slot="{ reveal }"
                        >
                            <Form
                                @error="reveal"
                                v-if="
                                    product.inventory &&
                                    $page.props.auth.can?.reorderInventory
                                "
                                v-bind="
                                    InventoryController.updateReorderLevel.form(
                                        product.inventory.id,
                                    )
                                "
                                v-slot="{ errors, processing }"
                                class="flex flex-wrap items-end gap-3"
                            >
                                <label class="text-sm"
                                    >New reorder level<Input
                                        class="min-h-11"
                                        name="reorder_level"
                                        type="number"
                                        min="0"
                                        required
                                        :default-value="
                                            product.inventory.reorder_level
                                        "
                                /></label>
                                <label class="text-sm"
                                    >Reason<Input
                                        class="min-h-11"
                                        name="reason"
                                        required
                                        minlength="5"
                                        maxlength="2000"
                                /></label>
                                <Button
                                    class="min-h-11"
                                    type="submit"
                                    variant="outline"
                                    :disabled="processing"
                                    >Update reorder level</Button
                                >
                                <InputError
                                    :message="
                                        errors.reorder_level || errors.reason
                                    "
                                />
                            </Form>
                        </InlineEditor>
                        <InlineEditor
                            v-if="!product.inventory && canChangeStock"
                            :trigger-label="'Set up stock for ' + product.name"
                            label="Set up stock"
                            v-slot="{ reveal }"
                        >
                            <p
                                class="text-muted-foreground mb-3 text-sm"
                                role="note"
                            >
                                {{
                                    canAdjustStock
                                        ? 'Opening stock is available immediately after validation and confirmation.'
                                        : 'Manager or Owner approval is required for opening stock.'
                                }}
                            </p>
                            <Form
                                @error="reveal"
                                @input.capture="
                                    resetStockConfirmation($event, product.id)
                                "
                                @change.capture="
                                    resetStockConfirmation($event, product.id)
                                "
                                @success="stockSubmitted(product.id)"
                                v-if="!product.inventory && canChangeStock"
                                v-bind="
                                    InventoryController.store.form(product.id)
                                "
                                :options="{
                                    preserveScroll: true,
                                    preserveState: 'errors',
                                }"
                                :error-bag="`initializeInventory${product.id}`"
                                class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                                v-slot="{ errors, processing }"
                            >
                                <div class="sm:col-span-3">
                                    <Badge variant="outline"
                                        >Stock not set</Badge
                                    >
                                    <p
                                        class="text-muted-foreground mt-2 text-sm"
                                    >
                                        {{
                                            canAdjustStock
                                                ? 'Set starting quantities for this product.'
                                                : 'Request starting quantities for this product.'
                                        }}
                                    </p>
                                </div>

                                <div class="grid gap-2">
                                    <Label
                                        :for="`initial-quantity-${product.id}`"
                                    >
                                        Initial quantity
                                    </Label>
                                    <Input
                                        class="min-h-11"
                                        :id="`initial-quantity-${product.id}`"
                                        @update:model-value="
                                            requestedQuantities[product.id] =
                                                $event
                                        "
                                        name="quantity"
                                        type="number"
                                        min="0"
                                        max="4294967295"
                                        step="1"
                                        inputmode="numeric"
                                        :aria-invalid="Boolean(errors.quantity)"
                                        required
                                    />
                                    <InputError :message="errors.quantity" />
                                </div>

                                <div class="grid gap-2">
                                    <Label
                                        :for="`initial-reorder-level-${product.id}`"
                                    >
                                        Reorder level
                                    </Label>
                                    <Input
                                        class="min-h-11"
                                        :id="`initial-reorder-level-${product.id}`"
                                        name="reorder_level"
                                        type="number"
                                        min="0"
                                        max="4294967295"
                                        step="1"
                                        inputmode="numeric"
                                        :aria-invalid="
                                            Boolean(errors.reorder_level)
                                        "
                                        required
                                    />
                                    <InputError
                                        :message="errors.reorder_level"
                                    />
                                </div>

                                <div class="grid gap-2 sm:col-span-3">
                                    <Label
                                        :for="`opening-reason-code-${product.id}`"
                                        >Reason</Label
                                    >
                                    <select
                                        :id="`opening-reason-code-${product.id}`"
                                        name="reason_code"
                                        :value="
                                            requestReasonCodes[product.id] ?? ''
                                        "
                                        @change="
                                            requestReasonCodes[product.id] =
                                                $event.target.value
                                        "
                                        required
                                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <option value="">
                                            Select a reason
                                        </option>
                                        <option value="receiving_error">
                                            Initial receipt
                                        </option>
                                        <option value="found_stock">
                                            Found stock
                                        </option>
                                        <option value="other">Other</option>
                                    </select>
                                    <InputError :message="errors.reason_code" />
                                    <Label
                                        :for="`opening-reason-${product.id}`"
                                        >{{
                                            requestReasonCodes[product.id] ===
                                            'other'
                                                ? 'Additional notes (required)'
                                                : 'Additional notes (optional)'
                                        }}</Label
                                    >
                                    <Input
                                        class="min-h-11"
                                        :id="`opening-reason-${product.id}`"
                                        name="reason"
                                        :required="
                                            requestReasonCodes[product.id] ===
                                            'other'
                                        "
                                        minlength="5"
                                        maxlength="2000"
                                    />
                                    <InputError :message="errors.reason" />
                                </div>

                                <StockAdjustmentSummary
                                    :quantity-label="
                                        canAdjustStock
                                            ? 'Proposed'
                                            : 'Requested'
                                    "
                                    class="sm:col-span-3"
                                    :current-quantity="0"
                                    :requested-quantity="
                                        requestedQuantities[product.id] ?? ''
                                    "
                                />

                                <div
                                    v-if="canAdjustStock"
                                    class="grid gap-2 sm:col-span-3"
                                >
                                    <label
                                        class="flex items-start gap-2 text-sm"
                                    >
                                        <input
                                            v-model="
                                                stockConfirmations[product.id]
                                            "
                                            type="checkbox"
                                            name="stock_adjustment_confirmed"
                                            value="1"
                                            required
                                            class="mt-1"
                                        />
                                        I confirm the proposed quantity,
                                        difference, and reason above. Apply this
                                        stock change immediately.
                                    </label>
                                    <InputError
                                        :message="
                                            errors.stock_adjustment_confirmed
                                        "
                                    />
                                </div>

                                <Button
                                    class="min-h-11 sm:justify-self-start"
                                    type="submit"
                                    :disabled="
                                        processing ||
                                        (canAdjustStock &&
                                            !stockConfirmations[product.id])
                                    "
                                >
                                    <Spinner v-if="processing" />
                                    <Save v-else />
                                    {{
                                        canAdjustStock
                                            ? 'Set up stock'
                                            : 'Request opening stock'
                                    }}
                                </Button>
                            </Form>
                        </InlineEditor>
                    </div>
                </article>
            </div>

            <nav
                v-if="products.last_page > 1"
                aria-label="Inventory pages"
                class="mt-5 flex items-center justify-between gap-4"
            >
                <Button
                    class="min-h-11"
                    v-if="products.current_page > 1"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="inventoryPage(products.current_page - 1)"
                        preserve-scroll
                    >
                        <ChevronLeft />
                        Previous
                    </Link>
                </Button>
                <Button
                    class="min-h-11"
                    v-else
                    variant="outline"
                    size="sm"
                    disabled
                >
                    <ChevronLeft />
                    Previous
                </Button>
                <p class="text-muted-foreground text-sm">
                    Page {{ products.current_page }} of {{ products.last_page }}
                </p>
                <Button
                    v-if="products.current_page < products.last_page"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="inventoryPage(products.current_page + 1)"
                        preserve-scroll
                    >
                        Next
                        <ChevronRight />
                    </Link>
                </Button>
                <Button
                    class="min-h-11"
                    v-else
                    variant="outline"
                    size="sm"
                    disabled
                >
                    Next
                    <ChevronRight />
                </Button>
            </nav>
        </section>
    </main>
</template>
