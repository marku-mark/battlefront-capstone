<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, PackageOpen, Tag } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import AddToCartForm from '@/components/cart/AddToCartForm.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import WishlistButton from '@/components/catalog/WishlistButton.vue';
import ShippingHandling from '@/components/catalog/ShippingHandling.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { consumeCatalogVisit } from '@/lib/catalogReturn';
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
    () =>
        Number.isInteger(props.product.inventory?.quantity) &&
        props.product.inventory.quantity > 0,
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

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
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
                class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(22rem,1fr)]"
            >
                <div
                    class="surface-card border-border bg-card aspect-square overflow-hidden border sm:aspect-4/3 lg:relative lg:aspect-auto lg:min-h-[min(36rem,70vh)]"
                >
                    <ProductImage
                        :image-url="product.image_url"
                        :product-name="product.name"
                        contain
                        desktop-cover
                        class="lg:absolute lg:inset-0"
                    />
                </div>

                <div
                    class="surface-card border-border bg-card flex min-w-0 flex-col border p-4 sm:p-6"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {{ product.category.name }}
                        </Badge>
                        <Badge v-if="product.is_featured">Featured</Badge>
                    </div>

                    <p
                        v-if="product.brand"
                        class="text-primary-ink mt-4 text-xs font-bold tracking-[0.18em] uppercase"
                    >
                        {{ product.brand }}
                    </p>
                    <h1
                        class="mt-2 text-2xl leading-tight font-bold tracking-tight break-words sm:text-3xl"
                    >
                        {{ product.name }}
                    </h1>
                    <WishlistButton
                        class="mt-4 self-start"
                        :product-id="product.id"
                        :product-name="product.name"
                        :is-wishlisted="product.is_wishlisted"
                        :can-wishlist="canUseCustomerCart"
                    />

                    <ProductPrice
                        :price="product.price"
                        :discount-price="product.discount_price"
                        class="mt-6"
                    />

                    <div class="mt-4">
                        <StockAvailability :inventory="product.inventory" />
                        <p
                            v-if="
                                ['out_of_stock', 'unavailable'].includes(
                                    product.inventory?.status,
                                )
                            "
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            This product remains in the catalog but is not
                            currently in stock.
                        </p>
                    </div>

                    <ShippingHandling
                        v-if="product.shipping_handling"
                        :handling="product.shipping_handling"
                        :show-preparation="false"
                        class="mt-6"
                    />

                    <section
                        v-if="hasAvailableStock && canUseCustomerCart"
                        class="mt-4"
                        aria-labelledby="add-to-cart-heading"
                    >
                        <h2 id="add-to-cart-heading" class="font-semibold">
                            Choose quantity
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm leading-6">
                            Stock is checked when you add it.
                        </p>
                        <AddToCartForm
                            class="mt-4"
                            :product-id="product.id"
                            :available-quantity="product.inventory.quantity"
                        />
                    </section>

                    <section
                        v-else-if="hasAvailableStock && !isAuthenticated"
                        class="mt-4"
                        aria-labelledby="customer-cart-heading"
                    >
                        <h2 id="customer-cart-heading" class="font-semibold">
                            Choose quantity
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm leading-6">
                            Choose a quantity, then log in or register to add it
                            to your cart.
                        </p>
                        <AddToCartForm
                            class="mt-4"
                            :product-id="product.id"
                            :available-quantity="product.inventory.quantity"
                            guest
                        />
                    </section>
                </div>

                <div
                    class="grid gap-6 lg:col-span-2 lg:grid-cols-[minmax(0,1.3fr)_minmax(22rem,1fr)]"
                >
                    <section aria-labelledby="description-heading">
                        <h2 id="description-heading" class="font-semibold">
                            Description
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
                                class="text-primary-ink size-4"
                                aria-hidden="true"
                            />
                            Tags
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
