<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { CircleHelp, CircleX, Save, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/CartItemController';
import InputError from '@/components/InputError.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/currency';
import { show as productShow } from '@/routes/products';

const props = defineProps({
    item: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});

const emit = defineEmits(['update:selected']);

const removeDialogOpen = ref(false);
const enteredQuantity = ref(props.item.quantity);
const isAvailable = computed(
    () => props.item.availability.status === 'available',
);
const availableQuantity = computed(() => {
    const quantity = props.item.availability.available_quantity;
    return Number.isInteger(quantity) && quantity >= 0 ? quantity : null;
});
const exceedsStock = computed(
    () => canUpdate.value && enteredQuantity.value > availableQuantity.value,
);
const isAtMaximum = computed(
    () => canUpdate.value && enteredQuantity.value === availableQuantity.value,
);
const canUpdate = computed(
    () =>
        !props.item.pc_build_group &&
        availableQuantity.value > 0 &&
        ['available', 'insufficient_stock'].includes(
            props.item.availability.status,
        ),
);
const productIsBrowsable = computed(
    () => props.item.availability.status !== 'product_ineligible',
);

function trackEnteredQuantity(event) {
    enteredQuantity.value = event.currentTarget.valueAsNumber;
}

const availabilityCopy = computed(() => {
    const status =
        isAvailable.value && !(availableQuantity.value > 0)
            ? 'inventory_unavailable'
            : props.item.availability.status;
    return {
        product_ineligible: {
            title: 'Product unavailable',
            description:
                'This product is no longer eligible for customer carts. Remove it before continuing.',
            icon: TriangleAlert,
        },
        inventory_unavailable: {
            title: 'Stock unavailable',
            description:
                'Current stock information is unavailable. Remove this item or check again later.',
            icon: CircleHelp,
        },
        out_of_stock: {
            title: 'Out of stock',
            description:
                'This product currently has no stock. Remove it or check again later.',
            icon: CircleX,
        },
        insufficient_stock: {
            title: 'Quantity exceeds stock',
            description:
                availableQuantity.value === null
                    ? 'Current stock information is unavailable. Check again before continuing.'
                    : `Your cart has ${props.item.quantity}, but only ${availableQuantity.value} are currently available. Reduce the quantity to continue.`,
            icon: TriangleAlert,
        },
    }[status];
});
</script>

<template>
    <article class="grid gap-5 p-5 sm:grid-cols-[9rem_minmax(0,1fr)] sm:p-6">
        <div class="flex items-center gap-3 sm:col-span-2">
            <Checkbox
                :id="`select-cart-item-${item.id}`"
                :model-value="selected"
                @update:model-value="emit('update:selected', $event === true)"
            />
            <Label
                :for="`select-cart-item-${item.id}`"
                class="min-h-11 md:min-h-0"
            >
                Select {{ item.product.name }} for checkout
            </Label>
        </div>
        <div class="border-border aspect-square overflow-hidden border">
            <ProductImage
                :image-url="item.product.image_url"
                :product-name="item.product.name"
            />
        </div>

        <div class="min-w-0">
            <Badge v-if="item.pc_build_group" variant="outline" class="mb-2">
                PC build · {{ item.pc_build_group.name }}
            </Badge>
            <div
                class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="min-w-0 lg:flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {{ item.product.category }}
                        </Badge>
                        <Badge
                            v-if="isAvailable && availableQuantity > 0"
                            variant="outline"
                        >
                            In stock<template v-if="availableQuantity > 0">
                                · {{ availableQuantity }} available</template
                            >
                        </Badge>
                    </div>
                    <p
                        v-if="
                            !isAvailable &&
                            item.availability.status !== 'product_ineligible' &&
                            availableQuantity !== null
                        "
                        class="text-muted-foreground mt-2 text-xs"
                        aria-live="polite"
                    >
                        {{ availableQuantity }} available
                    </p>
                    <p
                        v-if="item.product.brand"
                        class="text-muted-foreground mt-3 text-xs font-semibold tracking-wide uppercase"
                    >
                        {{ item.product.brand }}
                    </p>
                    <Button
                        v-if="productIsBrowsable"
                        as-child
                        variant="link"
                        class="line-clamp-2 h-auto max-w-full justify-start p-0 text-left text-lg font-bold wrap-anywhere whitespace-normal"
                    >
                        <Link :href="productShow(item.product.id)">
                            {{ item.product.name }}
                        </Link>
                    </Button>
                    <h2
                        v-else
                        class="mt-1 line-clamp-2 text-lg font-bold wrap-anywhere"
                    >
                        {{ item.product.name }}
                    </h2>
                    <ProductPrice
                        :price="item.product.price"
                        :discount-price="item.product.discount_price"
                        class="mt-3"
                    />
                </div>

                <div class="shrink-0 whitespace-nowrap lg:text-right">
                    <p
                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                    >
                        Line total
                    </p>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ formatCurrency(item.line_total) }}
                    </p>
                </div>
            </div>

            <Alert v-if="availabilityCopy" variant="destructive" class="mt-5">
                <component
                    :is="availabilityCopy.icon"
                    class="size-4"
                    aria-hidden="true"
                />
                <AlertTitle>{{ availabilityCopy.title }}</AlertTitle>
                <AlertDescription>
                    {{ availabilityCopy.description }}
                </AlertDescription>
            </Alert>

            <div
                class="border-border mt-5 flex flex-col gap-4 border-t pt-5 lg:flex-row lg:items-start lg:justify-between"
            >
                <Form
                    v-bind="update.form(item.id)"
                    :error-bag="`updateCartItem${item.id}`"
                    :options="{ preserveScroll: true }"
                    class="grid gap-3 lg:grid-cols-[8rem_auto] lg:items-start"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label :for="`cart-quantity-${item.id}`">
                            Quantity
                        </Label>
                        <Input
                            :id="`cart-quantity-${item.id}`"
                            name="quantity"
                            type="number"
                            min="1"
                            :max="availableQuantity ?? 0"
                            step="1"
                            inputmode="numeric"
                            :default-value="item.quantity"
                            :disabled="!canUpdate || processing"
                            :aria-invalid="Boolean(errors.quantity)"
                            :aria-describedby="
                                isAtMaximum || exceedsStock
                                    ? `cart-quantity-limit-${item.id}`
                                    : undefined
                            "
                            required
                            @input="trackEnteredQuantity"
                        />
                        <p
                            v-if="isAtMaximum || exceedsStock"
                            :id="`cart-quantity-limit-${item.id}`"
                            class="text-muted-foreground text-xs"
                            aria-live="polite"
                        >
                            {{
                                exceedsStock
                                    ? `Only ${availableQuantity} available. Reduce the quantity to continue.`
                                    : 'Maximum available quantity reached.'
                            }}
                        </p>
                        <InputError :message="errors.quantity" />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="lg:mt-7"
                        :disabled="!canUpdate || processing"
                    >
                        <Spinner v-if="processing" />
                        <Save v-else aria-hidden="true" />
                        {{ processing ? 'Updating...' : 'Update quantity' }}
                    </Button>
                </Form>

                <Dialog v-model:open="removeDialogOpen">
                    <DialogTrigger as-child>
                        <Button variant="ghost" class="lg:mt-7">
                            <Trash2 aria-hidden="true" />
                            Remove
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="destroy.form(item.id)"
                            :options="{ preserveScroll: true }"
                            @success="removeDialogOpen = false"
                            v-slot="{ processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>
                                    Remove {{ item.product.name }}?
                                </DialogTitle>
                                <DialogDescription>
                                    <template v-if="item.pc_build_group">
                                        This removes the complete
                                        {{ item.pc_build_group.name }}
                                        configuration from your cart.
                                    </template>
                                    <template v-else>
                                        This removes the product from your cart.
                                        You can add it again later if it remains
                                        available.
                                    </template>
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    <Trash2 v-else aria-hidden="true" />
                                    {{ processing ? 'Removing...' : 'Remove' }}
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    </article>
</template>
