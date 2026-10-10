<script setup>
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Eye,
    Pencil,
    PackageSearch,
    Plus,
    RotateCcw,
    Search,
    SlidersHorizontal,
    Star,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProductActivationController from '@/actions/App/Http/Controllers/Administration/ProductActivationController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import CatalogNavigation from '@/components/CatalogNavigation.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import PermanentDeletionDialog from '@/components/PermanentDeletionDialog.vue';
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
});

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
            page: options.query.page,
        },
    });
}

function selectedFilters() {
    return {
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
    <Head title="Products" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
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
                            Product management
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            Maintain the hardware details, pricing, categories,
                            and tags used across Battlefront catalog workflows.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="ProductController.create()">
                        <Plus />
                        Add product
                    </Link>
                </Button>
            </div>
            <CatalogNavigation />
        </section>

        <section
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
                class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
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
                    <p class="text-muted-foreground text-xs" aria-live="polite">
                        {{
                            isSearching
                                ? 'Updating results...'
                                : 'Results update automatically as you type.'
                        }}
                    </p>
                </div>
                <Button
                    v-if="hasSearchInput"
                    type="button"
                    variant="outline"
                    class="sm:mb-5"
                    @click="clearSearch"
                >
                    <X />
                    Clear search
                </Button>
            </div>

            <div
                class="border-border mt-5 grid gap-4 border-t pt-5 md:grid-cols-2 xl:grid-cols-4"
            >
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

        <section aria-labelledby="product-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ products.total }} products
                </p>
                <h2 id="product-list-heading" class="text-xl font-semibold">
                    Catalog products
                </h2>
            </div>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
            >
                <div>
                    <PackageSearch
                        class="text-muted-foreground mx-auto size-9"
                    />
                    <p class="mt-3 font-medium">
                        {{
                            hasActiveQuery
                                ? 'No products match this query'
                                : 'No products available'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{
                            hasActiveQuery
                                ? 'Try another search term or clear the applied filters.'
                                : 'Add the first Battlefront catalog product to get started.'
                        }}
                    </p>
                    <Button v-if="!hasActiveQuery" as-child class="mt-5">
                        <Link :href="ProductController.create()">
                            <Plus />
                            Add product
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
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
                            <Badge
                                :variant="
                                    product.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ product.is_active ? 'Active' : 'Inactive' }}
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
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="ProductController.show(product.id)">
                                <Eye />
                                View
                            </Link>
                        </Button>

                        <Button variant="outline" size="sm" as-child>
                            <Link :href="ProductController.edit(product.id)">
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <DeactivationDialog
                            v-if="product.is_active"
                            :name="product.name"
                            :form="ProductActivationController.form(product.id)"
                            description="This product will no longer appear in customer catalog, cart, or recommendation workflows. Its record, category, tags, and inventory are preserved."
                        />

                        <Form
                            v-else
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
                        <PermanentDeletionDialog
                            :name="product.name"
                            :form="ProductController.destroy.form(product.id)"
                            :error-bag="`deleteProduct${product.id}`"
                            :imported="product.is_catalog_imported"
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
