<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import WishlistButton from '@/components/catalog/WishlistButton.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import { Badge } from '@/components/ui/badge';
import { useProductPrefetch } from '@/composables/useProductPrefetch';
import { rememberCatalogVisit } from '@/lib/catalogReturn';
import { show as productShow } from '@/routes/products';

defineProps({ product: { type: Object, required: true } });
const { prefetchProduct, cancelProductPrefetch } = useProductPrefetch();
const page = usePage();
</script>

<template>
    <div
        class="surface-card border-border bg-card focus-visible:ring-ring group hover:border-primary/60 relative flex min-h-full min-w-0 flex-col overflow-hidden border transition-colors"
    >
        <WishlistButton
            class="absolute top-3 right-3 z-10"
            :product-id="product.id"
            :product-name="product.name"
            :is-wishlisted="product.is_wishlisted"
            :can-wishlist="page.props.auth?.can?.useCustomerCart === true"
            compact
        />
        <Link
            :href="productShow(product.id)"
            @pointerenter="prefetchProduct($event, productShow(product.id))"
            @pointerleave="cancelProductPrefetch"
            @pointercancel="cancelProductPrefetch"
            @pointerdown="cancelProductPrefetch"
            @click.capture="
                cancelProductPrefetch();
                rememberCatalogVisit($event, product.id);
            "
            class="focus-visible:ring-ring flex min-h-full min-w-0 flex-col focus-visible:ring-2 focus-visible:outline-none"
        >
            <div class="aspect-square overflow-hidden sm:aspect-3/2">
                <ProductImage
                    :image-url="product.image_url"
                    :product-name="product.name"
                    contain
                    desktop-cover
                    class="transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none max-lg:[&_img]:size-[98%]"
                />
            </div>

            <article class="product-card-content">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="secondary"
                        class="max-w-full text-left leading-4 wrap-anywhere whitespace-normal"
                    >
                        {{ product.category.name }}
                    </Badge>
                    <Badge v-if="product.is_featured"> Featured </Badge>
                </div>

                <div>
                    <p
                        v-if="product.brand"
                        class="text-muted-foreground text-xs font-semibold tracking-wide wrap-anywhere uppercase"
                    >
                        {{ product.brand }}
                    </p>
                    <h3
                        class="mt-1 text-sm leading-5 font-bold tracking-tight wrap-anywhere sm:text-lg"
                    >
                        {{ product.name }}
                    </h3>
                    <p
                        v-if="product.description"
                        class="text-muted-foreground mt-2 hidden text-sm leading-6 sm:line-clamp-2"
                    >
                        {{ product.description }}
                    </p>
                </div>

                <div class="mt-auto flex flex-col gap-3">
                    <p
                        v-if="
                            ['fragile', 'bulky'].includes(
                                product.shipping_profile,
                            )
                        "
                        class="text-muted-foreground text-xs first-letter:uppercase"
                    >
                        {{ product.shipping_profile }} handling
                    </p>
                    <ProductPrice
                        :price="product.price"
                        :discount-price="product.discount_price"
                        compact
                    />
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <StockAvailability :inventory="product.inventory" />
                        <ArrowRight
                            class="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-1 motion-reduce:transition-none sm:block"
                            aria-hidden="true"
                        />
                    </div>
                </div>
            </article>
        </Link>
    </div>
</template>
