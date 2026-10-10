<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, LogIn, PackageOpen, Tag } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import AddToCartForm from '@/components/cart/AddToCartForm.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { consumeCatalogVisit } from '@/lib/catalogReturn';
import { login } from '@/routes';
import { index as productIndex } from '@/routes/products';
import { store as storeProductDwell } from '@/routes/products/dwell';
import { store as storeProductView } from '@/routes/products/view';

const props = defineProps({
    product: { type: Object, required: true },
    can_record_product_dwell: { type: Boolean, default: false },
    is_personalized: { type: Boolean, default: false },
    has_featured_fallback: { type: Boolean, default: false },
    recommendations: { type: Array, default: () => [] },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const canUseCustomerCart = computed(
    () => page.props.auth?.can?.useCustomerCart === true,
);
const hasAvailableStock = computed(
    () => Number(props.product.inventory.quantity) > 0,
);
const canReturnToCatalog = consumeCatalogVisit(props.product.id);
let visibleSince = null;
let accumulatedVisibleMilliseconds = 0;
let trackedProductId = props.product.id;
let viewRequest = null;

function postActivity(route, payload = {}) {
    const xsrfToken = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
    return fetch(route.url, {
        method: route.method.toUpperCase(),
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken,
        },
        body: JSON.stringify(payload),
    })
        .then((response) => response.ok)
        .catch(() => false);
}

function startViewing() {
    if (
        !props.can_record_product_dwell ||
        document.visibilityState !== 'visible'
    )
        return;
    viewRequest ??= postActivity(storeProductView.post(trackedProductId));
    visibleSince ??= performance.now();
}

function recordVisibleTime() {
    if (!props.can_record_product_dwell) {
        return;
    }

    const visibleMilliseconds =
        accumulatedVisibleMilliseconds +
        (visibleSince === null ? 0 : performance.now() - visibleSince);
    const seconds = Math.min(3600, Math.floor(visibleMilliseconds / 1000));

    if (seconds < 5) {
        return;
    }

    const route = storeProductDwell.post(trackedProductId);
    viewRequest?.then((recorded) => {
        if (recorded) postActivity(route, { seconds });
    });
}

watch(
    () => props.product.id,
    (productId) => {
        if (visibleSince !== null) {
            accumulatedVisibleMilliseconds += performance.now() - visibleSince;
            visibleSince = null;
        }

        recordVisibleTime();
        accumulatedVisibleMilliseconds = 0;
        trackedProductId = productId;
        viewRequest = null;
        startViewing();
    },
);

function handleVisibilityChange() {
    if (document.visibilityState === 'hidden') {
        if (visibleSince !== null) {
            accumulatedVisibleMilliseconds += performance.now() - visibleSince;
            visibleSince = null;
        }

        recordVisibleTime();

        return;
    }

    startViewing();
}

onMounted(() => {
    if (!props.can_record_product_dwell) {
        return;
    }

    startViewing();
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', handleVisibilityChange);

    if (visibleSince !== null) {
        accumulatedVisibleMilliseconds += performance.now() - visibleSince;
        visibleSince = null;
    }

    recordVisibleTime();
});

function returnToCatalog() {
    window.history.back();
}
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head :title="product.name">
            <meta
                head-key="description"
                name="description"
                :content="
                    product.description ??
                    `View ${product.name} product details and stock availability.`
                "
            />
        </Head>

        <StorefrontHeader active-section="products" />

        <main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-12">
            <Button
                v-if="canReturnToCatalog"
                type="button"
                variant="ghost"
                class="-ml-3"
                @click="returnToCatalog"
            >
                <ArrowLeft aria-hidden="true" />
                Back to products
            </Button>
            <Button v-else as-child variant="ghost" class="-ml-3">
                <Link :href="productIndex()">
                    <ArrowLeft aria-hidden="true" />
                    Back to products
                </Link>
            </Button>

            <article
                class="border-border bg-card mt-6 grid overflow-hidden border lg:grid-cols-[minmax(0,1.3fr)_minmax(22rem,1fr)]"
            >
                <div
                    class="border-border aspect-square overflow-hidden border-b sm:aspect-4/3 lg:aspect-auto lg:h-144 lg:max-h-[70vh] lg:border-r lg:border-b-0"
                >
                    <ProductImage
                        :image-url="product.image_url"
                        :product-name="product.name"
                        contain
                    />
                </div>

                <div class="flex flex-col p-6 sm:p-9">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {{ product.category.name }}
                        </Badge>
                        <Badge v-if="product.is_featured">Featured</Badge>
                    </div>

                    <p
                        v-if="product.brand"
                        class="text-primary mt-7 text-xs font-bold tracking-[0.18em] uppercase"
                    >
                        {{ product.brand }}
                    </p>
                    <h1
                        class="mt-2 text-2xl leading-tight font-bold tracking-tight break-words sm:text-3xl"
                    >
                        {{ product.name }}
                    </h1>

                    <ProductPrice
                        :price="product.price"
                        :discount-price="product.discount_price"
                        class="mt-6"
                    />

                    <div class="border-border mt-7 border-y py-6">
                        <p
                            class="text-muted-foreground mb-3 text-xs font-bold tracking-wide uppercase"
                        >
                            Current availability
                        </p>
                        <StockAvailability :inventory="product.inventory" />
                        <p
                            v-if="
                                ['out_of_stock', 'unavailable'].includes(
                                    product.inventory.status,
                                )
                            "
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            This product remains in the catalog but is not
                            currently in stock.
                        </p>
                    </div>

                    <section
                        v-if="hasAvailableStock && canUseCustomerCart"
                        class="mt-7"
                        aria-labelledby="add-to-cart-heading"
                    >
                        <h2 id="add-to-cart-heading" class="font-semibold">
                            Add this product to your cart
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm leading-6">
                            Choose a quantity. Current stock is checked again
                            when you add it.
                        </p>
                        <AddToCartForm
                            class="mt-4"
                            :product-id="product.id"
                            :available-quantity="product.inventory.quantity"
                        />
                    </section>

                    <section
                        v-else-if="hasAvailableStock && !isAuthenticated"
                        class="border-border bg-secondary/40 mt-7 border p-4"
                        aria-labelledby="customer-cart-heading"
                    >
                        <h2 id="customer-cart-heading" class="font-semibold">
                            Ready to add this product?
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm leading-6">
                            Log in with a customer account to use the cart.
                        </p>
                        <Button as-child class="mt-4">
                            <Link :href="login()">
                                <LogIn aria-hidden="true" />
                                Log in to add to cart
                            </Link>
                        </Button>
                    </section>
                </div>

                <div
                    class="border-border grid gap-8 border-t p-6 sm:p-9 lg:col-span-2 lg:grid-cols-[minmax(0,1.3fr)_minmax(22rem,1fr)]"
                >
                    <section aria-labelledby="description-heading">
                        <h2 id="description-heading" class="font-semibold">
                            Product description
                        </h2>
                        <p
                            v-if="product.description"
                            class="text-muted-foreground mt-3 text-sm leading-7 whitespace-pre-line"
                        >
                            {{ product.description }}
                        </p>
                        <div
                            v-else
                            class="border-border bg-muted/30 mt-3 flex gap-3 border border-dashed p-4"
                        >
                            <PackageOpen
                                class="text-muted-foreground mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <p class="text-muted-foreground text-sm leading-6">
                                A product description is not currently
                                available.
                            </p>
                        </div>
                    </section>

                    <section aria-labelledby="tags-heading">
                        <h2
                            id="tags-heading"
                            class="flex items-center gap-2 font-semibold"
                        >
                            <Tag
                                class="text-primary size-4"
                                aria-hidden="true"
                            />
                            Product tags
                        </h2>
                        <div
                            v-if="product.tags.length"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <Badge
                                v-for="tag in product.tags"
                                :key="tag.id"
                                variant="outline"
                            >
                                {{ tag.name }}
                            </Badge>
                        </div>
                        <p
                            v-else
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            No tags are currently assigned to this product.
                        </p>
                    </section>
                </div>
            </article>

            <div class="mt-12">
                <RecommendationSection
                    :recommendations="recommendations"
                    placement="product"
                    :title="
                        is_personalized
                            ? 'You may also like'
                            : has_featured_fallback
                              ? 'Popular and featured products'
                              : 'Popular products'
                    "
                    :description="
                        is_personalized
                            ? isAuthenticated
                                ? 'Suggestions based on your recent browsing, cart, and completed purchases.'
                                : 'Suggestions based on your recent browsing in this browser.'
                            : has_featured_fallback
                              ? 'Popular products and featured picks with current Sagay stock.'
                              : 'Products that appear often in completed Battlefront orders.'
                    "
                />
            </div>
        </main>
    </div>
</template>
