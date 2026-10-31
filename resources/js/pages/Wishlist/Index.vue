<script setup>
import { Head, Form, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Heart,
    PackageOpen,
    PackageX,
    ShoppingCart,
    Trash2,
} from '@lucide/vue';
import WishlistController from '@/actions/App/Http/Controllers/WishlistController';
import CartItemController from '@/actions/App/Http/Controllers/CartItemController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import { useWishlistInteractions } from '@/composables/useWishlistInteractions';
import { Button } from '@/components/ui/button';
import { index as productIndex, show as productShow } from '@/routes/products';
import { index as wishlistIndex } from '@/routes/wishlist';
import { index as cartIndex } from '@/routes/cart';

defineProps({
    wishlist: { type: Object, required: true },
});

const interactions = useWishlistInteractions();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Wishlist',
                href: wishlistIndex(),
            },
        ],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="My wishlist" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader
                title="My wishlist"
                description="Keep the products you are considering in one place."
                heading-id="wishlist-heading"
            >
                <template #metadata>
                    <Heart class="text-primary-ink size-4" aria-hidden="true" />
                    <span role="status">
                        {{ wishlist.count }}
                        {{
                            wishlist.count === 1
                                ? 'saved product'
                                : 'saved products'
                        }}
                    </span>
                </template>
                <template #actions>
                    <Button variant="outline" as-child>
                        <Link :href="productIndex()">
                            Browse products
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <section
                v-if="wishlist.items.data.length"
                aria-labelledby="wishlist-items-heading"
            >
                <h2 id="wishlist-items-heading" class="sr-only">
                    Saved products
                </h2>
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="item in wishlist.items.data"
                        :key="item.id"
                        class="surface-card border-border bg-card flex min-w-0 flex-col overflow-hidden border"
                    >
                        <Link
                            v-if="item.product"
                            :href="productShow(item.product_id)"
                            class="group focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <div
                                class="bg-muted aspect-[4/3] overflow-hidden p-4"
                            >
                                <ProductImage
                                    :image-url="item.product.image_url"
                                    :product-name="item.product.name"
                                    class="size-full transition-transform duration-300 group-hover:scale-[1.03] motion-reduce:transition-none"
                                />
                            </div>
                            <div class="min-w-0 space-y-2 p-4 pb-0">
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                >
                                    {{ item.product.category.name }}
                                </p>
                                <h3
                                    class="line-clamp-2 min-h-12 leading-6 font-semibold wrap-anywhere"
                                >
                                    {{ item.product.name }}
                                </h3>
                                <ProductPrice
                                    :price="item.product.price"
                                    :discount-price="
                                        item.product.discount_price
                                    "
                                />
                            </div>
                        </Link>

                        <div
                            v-else
                            class="flex min-h-48 items-center gap-4 border-b p-5"
                        >
                            <PackageX
                                class="text-muted-foreground size-7 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <h3 class="font-semibold">
                                    Unavailable product
                                </h3>
                                <p
                                    class="text-muted-foreground mt-1 text-sm leading-5"
                                >
                                    This product is no longer available, but you
                                    can remove it from your wishlist.
                                </p>
                            </div>
                        </div>

                        <div class="mt-auto space-y-4 p-4">
                            <StockAvailability
                                v-if="item.product"
                                :inventory="item.product.inventory"
                            />

                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-if="item.is_in_cart"
                                    as-child
                                    size="sm"
                                >
                                    <Link :href="cartIndex()">
                                        <ShoppingCart aria-hidden="true" />
                                        View cart
                                    </Link>
                                </Button>
                                <Form
                                    v-else-if="item.can_add_to_cart"
                                    v-bind="CartItemController.store.form()"
                                    v-slot="{ processing }"
                                    :options="
                                        interactions.formOptions(
                                            item.product_id,
                                            {
                                                only: [
                                                    'wishlist',
                                                    'wishlistCount',
                                                    'cartQuantity',
                                                ],
                                            },
                                        )
                                    "
                                    :show-progress="false"
                                >
                                    <input
                                        type="hidden"
                                        name="product_id"
                                        :value="item.product_id"
                                    />
                                    <input
                                        type="hidden"
                                        name="quantity"
                                        value="1"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            interactions.isBusy(item.product_id)
                                        "
                                    >
                                        <ShoppingCart aria-hidden="true" />
                                        {{
                                            processing
                                                ? 'Adding…'
                                                : 'Add to cart'
                                        }}
                                    </Button>
                                </Form>

                                <Form
                                    v-bind="
                                        WishlistController.destroy.form(
                                            item.product_id,
                                        )
                                    "
                                    v-slot="{ processing }"
                                    :options="
                                        interactions.formOptions(
                                            item.product_id,
                                            {
                                                only: [
                                                    'wishlist',
                                                    'wishlistCount',
                                                ],
                                                isSaved: false,
                                            },
                                        )
                                    "
                                    :show-progress="false"
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        :disabled="
                                            interactions.isBusy(item.product_id)
                                        "
                                        :aria-label="`Remove ${item.product?.name ?? 'unavailable product'} from wishlist`"
                                    >
                                        <Trash2 aria-hidden="true" />
                                        {{
                                            processing ? 'Removing…' : 'Remove'
                                        }}
                                    </Button>
                                </Form>
                                <p
                                    v-if="interactions.error(item.product_id)"
                                    role="alert"
                                    class="text-destructive basis-full text-xs"
                                >
                                    {{ interactions.error(item.product_id) }}
                                </p>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                v-else
                aria-labelledby="empty-wishlist-heading"
                class="surface-panel border-border bg-card flex min-h-80 items-center justify-center border border-dashed p-8 text-center"
            >
                <div class="max-w-md">
                    <span
                        class="border-border bg-secondary text-muted-foreground mx-auto flex size-14 items-center justify-center border"
                    >
                        <PackageOpen class="size-6" aria-hidden="true" />
                    </span>
                    <h2
                        id="empty-wishlist-heading"
                        class="mt-5 text-xl font-bold"
                    >
                        Your wishlist is empty
                    </h2>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Save products from the catalog to find them here later.
                    </p>
                    <Button as-child class="mt-6">
                        <Link :href="productIndex()">
                            Browse products
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </section>

            <CatalogPagination
                :current-page="wishlist.items.current_page"
                :last-page="wishlist.items.last_page"
                :route="({ query }) => wishlistIndex({ query })"
                label="Wishlist pages"
            />
        </main>
    </div>
</template>
