<script setup>
import { Form, useForm } from '@inertiajs/vue3';
import { LogIn, ShoppingCart } from '@lucide/vue';
import { computed, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CartItemController';
import { store as storePendingIntent } from '@/actions/App/Http/Controllers/PendingCartIntentController';
import { store as buyNow } from '@/routes/buy-now';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps({
    productId: { type: Number, required: true },
    availableQuantity: { type: Number, default: null },
    guest: { type: Boolean, default: false },
});

const enteredQuantity = ref(1);
const purchase = useForm({ product_id: props.productId, quantity: 1 });
function purchaseNow() {
    if (purchase.processing || !hasAvailableStock.value || exceedsStock.value || !Number.isInteger(enteredQuantity.value) || enteredQuantity.value < 1) return;
    purchase.quantity = enteredQuantity.value;
    purchase.post(buyNow.url(), { preserveScroll: true });
}
const hasAvailableStock = computed(
    () =>
        Number.isInteger(props.availableQuantity) &&
        props.availableQuantity > 0,
);
const exceedsStock = computed(
    () =>
        hasAvailableStock.value &&
        enteredQuantity.value > props.availableQuantity,
);
const isAtMaximum = computed(
    () =>
        hasAvailableStock.value &&
        enteredQuantity.value === props.availableQuantity,
);

function trackEnteredQuantity(event) {
    enteredQuantity.value = event.currentTarget.valueAsNumber;
}
</script>

<template>
    <Form
        v-bind="guest ? storePendingIntent.form() : store.form()"
        :error-bag="`addCartItem${productId}`"
        :options="{ preserveScroll: true }"
        class="grid gap-4 sm:grid-cols-[minmax(8rem,0.45fr)_minmax(0,1fr)] sm:items-end"
        :class="{ 'border-border bg-secondary/40 border p-4': !guest }"
        v-slot="{ errors, processing }"
    >
        <input type="hidden" name="product_id" :value="productId" />

        <div class="grid gap-2">
            <Label :for="`add-cart-quantity-${productId}`">Quantity</Label>
            <p
                :id="`add-cart-stock-${productId}`"
                class="text-muted-foreground text-xs"
                aria-live="polite"
            >
                {{
                    hasAvailableStock
                        ? `${availableQuantity} available`
                        : availableQuantity === 0
                          ? 'Out of stock'
                          : 'Stock unavailable'
                }}
            </p>
            <Input
                :id="`add-cart-quantity-${productId}`"
                name="quantity"
                type="number"
                min="1"
                :max="hasAvailableStock ? availableQuantity : 0"
                :disabled="!hasAvailableStock || processing || purchase.processing"
                step="1"
                inputmode="numeric"
                :default-value="1"
                :aria-invalid="Boolean(errors.quantity)"
                :aria-describedby="`add-cart-stock-${productId}${isAtMaximum || exceedsStock ? ` add-cart-quantity-limit-${productId}` : ''}`"
                required
                @input="trackEnteredQuantity"
            />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
        <Button class="w-full sm:w-auto" type="submit" :disabled="processing || purchase.processing || !hasAvailableStock">
            <Spinner v-if="processing" />
            <LogIn v-else-if="guest" aria-hidden="true" />
            <ShoppingCart v-else aria-hidden="true" />
            {{
                guest
                    ? processing
                        ? 'Continuing...'
                        : 'Log in to add to cart'
                    : processing
                      ? 'Adding...'
                      : 'Add to cart'
            }}
        </Button>

        <Button class="w-full sm:w-auto" type="button" variant="outline" :disabled="processing || purchase.processing || !hasAvailableStock || exceedsStock || !Number.isInteger(enteredQuantity) || enteredQuantity < 1" @click="purchaseNow">
            <Spinner v-if="purchase.processing" />
            {{ purchase.processing ? 'Continuing...' : 'Buy Now' }}
        </Button>
        </div>
        <div class="sm:col-span-2" aria-live="polite"><InputError :message="purchase.errors.product_id || purchase.errors.quantity || purchase.errors.cart" /></div>

        <div class="min-h-8 sm:col-span-2" aria-live="polite">
            <p
                v-if="isAtMaximum || exceedsStock"
                :id="`add-cart-quantity-limit-${productId}`"
                class="text-muted-foreground text-xs"
            >
                {{
                    exceedsStock
                        ? `Only ${availableQuantity} available. Reduce the quantity to continue.`
                        : 'Maximum available quantity reached.'
                }}
            </p>
            <p class="text-muted-foreground text-xs">
                Adding to your cart does not reserve stock. Availability is
                checked again at checkout.
            </p>
        </div>

        <InputError
            v-if="errors.product_id"
            class="sm:col-span-2"
            :message="errors.product_id"
        />
        <InputError
            v-if="errors.quantity"
            class="sm:col-span-2"
            :message="errors.quantity"
        />
    </Form>
</template>
