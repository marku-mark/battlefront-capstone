<script setup>
import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    PackageSearch,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { useProductPrefetch } from '@/composables/useProductPrefetch';
import { rememberCatalogVisit } from '@/lib/catalogReturn';
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
import { index as productIndex, show as productShow } from '@/routes/products';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    recommendations: { type: Array, default: () => [] },
    is_personalized: { type: Boolean, default: false },
    has_featured_fallback: { type: Boolean, default: false },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const categoryId = ref(String(props.filters.category_id ?? 'all'));
const brand = ref(props.filters.brand ?? 'all');
const tagId = ref(String(props.filters.tag_id ?? 'all'));
const categoryPlaceholder = computed(() =>
    categoryId.value !== 'all' &&
    !props.filter_options.categories.some(
        (category) => String(category.id) === categoryId.value,
    )
        ? 'Selected category unavailable'
        : 'All categories',
);
const brandPlaceholder = computed(() =>
    brand.value !== 'all' && !props.filter_options.brands.includes(brand.value)
        ? 'Selected brand unavailable'
        : 'All brands',
);
const tagPlaceholder = computed(() =>
    tagId.value !== 'all' &&
    !props.filter_options.tags.some((tag) => String(tag.id) === tagId.value)
        ? 'Selected tag unavailable'
        : 'All tags',
);
const { prefetchProduct, cancelProductPrefetch } = useProductPrefetch();
const catalogReloadProps = [
    'products',
    'filters',
    'filter_options',
    'recommendations',
    'is_personalized',
    'has_featured_fallback',
    'guest_recommendation_scope',
];

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: productIndex,
        query: selectedFilters,
        reset: ['products'],
        only: catalogReloadProps,
        preserveScroll: false,
    });

const hasSearch = computed(() => Boolean(props.filters.q));
const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(() =>
    ['category_id', 'brand', 'tag_id'].some(
        (filter) => props.filters[filter] !== null,
    ),
);
const hasActiveQuery = computed(
    () => hasSearch.value || hasAppliedFilters.value,
);
const showRecommendations = computed(
    () =>
        props.recommendations.length > 0 &&
        !hasActiveQuery.value &&
        !hasSearchInput.value &&
        [categoryId.value, brand.value, tagId.value].every(
            (value) => value === 'all',
        ),
);

function selectedValue(value) {
    return value === 'all' ? undefined : value;
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        brand: props.filters.brand ?? undefined,
        tag_id: props.filters.tag_id ?? undefined,
    };
}

function selectedFilters() {
    return {
        category_id: selectedValue(categoryId.value),
        brand: selectedValue(brand.value),
        tag_id: selectedValue(tagId.value),
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
        productIndex({
            query: {
                q: search.value.trim() || undefined,
                ...selectedFilters(),
            },
        }),
        {
            preserveScroll: false,
            preserveState: true,
            replace: true,
            reset: ['products'],
            only: catalogReloadProps,
        },
    );
}

function clearFilters() {
    cancelPendingSearch();
    categoryId.value = 'all';
    brand.value = 'all';
    tagId.value = 'all';
}

watch([categoryId, brand, tagId], updateFilters);
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Products">
            <meta
                head-key="description"
                name="description"
                content="Browse available computer hardware from Battlefront Computer Trading."
            />
        </Head>

        <StorefrontHeader active-section="products" />

        <main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
            <section
                class="border-border bg-card relative overflow-hidden border px-6 py-8 sm:px-10 lg:grid lg:grid-cols-[1.45fr_0.55fr] lg:items-end lg:gap-10"
                aria-labelledby="catalog-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1.5"
                    aria-hidden="true"
                />
                <div>
                    <div
                        class="border-primary/30 bg-primary/10 text-primary mb-4 flex size-11 items-center justify-center border"
                    >
                        <PackageSearch class="size-5" aria-hidden="true" />
                    </div>
                    <p
                        class="text-primary text-xs font-bold tracking-[0.2em] uppercase"
                    >
                        Customer catalog
                    </p>
                    <h1
                        id="catalog-heading"
                        class="mt-3 max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        Computer hardware, clearly presented
                    </h1>
                    <p
                        class="text-muted-foreground mt-4 max-w-2xl text-base leading-7"
                    >
                        Review current product details, pricing, and stock
                        availability before choosing the right hardware for your
                        setup.
                    </p>
                </div>

                <div
                    class="border-border mt-8 border-t pt-6 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10"
                >
                    <p class="text-muted-foreground text-sm font-medium">
                        {{
                            hasActiveQuery
                                ? 'Products matching your catalog query'
                                : 'Products available to browse'
                        }}
                    </p>
                    <p class="mt-1 text-3xl font-bold">{{ products.total }}</p>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Stock information reflects the current catalog record.
                    </p>
                </div>
            </section>

            <div v-if="showRecommendations" class="mt-8">
                <RecommendationSection
                    :recommendations="recommendations"
                    placement="catalog"
                    :title="
                        is_personalized
                            ? 'Recommended for you'
                            : has_featured_fallback
                              ? 'Popular and featured products'
                              : 'Popular products'
                    "
                    :description="
                        is_personalized
                            ? isAuthenticated
                                ? 'Suggestions based on your recent browsing, cart, and completed purchases.'
                                : 'Suggestions based on your recent browsing in this browser.'
                            : 'Popular and featured picks with current Sagay stock.'
                    "
                />
            </div>

            <div class="border-border mt-8 border-t pt-8">
                <h2 class="text-2xl font-bold tracking-tight">All products</h2>
            </div>

            <section
                class="border-border bg-card mt-6 border p-5 sm:p-6"
                aria-labelledby="catalog-filters-heading"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 id="catalog-filters-heading" class="font-bold">
                            Find the right hardware
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Search product details or narrow the catalog by
                            category, brand, and tag.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                >
                    <div class="grid gap-2">
                        <Label for="catalog-search">Search products</Label>
                        <div class="relative">
                            <Search
                                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                                aria-hidden="true"
                            />
                            <Input
                                id="catalog-search"
                                v-model="search"
                                class="pl-9"
                                maxlength="255"
                                placeholder="Name, brand, or description"
                                autocomplete="off"
                            />
                        </div>
                        <p
                            class="text-muted-foreground text-xs"
                            aria-live="polite"
                        >
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
                        <X aria-hidden="true" />
                        Clear search
                    </Button>
                </div>

                <div class="border-border mt-4 border-t pt-4">
                    <div>
                        <h3 class="font-semibold">Filter products</h3>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Category, brand, and tag selections update results
                            automatically and remain separate from search.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="catalog-category">Category</Label>
                            <Select v-model="categoryId">
                                <SelectTrigger
                                    id="catalog-category"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="categoryPlaceholder"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All categories
                                    </SelectItem>
                                    <SelectItem
                                        v-for="category in filter_options.categories"
                                        :key="category.id"
                                        :value="String(category.id)"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="catalog-brand">Brand</Label>
                            <Select v-model="brand">
                                <SelectTrigger
                                    id="catalog-brand"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="brandPlaceholder"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All brands
                                    </SelectItem>
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
                            <Label for="catalog-tag">Tag</Label>
                            <Select v-model="tagId">
                                <SelectTrigger id="catalog-tag" class="w-full">
                                    <SelectValue
                                        :placeholder="tagPlaceholder"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All tags</SelectItem
                                    >
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

                        <div
                            class="flex flex-wrap gap-2 md:col-span-3 md:justify-end"
                        >
                            <Button
                                v-if="hasAppliedFilters"
                                type="button"
                                variant="outline"
                                @click="clearFilters"
                            >
                                <X aria-hidden="true" />
                                Clear filters
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-8" aria-labelledby="product-list-heading">
                <div class="mb-6 flex items-end justify-between gap-6">
                    <div>
                        <p
                            class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                        >
                            Product lineup
                        </p>
                        <h2
                            id="product-list-heading"
                            class="mt-2 text-2xl font-bold tracking-tight"
                        >
                            Browse the catalog
                        </h2>
                    </div>
                    <p
                        v-if="products.total"
                        class="text-muted-foreground hidden text-sm sm:block"
                    >
                        Showing {{ products.data.length }} of
                        {{ products.total }}
                    </p>
                </div>

                <InfiniteScroll
                    v-if="products.data.length"
                    data="products"
                    :buffer="300"
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <Link
                        v-for="product in products.data"
                        :key="product.id"
                        :href="productShow(product.id)"
                        @pointerenter="
                            prefetchProduct($event, productShow(product.id))
                        "
                        @pointerleave="cancelProductPrefetch"
                        @pointercancel="cancelProductPrefetch"
                        @pointerdown="cancelProductPrefetch"
                        @click.capture="
                            cancelProductPrefetch();
                            rememberCatalogVisit($event, product.id);
                        "
                        class="border-border bg-card focus-visible:ring-ring group hover:border-primary/60 flex min-h-full flex-col overflow-hidden border transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <div class="aspect-3/2 overflow-hidden">
                            <ProductImage
                                :image-url="product.image_url"
                                :product-name="product.name"
                                class="transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                            />
                        </div>

                        <article class="flex flex-1 flex-col gap-3 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {{ product.category.name }}
                                </Badge>
                                <Badge v-if="product.is_featured">
                                    Featured
                                </Badge>
                            </div>

                            <div>
                                <p
                                    v-if="product.brand"
                                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                >
                                    {{ product.brand }}
                                </p>
                                <h3
                                    class="mt-1 text-lg leading-snug font-bold tracking-tight"
                                >
                                    {{ product.name }}
                                </h3>
                                <p
                                    v-if="product.description"
                                    class="text-muted-foreground mt-2 line-clamp-2 text-sm leading-6"
                                >
                                    {{ product.description }}
                                </p>
                            </div>

                            <div class="mt-auto flex flex-col gap-3">
                                <ProductPrice
                                    :price="product.price"
                                    :discount-price="product.discount_price"
                                />
                                <div
                                    class="flex items-end justify-between gap-3"
                                >
                                    <StockAvailability
                                        :inventory="product.inventory"
                                    />
                                    <ArrowRight
                                        class="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                        </article>
                    </Link>
                    <template #next="{ loading, hasMore }">
                        <p
                            v-if="loading"
                            role="status"
                            class="text-muted-foreground mt-6 text-center text-sm"
                        >
                            Loading more products...
                        </p>
                        <p
                            v-else-if="!hasMore"
                            class="text-muted-foreground mt-6 text-center text-sm"
                        >
                            You've reached the end of the catalog.
                        </p>
                    </template>
                </InfiniteScroll>

                <div
                    v-else
                    class="border-border bg-muted/30 border border-dashed px-6 py-16 text-center"
                >
                    <Boxes
                        class="text-muted-foreground mx-auto size-8"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold">
                        {{
                            hasActiveQuery
                                ? 'No products match your search and filters.'
                                : 'No products are currently available to browse.'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{
                            hasActiveQuery
                                ? 'Try another search term or clear the applied filters.'
                                : 'Please check again later for catalog updates.'
                        }}
                    </p>
                    <div
                        v-if="hasActiveQuery"
                        class="mt-5 flex flex-wrap justify-center gap-2"
                    >
                        <Button
                            v-if="hasSearch"
                            type="button"
                            variant="outline"
                            @click="clearSearch"
                        >
                            Clear search
                        </Button>
                        <Button
                            v-if="hasAppliedFilters"
                            type="button"
                            variant="outline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
