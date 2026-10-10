<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, PackageSearch, Pencil } from '@lucide/vue';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import PermanentDeletionDialog from '@/components/PermanentDeletionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps({
    product: { type: Object, required: true },
});

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

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: ProductController.index() },
            { title: 'Product detail', href: ProductController.index() },
        ],
    },
});
</script>

<template>
    <Head :title="product.name" />

    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-6 lg:p-10"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-3">
                <Link
                    :href="ProductController.index()"
                    class="text-muted-foreground hover:text-foreground inline-flex items-center gap-2 text-sm"
                >
                    <ArrowLeft class="size-4" />
                    Back to products
                </Link>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Sagay City catalog · {{ product.product_code }}
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        {{ product.name }}
                    </h1>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child>
                    <Link :href="ProductController.edit(product.id)">
                        <Pencil />
                        Edit product
                    </Link>
                </Button>
                <PermanentDeletionDialog
                    :name="product.name"
                    :form="ProductController.destroy.form(product.id)"
                    :error-bag="`deleteProduct${product.id}`"
                    :imported="product.is_catalog_imported"
                />
            </div>
        </div>

        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start"
        >
            <section
                class="border-border bg-card border p-4 sm:p-6"
                aria-label="Product image"
            >
                <div
                    class="border-border bg-muted/40 relative flex aspect-square items-center justify-center overflow-hidden border"
                >
                    <PackageSearch
                        class="text-muted-foreground size-16"
                        aria-hidden="true"
                    />
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        :alt="`${product.name} product image`"
                        class="absolute inset-0 size-full object-contain"
                        @error="hideBrokenImage"
                    />
                </div>
            </section>

            <div class="flex flex-col gap-6">
                <section
                    class="border-border bg-card border p-6"
                    aria-labelledby="product-details-heading"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2
                            id="product-details-heading"
                            class="text-lg font-semibold"
                        >
                            Product details
                        </h2>
                        <Badge
                            :variant="
                                product.is_active ? 'secondary' : 'outline'
                            "
                        >
                            {{ product.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Product code</dt>
                            <dd class="mt-1 font-medium break-all">
                                {{ product.product_code }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Category</dt>
                            <dd class="mt-1 font-medium">
                                {{ product.category.name }}
                                <span
                                    v-if="!product.category.is_active"
                                    class="text-muted-foreground"
                                    >(Inactive)</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Brand</dt>
                            <dd class="mt-1 font-medium">
                                {{ product.brand || 'Not specified' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Regular price</dt>
                            <dd class="mt-1 font-medium tabular-nums">
                                {{ formatPrice(product.price) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Discount price
                            </dt>
                            <dd class="mt-1 font-medium tabular-nums">
                                {{
                                    product.discount_price === null
                                        ? 'None'
                                        : formatPrice(product.discount_price)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Tags</dt>
                            <dd
                                v-if="product.tags.length"
                                class="mt-2 flex flex-wrap gap-1.5"
                            >
                                <Badge
                                    v-for="tag in product.tags"
                                    :key="tag.id"
                                    variant="outline"
                                >
                                    {{ tag.name }}
                                </Badge>
                            </dd>
                            <dd v-else class="mt-1 font-medium">None</dd>
                        </div>
                    </dl>
                    <div
                        v-if="product.description"
                        class="border-border mt-5 border-t pt-5 text-sm"
                    >
                        <h3 class="text-muted-foreground">Description</h3>
                        <p class="mt-2 whitespace-pre-line">
                            {{ product.description }}
                        </p>
                    </div>
                </section>

                <section
                    class="border-border bg-card border p-6"
                    aria-labelledby="product-stock-heading"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2
                            id="product-stock-heading"
                            class="text-lg font-semibold"
                        >
                            Inventory
                        </h2>
                        <Badge
                            v-if="product.stock_status === 'out_of_stock'"
                            variant="outline"
                            class="border-destructive/50 bg-destructive/10 text-destructive"
                            >Out of stock</Badge
                        >
                        <Badge
                            v-else-if="product.stock_status === 'low_stock'"
                            variant="outline"
                            class="border-primary/40 text-primary"
                            >Low stock</Badge
                        >
                        <Badge
                            v-else-if="product.stock_status === 'in_stock'"
                            variant="secondary"
                            >In stock</Badge
                        >
                        <Badge v-else variant="outline">Not initialized</Badge>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                Stock quantity
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{ product.inventory?.quantity ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Reorder level</dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{ product.inventory?.reorder_level ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </main>
</template>
