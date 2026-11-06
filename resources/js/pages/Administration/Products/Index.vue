<script setup>
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Eye,
    Pencil,
    PackageSearch,
    Plus,
    RotateCcw,
    Search,
    Star,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProductActivationController from '@/actions/App/Http/Controllers/Administration/ProductActivationController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import CatalogNavigation from '@/components/CatalogNavigation.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import InputError from '@/components/InputError.vue';
import RecoverableDeletionDialog from '@/components/RecoverableDeletionDialog.vue';
import SearchField from '@/components/SearchField.vue';
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

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    deleted: { type: Boolean, default: false },
});
const page = usePage();
const canManageProducts = computed(
    () => page.props.auth?.can?.manageProducts === true,
);
const canCreateProduct = computed(
    () => page.props.auth?.can?.priceProducts === true,
);
const canDeleteProduct = computed(
    () => page.props.auth?.can?.deleteCatalog === true,
);

const categoryId = ref(String(props.filters.category_id ?? 'all'));
const brand = ref(props.filters.brand ?? 'all');
const tagId = ref(String(props.filters.tag_id ?? 'all'));
const status = ref(props.filters.status ?? 'all');

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: ProductController.index,
        query: selectedFilters,
    });

const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(() =>
    ['category_id', 'brand', 'tag_id', 'status'].some(
        (filter) => props.filters[filter] !== null,
    ),
);
const hasActiveQuery = computed(
    () => Boolean(props.filters.q) || hasAppliedFilters.value,
);

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

function formatPrice(value) {
    return currencyFormatter.format(Number(value));
}

function hideBrokenImage(event) {
    event.currentTarget.hidden = true;
}

function selectedValue(value) {
    return value === 'all' ? undefined : value;
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        brand: props.filters.brand ?? undefined,
        tag_id: props.filters.tag_id ?? undefined,
        status: props.filters.status ?? undefined,
    };
}

function productPage(options) {
    return ProductController.index({
        query: {
            ...props.filters,
            deleted: props.deleted ? 1 : undefined,
            page: options.query.page,
        },
    });
}

function selectedFilters() {
    return {
        deleted: props.deleted ? 1 : undefined,
        category_id: selectedValue(categoryId.value),
        brand: selectedValue(brand.value),
        tag_id: selectedValue(tagId.value),
        status: selectedValue(status.value),
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
        ProductController.index({
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
    brand.value = 'all';
    tagId.value = 'all';
    status.value = 'all';
}

watch([categoryId, brand, tagId, status], updateFilters);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Products',
                href: ProductController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <PackageSearch class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Sagay City catalog
                        </p>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            {{
                                canManageProducts
                                    ? 'Product management'
                                    : 'Products'
                            }}
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            {{
                                canCreateProduct
                                    ? 'Maintain the hardware details, pricing, categories, and tags used across Battlefront catalog workflows.'
                                    : canManageProducts
                                      ? 'Manage product details, categories, and tags. The owner controls prices.'
                                      : 'Browse product details, prices, availability, and catalog status.'
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canDeleteProduct"
                        variant="outline"
                        as-child
                        class="shrink-0"
                    >
                        <Link
                            :href="
                                ProductController.index({
                                    query: { deleted: deleted ? undefined : 1 },
                                })
                            "
                        >
                            <RotateCcw />
                            {{
                                deleted
                                    ? 'Back to Catalog products'
                                    : 'Restore archived products'
                            }}
                        </Link>
                    </Button>
                    <Button
                        v-if="canCreateProduct && !deleted"
                        as-child
                        class="shrink-0"
                    >
                        <Link :href="ProductController.create()">
                            <Plus />
                            Add product
                        </Link>
                    </Button>
                </div>
            </div>
            <CatalogNavigation />
        </section>

        <section
            v-if="!deleted"
            class="border-border bg-card border p-5"
            aria-labelledby="product-query-heading"
        >
            <div class="flex items-start gap-3">
                <span
                    class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                >
                    <SlidersHorizontal class="size-4" />
                </span>
                <div>
                    <h2 id="product-query-heading" class="font-semibold">
                        Find catalog records
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Search immediately; catalog attributes and lifecycle
                        status update results as they change.
                    </p>
                </div>
            </div>

            <div
                class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="admin-product-search">Search products</Label>
                    <div class="relative">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        />
                        <Input
                            id="admin-product-search"
                            v-model="search"
                            class="pl-9"
                            maxlength="255"
                            placeholder="Code, name, brand, or description"
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

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="admin-product-category">Category</Label>
                    <Select v-model="categoryId">
                        <SelectTrigger
                            id="admin-product-category"
                            class="w-full"
                        >
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            <SelectItem
                                v-for="category in filter_options.categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                                {{ category.is_active ? '' : '(Inactive)' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="admin-product-brand">Brand</Label>
                    <Select v-model="brand">
                        <SelectTrigger id="admin-product-brand" class="w-full">
                            <SelectValue placeholder="All brands" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All brands</SelectItem>
                            <SelectItem
                                v-for="brandOption in filter_options.brands"
                                :key="brandOption"
                                :value="brandOption"
                            >
                                {{ brandOption }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="admin-product-tag">Tag</Label>
                    <Select v-model="tagId">
                        <SelectTrigger id="admin-product-tag" class="w-full">
                            <SelectValue placeholder="All tags" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All tags</SelectItem>
                            <SelectItem
                                v-for="tag in filter_options.tags"
                                :key="tag.id"
                                :value="String(tag.id)"
                            >
                                {{ tag.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="admin-product-status">Status</Label>
                    <Select v-model="status">
                        <SelectTrigger id="admin-product-status" class="w-full">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div
                    class="flex flex-wrap gap-2 md:col-span-2 md:justify-end xl:col-span-4"
                >
                    <Button
                        v-if="hasAppliedFilters"
                        type="button"
                        variant="outline"
                        @click="clearFilters"
                    >
                        <X />
                        Clear filters
                    </Button>
                </div>
            </div>
        </section>

        <section
            v-else
            class="border-border bg-card border p-5"
            aria-label="Search archived products"
        >
            <SearchField
                id="deleted-product-search"
                v-model="search"
                class="max-w-xl"
                label="Search archived products"
                placeholder="Code, name, brand, or description"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
        </section>

        <section aria-labelledby="product-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ products.total }}
                    {{ deleted ? 'archived products' : 'products' }}
                </p>
                <h2 id="product-list-heading" class="text-xl font-semibold">
                    {{ deleted ? 'Archived products' : 'Catalog products' }}
                </h2>
            </div>

            <div
                v-if="products.data.length === 0"
                class="surface-panel border-border bg-card flex min-h-52 items-center justify-center border p-4 text-center sm:p-6"
            >
                <div>
                    <PackageSearch
                        class="text-muted-foreground mx-auto size-9"
                    />
                    <p class="mt-3 font-medium">
                        {{
                            hasActiveQuery
                                ? 'No products match this query'
                                : deleted
                                  ? 'No archived products'
                                  : 'No products available'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{
                            hasActiveQuery
                                ? 'Try another search term or clear the applied filters.'
                                : canCreateProduct
                                  ? 'Add the first Battlefront catalog product to get started.'
                                  : 'No product records are available yet.'
                        }}
                    </p>
                    <Button
                        v-if="!deleted && !hasActiveQuery && canCreateProduct"
                        as-child
                        class="mt-5"
                    >
                        <Link :href="ProductController.create()">
                            <Plus />
                            Add product
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="grid gap-5 p-5 lg:grid-cols-[5rem_minmax(0,1.4fr)_minmax(10rem,0.7fr)_auto] lg:items-center"
                >
                    <div
                        class="border-border bg-muted/40 relative flex aspect-square w-20 items-center justify-center overflow-hidden border"
                    >
                        <PackageSearch class="text-muted-foreground size-6" />
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="`${product.name} product image`"
                            class="absolute inset-0 size-full object-contain"
                            @error="hideBrokenImage"
                        />
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ product.name }}</h3>
                            <Badge v-if="!deleted" variant="outline">{{
                                product.shipping_handling.label
                            }}</Badge>
                            <Badge
                                :variant="
                                    product.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{
                                    deleted
                                        ? 'Archived'
                                        : product.is_active
                                          ? 'Active'
                                          : 'Inactive'
                                }}
                            </Badge>
                            <Badge v-if="product.is_featured" variant="outline">
                                <Star />
                                Featured
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ product.product_code
                            }}<span v-if="product.brand">
                                · {{ product.brand }}</span
                            >
                            ·
                            {{ product.category.name }}
                            <span v-if="!product.category.is_active">
                                (Inactive category)
                            </span>
                        </p>
                        <p
                            v-if="deleted && product.category.is_deleted"
                            class="text-destructive mt-2 text-xs"
                        >
                            Restore this product's category before restoring the
                            product.
                        </p>
                        <p
                            v-if="deleted"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            Archived {{ product.deleted_at }} by
                            {{ product.deleted_by || 'an administrator' }}
                        </p>
                        <p v-else class="text-muted-foreground mt-2 text-xs">
                            {{
                                formatPrice(product.shipping_handling.surcharge)
                            }}
                            additional surcharge per delivery order ·
                            {{ product.shipping_handling.preparation_days }}
                            {{
                                product.shipping_handling.preparation_days === 1
                                    ? 'day'
                                    : 'days'
                            }}
                            preparation on configured routes
                        </p>
                        <div
                            class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs"
                        >
                            <span v-if="!deleted" class="text-muted-foreground"
                                >Storefront:
                                {{ product.storefront_visibility }}</span
                            >
                            <span
                                v-if="product.inventory"
                                class="text-muted-foreground"
                                >Sellable {{ product.inventory.quantity }} ·
                                Quarantined
                                {{
                                    product.inventory.quarantined_quantity
                                }}</span
                            >
                            <span v-else class="text-muted-foreground"
                                >Stock not initialized</span
                            >
                        </div>
                        <div
                            v-if="product.tags.length > 0"
                            class="mt-3 flex flex-wrap gap-1.5"
                        >
                            <Badge
                                v-for="tag in product.tags.slice(0, 3)"
                                :key="tag.id"
                                variant="outline"
                            >
                                {{ tag.name }}
                            </Badge>
                            <span
                                v-if="product.tags.length > 3"
                                class="text-muted-foreground self-center text-xs"
                            >
                                +{{ product.tags.length - 3 }} more
                            </span>
                        </div>
                    </div>

                    <div>
                        <p class="text-muted-foreground text-xs">
                            {{
                                product.discount_price
                                    ? 'Discount price'
                                    : 'Regular price'
                            }}
                        </p>
                        <p class="mt-1 font-semibold tabular-nums">
                            {{
                                formatPrice(
                                    product.discount_price ?? product.price,
                                )
                            }}
                        </p>
                        <p
                            v-if="product.discount_price"
                            class="text-muted-foreground mt-1 text-sm tabular-nums line-through"
                        >
                            {{ formatPrice(product.price) }}
                        </p>
                        <p
                            v-if="product.acquisition_cost !== undefined"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            Acquisition cost ·
                            {{ formatPrice(product.acquisition_cost) }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                    >
                        <Button
                            v-if="!deleted"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="ProductController.show(product.id)">
                                <Eye />
                                View
                            </Link>
                        </Button>

                        <Button
                            v-if="canManageProducts && !deleted"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="ProductController.edit(product.id)">
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <DeactivationDialog
                            v-if="
                                !deleted &&
                                canManageProducts &&
                                product.is_active
                            "
                            :name="product.name"
                            :form="ProductActivationController.form(product.id)"
                            description="This temporarily hides the product from customer browsing and recommendations. It remains in Catalog products and its inventory and history are preserved. Existing cart lines stay visible as unavailable."
                        />

                        <Form
                            v-else-if="!deleted && canManageProducts"
                            v-bind="
                                ProductActivationController.form(product.id)
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <input type="hidden" name="is_active" value="1" />
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Reactivate
                            </Button>
                        </Form>
                        <Form
                            v-if="deleted"
                            v-bind="ProductController.restore.form(product.id)"
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing, errors }"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Restore archived product
                            </Button>
                            <InputError :message="errors.restore" />
                        </Form>
                        <RecoverableDeletionDialog
                            v-if="canDeleteProduct && !deleted"
                            :name="product.name"
                            resource-name="product"
                            collection-name="Archived products"
                            :form="ProductController.destroy.form(product.id)"
                            :error-bag="`deleteProduct${product.id}`"
                        />
                    </div>
                </article>
            </div>

            <CatalogPagination
                :current-page="products.current_page"
                :last-page="products.last_page"
                :route="productPage"
                label="Product pages"
            />
        </section>
    </main>
</template>
