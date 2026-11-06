<script setup>
import PageHeader from '@/components/PageHeader.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { Boxes, Search, SlidersHorizontal, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProductCard from '@/components/catalog/ProductCard.vue';
import DeferredContent from '@/components/DeferredContent.vue';
import ProductCardSkeleton from '@/components/ProductCardSkeleton.vue';
import RecommendationSkeleton from '@/components/RecommendationSkeleton.vue';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
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
import { index as productIndex } from '@/routes/products';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    recommendations: { type: Array, default: () => [] },
    recommendations_enabled: { type: Boolean, default: true },
    is_personalized: { type: Boolean, default: false },
    has_featured_fallback: { type: Boolean, default: false },
});

const mobileFiltersOpen = ref(false);
const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const categoryId = ref(String(props.filters.category_id ?? 'all'));
const brand = ref(props.filters.brand ?? 'all');
const tagId = ref(String(props.filters.tag_id ?? 'all'));
const sort = ref(props.filters.sort ?? 'featured');
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
const activeFilters = computed(() =>
    [
        {
            key: 'category_id',
            value: categoryId.value,
            label:
                props.filter_options.categories.find(
                    (item) => String(item.id) === categoryId.value,
                )?.name ?? categoryPlaceholder.value,
        },
        { key: 'brand', value: brand.value, label: brand.value },
        {
            key: 'tag_id',
            value: tagId.value,
            label:
                props.filter_options.tags.find(
                    (item) => String(item.id) === tagId.value,
                )?.name ?? tagPlaceholder.value,
        },
    ].filter((filter) => filter.value !== 'all'),
);

function clearFilter(key) {
    if (key === 'category_id') {
        categoryId.value = 'all';
    }
    if (key === 'brand') {
        brand.value = 'all';
    }
    if (key === 'tag_id') {
        tagId.value = 'all';
    }
}

const catalogReloadProps = [
    'products',
    'filters',
    'filter_options',
    'recommendations',
    'recommendations_enabled',
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
        (filter) => props.filters[filter] != null,
    ),
);
const hasActiveQuery = computed(
    () => hasSearch.value || hasAppliedFilters.value,
);
const isDiscoveryView = computed(
    () =>
        !hasActiveQuery.value &&
        !hasSearchInput.value &&
        [categoryId.value, brand.value, tagId.value].every(
            (value) => value === 'all',
        ),
);
const showRecommendations = computed(
    () => isDiscoveryView.value && props.recommendations.length > 0,
);

function selectedValue(value) {
    return value === 'all' ? undefined : value;
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        brand: props.filters.brand ?? undefined,
        tag_id: props.filters.tag_id ?? undefined,
        sort:
            props.filters.sort === 'featured' ? undefined : props.filters.sort,
    };
}

function selectedFilters() {
    return {
        category_id: selectedValue(categoryId.value),
        brand: selectedValue(brand.value),
        tag_id: selectedValue(tagId.value),
        ...(sort.value === 'featured' ? {} : { sort: sort.value }),
    };
}

function filtersAreCurrent() {
    const selected = selectedFilters();
    const applied = appliedFilters();

    return ['category_id', 'brand', 'tag_id', 'sort'].every(
        (filter) =>
            String(selected[filter] ?? '') === String(applied[filter] ?? ''),
    );
}

function updateFilters() {
    if (filtersAreCurrent()) {
        return;
    }

    cancelPendingSearch();
    const sortChanged = sort.value !== (props.filters.sort ?? 'featured');
    if (sortChanged) {
        router.cancelAll({ prefetch: false });
    }

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
            replace: !sortChanged,
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

watch([categoryId, brand, tagId, sort], updateFilters);
watch(
    () => props.filters,
    (filters) => {
        cancelPendingSearch();
        categoryId.value = String(filters.category_id ?? 'all');
        brand.value = filters.brand ?? 'all';
        tagId.value = String(filters.tag_id ?? 'all');
        sort.value = filters.sort ?? 'featured';
        search.value = filters.q ?? '';
    },
    { flush: 'sync' },
);
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Shop products">
            <meta
                head-key="description"
                name="description"
                content="Browse available computer hardware from Battlefront Computer Trading."
            />
        </Head>

        <StorefrontHeader active-section="products" />

        <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <PageHeader title="Shop products" heading-id="catalog-heading" />

            <section class="space-y-3" aria-label="Search and filter products">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                    <div class="grid min-w-0 gap-2">
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
                        <span class="sr-only" role="status">
                            {{ isSearching ? 'Updating results...' : '' }}
                        </span>
                    </div>
                    <div class="grid min-w-0 gap-2">
                        <Label id="catalog-sort-label" for="catalog-sort"
                            >Sort by</Label
                        >
                        <Select v-model="sort">
                            <SelectTrigger
                                id="catalog-sort"
                                aria-labelledby="catalog-sort-label"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="featured"
                                    >Featured First</SelectItem
                                >
                                <SelectItem value="price_asc"
                                    >Price: Low to High</SelectItem
                                >
                                <SelectItem value="price_desc"
                                    >Price: High to Low</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <Collapsible v-model:open="mobileFiltersOpen" class="space-y-3">
                    <CollapsibleTrigger as-child class="md:hidden">
                        <Button
                            id="catalog-filters-toggle"
                            type="button"
                            variant="outline"
                        >
                            <SlidersHorizontal aria-hidden="true" />
                            Filters
                            <span v-if="activeFilters.length"
                                >( {{ activeFilters.length }} )</span
                            >
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent
                        force-mount
                        class="grid gap-3 data-[state=closed]:hidden md:grid-cols-3 md:data-[state=closed]:grid"
                    >
                        <div class="grid min-w-0 gap-2">
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
                                    <SelectItem value="all"
                                        >All categories</SelectItem
                                    >
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
                        <div class="grid min-w-0 gap-2">
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
                                    <SelectItem value="all"
                                        >All brands</SelectItem
                                    >
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
                        <div class="grid min-w-0 gap-2">
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
                    </CollapsibleContent>
                </Collapsible>
                <div
                    v-if="hasSearchInput || activeFilters.length"
                    class="flex flex-wrap items-center gap-2"
                    aria-label="Active filters"
                >
                    <Button
                        v-if="hasSearchInput"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="clearSearch"
                    >
                        <X aria-hidden="true" />
                        Clear search
                    </Button>
                    <Button
                        v-for="filter in activeFilters"
                        :key="filter.key"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-auto min-h-10 max-w-full text-left whitespace-normal"
                        :aria-label="`Remove filter: ${filter.label}`"
                        @click="clearFilter(filter.key)"
                    >
                        {{ filter.label }}
                        <X class="shrink-0" aria-hidden="true" />
                    </Button>
                    <Button
                        v-if="hasAppliedFilters"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                    >
                        Clear filters
                    </Button>
                </div>
            </section>

            <div v-if="recommendations_enabled && isDiscoveryView">
                <DeferredContent
                    :data="[
                        'recommendations',
                        'is_personalized',
                        'has_featured_fallback',
                        'guest_recommendation_scope',
                    ]"
                    label="Loading product recommendations…"
                    error-message="Product recommendations could not be loaded."
                >
                    <template #fallback>
                        <RecommendationSkeleton />
                    </template>
                    <RecommendationSection
                        v-if="showRecommendations"
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
                </DeferredContent>
            </div>

            <section aria-labelledby="product-list-heading">
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-2"
                >
                    <h2 id="product-list-heading" class="text-lg font-semibold">
                        All products
                    </h2>
                    <p
                        v-if="products.total"
                        class="text-muted-foreground text-sm"
                        role="status"
                    >
                        Showing
                        {{ products.data.length }}
                        of
                        {{ products.total }}
                    </p>
                </div>

                <InfiniteScroll
                    v-if="products.data.length"
                    data="products"
                    :buffer="300"
                    class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <ProductCard
                        v-for="product in products.data"
                        :key="product.id"
                        :product="product"
                    />
                    <template #next="{ loading, hasMore }">
                        <div v-if="loading" role="status" class="col-span-full">
                            <span class="sr-only">Loading more products…</span>
                            <div
                                class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
                            >
                                <ProductCardSkeleton
                                    v-for="card in 4"
                                    :key="card"
                                    :class="
                                        card === 3
                                            ? 'hidden lg:flex'
                                            : card === 4
                                              ? 'hidden xl:flex'
                                              : ''
                                    "
                                />
                            </div>
                        </div>
                        <p
                            v-else-if="!hasMore"
                            class="text-muted-foreground col-span-full mt-6 text-center text-sm"
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
