<script setup>
import { formatCalendarDate } from '@/lib/dateTime';
import FileInput from '@/components/FileInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    Clock3,
    CreditCard,
    MapPin,
    Phone,
    ShieldCheck,
    Store,
    Truck,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { show as deliveryQuoteShow } from '@/routes/checkout/delivery-quote';
import { store as storeOrder } from '@/actions/App/Http/Controllers/OrderController';
import DeliveryAddressFields from '@/components/DeliveryAddressFields.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/currency';
import { createUuid } from '@/lib/uuid';
import { index as cartIndex } from '@/routes/cart';

const props = defineProps({
    cart: { type: Object, required: true },
    customer: { type: Object, required: true },
    pickupLocation: { type: Object, required: true },
    fulfillmentMethods: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
    paymongoHostedMethods: { type: Array, required: true },
    deliveryQuotes: { type: Array, required: true },
    deliveryLocations: { type: Array, required: true },
    deliveryAddressPrefill: { type: Object, required: true },
    pickupQuote: { type: Object, required: true },
});

const fulfillmentMethod = ref('pickup');
const paymentMethod = ref('cash');
const hostedPaymentMethod = ref(
    props.paymongoHostedMethods.includes('qrph')
        ? 'qrph'
        : (props.paymongoHostedMethods[0] ?? ''),
);
const checkoutSubmissionKey = createUuid();
let paymentWindow = null;
const paymentProofKey = ref(0);
const deliveryAddress = ref({ ...props.deliveryAddressPrefill });
const deliveryDestination = computed(
    () => deliveryAddress.value.delivery_destination,
);
const liveQuote = ref(null);
const quoteNotice = ref('');
const quoteError = ref('');
const quoteLoading = ref(false);
let quoteVersion = 0;
const selectedQuote = computed(() =>
    fulfillmentMethod.value === 'pickup'
        ? props.pickupQuote
        : /^\d{10}$/.test(deliveryDestination.value)
          ? liveQuote.value?.destination === deliveryDestination.value
              ? liveQuote.value
              : null
          : props.deliveryQuotes.find(
                (quote) => quote.destination === deliveryDestination.value,
            ),
);
function formatDeliveryDate(value) {
    return formatCalendarDate(value);
}

const availablePaymentMethods = computed(() =>
    props.paymentMethods.filter((method) => {
        if (!method.available_for.includes(fulfillmentMethod.value)) {
            return false;
        }

        if (fulfillmentMethod.value !== 'delivery') {
            return true;
        }

        if (selectedQuote.value?.order_placement_allowed === false) {
            return false;
        }

        const quotePaymentMethods = selectedQuote.value?.payment_methods;

        return (
            !quotePaymentMethods ||
            quotePaymentMethods.includes(method.value) ||
            (method.value === 'online' &&
                quotePaymentMethods.some((value) =>
                    ['gcash', 'maya'].includes(value),
                ))
        );
    }),
);
const selectedPaymentMethod = computed(() =>
    props.paymentMethods.find((method) => method.value === paymentMethod.value),
);
const isCod = computed(() => paymentMethod.value === 'cod');
const requiresPaymentProof = computed(
    () => selectedPaymentMethod.value?.requires_proof ?? false,
);
const selectedPaymentAccount = computed(
    () => selectedPaymentMethod.value?.payment_account ?? null,
);

function chooseFulfillment(value) {
    fulfillmentMethod.value = value;

    if (value === 'pickup') {
        deliveryAddress.value = {
            ...deliveryAddress.value,
            delivery_destination: '',
            delivery_barangay_code: '',
            delivery_city_code: '',
            delivery_region_code: '',
            delivery_province_code: '',
        };
    }

    if (
        !availablePaymentMethods.value.some(
            (method) => method.value === paymentMethod.value,
        )
    ) {
        paymentMethod.value = availablePaymentMethods.value[0]?.value ?? '';
    }
}

function choosePayment(value) {
    paymentMethod.value = value;
    paymentProofKey.value += 1;
}

function preparePaymentWindow() {
    if (paymentMethod.value !== 'online') {
        return;
    }

    paymentWindow = window.open('about:blank', '_blank');
    if (paymentWindow) {
        paymentWindow.opener = null;
        paymentWindow.document.title = 'Opening secure payment';
        paymentWindow.document.body.innerHTML =
            '<p style="font-family: sans-serif; padding: 2rem">Preparing your secure payment…</p>';
    }
}

function finishPaymentWindow(page) {
    const checkoutUrl = page.props.order?.payment?.checkout_url;
    if (paymentWindow && checkoutUrl) {
        paymentWindow.location.replace(checkoutUrl);
    } else if (paymentWindow) {
        paymentWindow.close();
    }
    paymentWindow = null;
}

async function refreshQuote() {
    const destination = deliveryDestination.value;
    const fulfillment = fulfillmentMethod.value;
    const version = ++quoteVersion;
    liveQuote.value = null;
    quoteNotice.value = '';
    quoteError.value = '';
    quoteLoading.value = false;
    if (fulfillment !== 'delivery' || !/^\d{10}$/.test(destination)) return;
    quoteLoading.value = true;
    try {
        const response = await fetch(
            deliveryQuoteShow.url({
                query: {
                    delivery_city_code: destination,
                    cart_item_ids: props.cart.items.map((item) => item.id),
                },
            }),
            { headers: { Accept: 'application/json' } },
        );
        if (!response.ok)
            throw new Error(
                'Unable to refresh the delivery quote. Please retry.',
            );
        const { data } = await response.json();
        if (version !== quoteVersion) return;
        liveQuote.value = data.quote;
        quoteNotice.value = data.notice ?? '';
    } catch (error) {
        if (version === quoteVersion) quoteError.value = error.message;
    } finally {
        if (version === quoteVersion) quoteLoading.value = false;
    }
}
watch([deliveryDestination, fulfillmentMethod], refreshQuote);
watch(availablePaymentMethods, (methods) => {
    if (!methods.some((method) => method.value === paymentMethod.value)) {
        choosePayment(methods[0]?.value ?? '');
    }
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cart', href: cartIndex() },
            { title: 'Checkout' },
        ],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Checkout" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader title="Checkout" heading-id="checkout-heading" />

            <Form
                v-bind="storeOrder.form()"
                :options="{
                    preserveScroll: true,
                    onSuccess: finishPaymentWindow,
                    onError: () => paymentWindow?.close(),
                }"
                @submit="preparePaymentWindow"
                class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start"
                v-slot="{ errors, processing, progress }"
            >
                <input
                    v-for="item in cart.items"
                    :key="`selected-${item.id}`"
                    type="hidden"
                    name="cart_item_ids[]"
                    :value="item.id"
                />
                <input
                    type="hidden"
                    name="checkout_submission_key"
                    :value="checkoutSubmissionKey"
                />
                <input
                    v-if="paymentMethod === 'online'"
                    type="hidden"
                    name="payment_channel"
                    value="hosted_checkout"
                />
                <input
                    v-if="paymentMethod === 'online'"
                    type="hidden"
                    name="hosted_payment_method"
                    :value="hostedPaymentMethod"
                />
                <div class="grid gap-6">
                    <Alert v-if="errors.cart" variant="destructive">
                        <AlertTitle>Your cart changed</AlertTitle>
                        <AlertDescription>{{ errors.cart }}</AlertDescription>
                    </Alert>
                    <InputError :message="errors.cart_item_ids" />

                    <section
                        class="surface-panel border-border bg-card grid gap-6 border p-6"
                        aria-labelledby="recipient-heading"
                    >
                        <div class="flex items-center gap-3">
                            <ShieldCheck
                                class="text-primary-ink size-5"
                                aria-hidden="true"
                            />
                            <div>
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                                >
                                    Step 1
                                </p>
                                <h2 id="recipient-heading" class="font-bold">
                                    Recipient details
                                </h2>
                            </div>
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="recipient-name"
                                    >Recipient name</Label
                                >
                                <Input
                                    id="recipient-name"
                                    name="recipient_name"
                                    type="text"
                                    maxlength="255"
                                    autocomplete="name"
                                    :default-value="customer.name"
                                    :aria-invalid="
                                        Boolean(errors.recipient_name)
                                    "
                                    required
                                />
                                <InputError :message="errors.recipient_name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="contact-number"
                                    >Contact number</Label
                                >
                                <Input
                                    id="contact-number"
                                    name="contact_number"
                                    type="tel"
                                    maxlength="20"
                                    autocomplete="tel"
                                    placeholder="e.g. 0917 123 4567"
                                    :default-value="
                                        customer.default_delivery_contact_number
                                    "
                                    :aria-invalid="
                                        Boolean(errors.contact_number)
                                    "
                                    required
                                />
                                <InputError :message="errors.contact_number" />
                            </div>
                        </div>
                    </section>

                    <section
                        class="surface-panel border-border bg-card grid gap-6 border p-6"
                        aria-labelledby="fulfillment-heading"
                    >
                        <div class="flex items-center gap-3">
                            <MapPin
                                class="text-primary-ink size-5"
                                aria-hidden="true"
                            />
                            <div>
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                                >
                                    Step 2
                                </p>
                                <h2 id="fulfillment-heading" class="font-bold">
                                    Fulfillment
                                </h2>
                            </div>
                        </div>

                        <div
                            class="grid gap-3 sm:grid-cols-2"
                            role="radiogroup"
                            aria-labelledby="fulfillment-heading"
                        >
                            <label
                                v-for="method in fulfillmentMethods"
                                :key="method.value"
                                class="border-border has-checked:border-primary has-checked:bg-primary/5 focus-within:ring-ring/50 grid cursor-pointer grid-cols-[auto_1fr] gap-3 border p-4 transition-colors focus-within:ring-3"
                            >
                                <input
                                    class="accent-primary mt-1 size-4"
                                    type="radio"
                                    name="fulfillment_method"
                                    :value="method.value"
                                    :checked="
                                        fulfillmentMethod === method.value
                                    "
                                    @change="chooseFulfillment(method.value)"
                                />
                                <span>
                                    <span
                                        class="flex items-center gap-2 font-semibold"
                                    >
                                        <Store
                                            v-if="method.value === 'pickup'"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        <Truck
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ method.label }}
                                    </span>
                                    <span
                                        class="text-muted-foreground mt-1 block text-xs leading-5"
                                    >
                                        {{
                                            method.value === 'pickup'
                                                ? 'Collect your order from Battlefront.'
                                                : 'Send the order to your supplied address.'
                                        }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.fulfillment_method" />

                        <div
                            v-if="fulfillmentMethod === 'pickup'"
                            class="border-border bg-secondary/40 grid gap-4 border p-4"
                        >
                            <div class="flex items-start gap-3">
                                <Store
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <p
                                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                    >
                                        Pickup location
                                    </p>
                                    <p class="mt-1 font-semibold">
                                        {{ pickupLocation.name }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="border-border bg-background grid gap-3 border p-4 text-sm"
                            >
                                <div class="flex items-start gap-3">
                                    <MapPin
                                        class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <p class="leading-6">
                                        {{ pickupLocation.address }}
                                    </p>
                                </div>
                                <div
                                    v-if="
                                        pickupLocation.contact_number ||
                                        pickupLocation.operating_hours
                                    "
                                    class="border-border grid gap-3 border-t pt-3 sm:grid-cols-2"
                                >
                                    <div
                                        v-if="pickupLocation.contact_number"
                                        class="flex items-center gap-3"
                                    >
                                        <Phone
                                            class="text-muted-foreground size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span class="tabular-nums">
                                            {{ pickupLocation.contact_number }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="pickupLocation.operating_hours"
                                        class="flex items-center gap-3"
                                    >
                                        <Clock3
                                            class="text-muted-foreground size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span>
                                            {{ pickupLocation.operating_hours }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="fulfillmentMethod === 'delivery'"
                            class="grid gap-5"
                        >
                            <DeliveryAddressFields
                                nationwide
                                v-model="deliveryAddress"
                                :locations="deliveryLocations"
                                :errors="errors"
                            >
                                <template #details-help>
                                    <p
                                        v-if="customer.default_delivery_address"
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        Saved profile address:
                                        {{ customer.default_delivery_address }}
                                        Confirm the location and enter the
                                        street details above. Changes here apply
                                        only to this order.
                                    </p>
                                </template>
                            </DeliveryAddressFields>
                            <div
                                v-if="quoteLoading && !selectedQuote"
                                role="status"
                            >
                                <span class="sr-only">
                                    Refreshing delivery estimate…
                                </span>
                                <div
                                    data-slot="delivery-quote-skeleton"
                                    aria-hidden="true"
                                    class="border-border bg-secondary/40 grid gap-2 border p-4"
                                >
                                    <Skeleton class="h-5 w-3/4" />
                                    <Skeleton class="h-5 w-2/3" />
                                    <Skeleton class="h-5 w-1/2" />
                                    <Skeleton class="h-5 w-3/4" />
                                    <Skeleton class="h-10 w-full" />
                                    <Skeleton class="h-10 w-full" />
                                </div>
                            </div>
                            <div
                                v-if="
                                    quoteNotice &&
                                    !quoteLoading &&
                                    !selectedQuote
                                "
                                class="border-border bg-secondary/40 grid gap-1 border p-4 text-sm"
                                role="status"
                            >
                                <p class="font-semibold">
                                    Delivery unavailable
                                </p>
                                <p class="text-muted-foreground leading-6">
                                    Battlefront cannot accept a delivery order
                                    for this location at this time.
                                </p>
                                <p
                                    class="text-muted-foreground text-xs leading-5"
                                >
                                    {{ quoteNotice }}
                                </p>
                            </div>
                            <p
                                v-if="quoteError"
                                role="alert"
                                class="text-destructive text-sm"
                            >
                                {{ quoteError }}
                            </p>
                            <button
                                v-if="quoteError && !quoteLoading"
                                type="button"
                                class="justify-self-start text-sm underline"
                                @click="refreshQuote"
                            >
                                Retry quote
                            </button>
                            <div
                                v-if="selectedQuote"
                                class="border-border bg-secondary/40 grid gap-2 border p-4 text-sm"
                                aria-live="polite"
                            >
                                <template
                                    v-if="
                                        selectedQuote.pricing_status ===
                                        'not_configured'
                                    "
                                >
                                    <p class="font-semibold">
                                        No delivery estimate configured ·
                                        {{
                                            selectedQuote.destination_label ??
                                            selectedQuote.destination
                                        }}
                                    </p>
                                    <p class="leading-6">
                                        You can place this order for the product
                                        subtotal. No delivery fee or ETA is
                                        included; Battlefront must confirm the
                                        delivery arrangement and any applicable
                                        charge separately.
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.notice }}
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.assumption_label }}
                                    </p>
                                    <a
                                        class="text-primary underline underline-offset-4"
                                        href="https://ratecalcapi.lbcapps.com/Tools/PH_Rate_Calculator"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Check this route with LBC’s official
                                        rate calculator
                                    </a>
                                </template>
                                <template
                                    v-else-if="
                                        selectedQuote.delivery_arrangement_required
                                    "
                                >
                                    <p class="font-semibold">
                                        Battlefront regional shipping estimate ·
                                        {{
                                            selectedQuote.destination_label ??
                                            selectedQuote.destination
                                        }}
                                    </p>
                                    <p>
                                        Route base
                                        {{
                                            formatCurrency(
                                                selectedQuote.route_base_fee,
                                            )
                                        }}
                                        + weight
                                        {{
                                            formatCurrency(
                                                selectedQuote.weight_surcharge,
                                            )
                                        }}
                                        + handling
                                        {{
                                            formatCurrency(
                                                selectedQuote.handling_surcharge,
                                            )
                                        }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        Billable weight:
                                        {{ selectedQuote.billable_weight_kg }}
                                        kg (actual
                                        {{ selectedQuote.actual_weight_kg }} kg;
                                        volumetric
                                        {{ selectedQuote.volumetric_weight_kg }}
                                        kg).
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        Package measurements:
                                        {{
                                            selectedQuote.measurement_sources
                                                .measured
                                        }}
                                        item units measured and
                                        {{
                                            selectedQuote.measurement_sources
                                                .estimated
                                        }}
                                        estimated.
                                    </p>
                                    <p class="leading-6">
                                        The estimated shipping fee is included
                                        in your total. Battlefront will confirm
                                        the delivery arrangement and ETA
                                        manually.
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.notice }}
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.assumption_label }}
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.estimate_disclosure }}
                                    </p>
                                    <a
                                        class="text-primary underline underline-offset-4"
                                        href="https://www.lbcexpress.com/rates/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Check LBC’s official rate calculator
                                    </a>
                                </template>
                                <template v-else>
                                    <p class="font-semibold">
                                        {{
                                            selectedQuote.carrier.toUpperCase()
                                        }}
                                        delivery estimate ·
                                        {{
                                            selectedQuote.destination_label ??
                                            selectedQuote.destination
                                        }}
                                    </p>
                                    <p>
                                        {{ selectedQuote.packing_expectation }}
                                        ·
                                        {{ selectedQuote.preparation_days }}
                                        preparation
                                        {{
                                            selectedQuote.preparation_days === 1
                                                ? 'day'
                                                : 'days'
                                        }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        Route base
                                        {{
                                            formatCurrency(
                                                selectedQuote.route_base_fee,
                                            )
                                        }}
                                        + weight
                                        {{
                                            formatCurrency(
                                                selectedQuote.weight_surcharge,
                                            )
                                        }}
                                        + handling
                                        {{
                                            formatCurrency(
                                                selectedQuote.handling_surcharge,
                                            )
                                        }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        Billable weight:
                                        {{ selectedQuote.billable_weight_kg }}
                                        kg (actual
                                        {{ selectedQuote.actual_weight_kg }} kg;
                                        volumetric
                                        {{ selectedQuote.volumetric_weight_kg }}
                                        kg).
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        Package measurements:
                                        {{
                                            selectedQuote.measurement_sources
                                                .measured
                                        }}
                                        item units measured and
                                        {{
                                            selectedQuote.measurement_sources
                                                .estimated
                                        }}
                                        estimated.
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.estimate_disclosure }}
                                    </p>
                                    <p class="font-semibold">
                                        Estimated delivery:
                                        {{
                                            formatDeliveryDate(
                                                selectedQuote.estimated_delivery_start,
                                            )
                                        }}<template
                                            v-if="
                                                selectedQuote.estimated_delivery_start !==
                                                selectedQuote.estimated_delivery_end
                                            "
                                            >–{{
                                                formatDeliveryDate(
                                                    selectedQuote.estimated_delivery_end,
                                                )
                                            }}</template
                                        >
                                    </p>
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.notice }}
                                    </p>
                                    <a
                                        class="text-primary underline underline-offset-4"
                                        href="https://ratecalcapi.lbcapps.com/Tools/PH_Rate_Calculator"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Check this route with LBC’s official
                                        rate calculator
                                    </a>
                                    <p
                                        v-if="selectedQuote.is_demo"
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        {{ selectedQuote.assumption_label }}
                                    </p>
                                </template>
                                <p
                                    v-if="
                                        selectedQuote.pricing_status ===
                                            'configured_estimate' ||
                                        selectedQuote.pricing_status ===
                                            'regional_estimate'
                                    "
                                    class="border-border text-muted-foreground border-t pt-2 text-xs leading-5"
                                >
                                    The shipping fee shown here is fixed when
                                    you place the order. Battlefront covers any
                                    difference from the courier's final charge.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section
                        class="surface-panel border-border bg-card grid gap-6 border p-6"
                        aria-labelledby="payment-heading"
                    >
                        <div class="flex items-center gap-3">
                            <CreditCard
                                class="text-primary-ink size-5"
                                aria-hidden="true"
                            />
                            <div>
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                                >
                                    Step 3
                                </p>
                                <h2 id="payment-heading" class="font-bold">
                                    Payment method
                                </h2>
                            </div>
                        </div>

                        <div
                            class="grid gap-3 sm:grid-cols-2"
                            role="radiogroup"
                            aria-labelledby="payment-heading"
                        >
                            <label
                                v-for="method in paymentMethods"
                                :key="method.value"
                                class="border-border has-checked:border-primary has-checked:bg-primary/5 focus-within:ring-ring/50 grid cursor-pointer grid-cols-[auto_1fr] gap-3 border p-4 transition-colors focus-within:ring-3 has-disabled:cursor-not-allowed has-disabled:opacity-50"
                            >
                                <input
                                    class="accent-primary mt-1 size-4"
                                    type="radio"
                                    name="payment_method"
                                    :value="method.value"
                                    :checked="paymentMethod === method.value"
                                    :disabled="
                                        !availablePaymentMethods.some(
                                            (available) =>
                                                available.value ===
                                                method.value,
                                        )
                                    "
                                    @change="choosePayment(method.value)"
                                />
                                <span>
                                    <span class="font-semibold">{{
                                        method.label
                                    }}</span>
                                    <span
                                        class="text-muted-foreground mt-1 block text-xs leading-5"
                                    >
                                        {{
                                            method.description ??
                                            (method.value === 'cod'
                                                ? 'Pay upon delivery. Delivery orders only.'
                                                : method.value === 'online'
                                                  ? 'Pay securely on PayMongo using an available online method. No receipt upload is required.'
                                                  : method.requires_proof
                                                    ? 'Manual e-wallet payment with proof.'
                                                    : fulfillmentMethod ===
                                                        'delivery'
                                                      ? 'Available for pickup only.'
                                                      : 'Pay when you collect your order.')
                                        }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.payment_method" />

                        <div
                            v-if="paymentMethod === 'online'"
                            class="border-border bg-secondary/40 grid gap-4 border p-4"
                        >
                            <div class="grid gap-2">
                                <InputError :message="errors.payment_channel" />
                                <Label for="hosted-payment-method"
                                    >Pay with</Label
                                >
                                <select
                                    id="hosted-payment-method"
                                    v-model="hostedPaymentMethod"
                                    class="border-input bg-background h-10 w-full border px-3 text-sm"
                                >
                                    <option
                                        v-if="
                                            paymongoHostedMethods.includes(
                                                'qrph',
                                            )
                                        "
                                        value="qrph"
                                    >
                                        QR Ph — GCash, Maya, and supported bank
                                        apps
                                    </option>
                                    <option
                                        v-if="
                                            paymongoHostedMethods.includes(
                                                'card',
                                            )
                                        "
                                        value="card"
                                    >
                                        Credit or debit card
                                    </option>
                                    <option
                                        v-if="
                                            paymongoHostedMethods.includes(
                                                'gcash',
                                            )
                                        "
                                        value="gcash"
                                    >
                                        GCash
                                    </option>
                                    <option
                                        v-if="
                                            paymongoHostedMethods.includes(
                                                'paymaya',
                                            )
                                        "
                                        value="paymaya"
                                    >
                                        Maya
                                    </option>
                                </select>
                                <InputError
                                    :message="errors.hosted_payment_method"
                                />
                                <p
                                    v-if="hostedPaymentMethod === 'qrph'"
                                    class="text-muted-foreground text-xs leading-5"
                                >
                                    After you place the order, PayMongo will
                                    open in a new tab and display your QR Ph
                                    code. Keep this page open to check your
                                    order.
                                </p>
                                <p
                                    v-else-if="hostedPaymentMethod === 'card'"
                                    class="text-muted-foreground text-xs leading-5"
                                >
                                    Pay securely by card on PayMongo.
                                </p>
                                <p
                                    v-else
                                    class="text-muted-foreground text-xs leading-5"
                                >
                                    Continue to PayMongo to pay with your
                                    selected wallet.
                                </p>
                            </div>
                        </div>

                        <Alert v-if="isCod">
                            <AlertTitle>Pay upon delivery</AlertTitle>
                            <AlertDescription>
                                <template
                                    v-if="
                                        selectedQuote?.pricing_configured ===
                                        false
                                    "
                                >
                                    Only the product subtotal is payable upon
                                    delivery. No delivery fee or LBC collection
                                    arrangement is included; delivery
                                    arrangements must be confirmed separately.
                                </template>
                                <template
                                    v-else-if="
                                        selectedQuote?.delivery_arrangement_required
                                    "
                                >
                                    The total COD amount includes the product
                                    subtotal and Battlefront’s regional delivery
                                    fee estimate. Battlefront will confirm the
                                    delivery arrangement manually. No advance
                                    payment or payment proof is required.
                                </template>
                                <template v-else>
                                    The total COD amount includes your products
                                    and the configured demo delivery fee. LBC
                                    collects the full amount at delivery.
                                    Battlefront records payment after LBC remits
                                    the full order amount. No advance payment or
                                    payment proof is required. Battlefront
                                    arranges delivery manually; this checkout
                                    does not create an LBC booking.
                                </template>
                            </AlertDescription>
                        </Alert>

                        <div
                            v-if="requiresPaymentProof"
                            class="border-border bg-secondary/40 grid gap-4 border p-4"
                        >
                            <p
                                v-if="
                                    fulfillmentMethod === 'delivery' &&
                                    selectedQuote
                                "
                                class="text-sm leading-6"
                            >
                                <template
                                    v-if="
                                        selectedQuote.pricing_configured ===
                                        false
                                    "
                                >
                                    The amount shown covers the product subtotal
                                    only; no delivery fee is included. Any
                                    delivery arrangement and charge must be
                                    confirmed separately.
                                </template>
                                <template
                                    v-else-if="
                                        selectedQuote.delivery_arrangement_required
                                    "
                                >
                                    Pay
                                    {{ formatCurrency(selectedQuote.total) }} to
                                    Battlefront, including the product subtotal
                                    and regional delivery fee estimate.
                                    Battlefront will confirm delivery
                                    arrangements manually.
                                </template>
                                <template v-else>
                                    Pay
                                    {{ formatCurrency(selectedQuote.total) }} to
                                    Battlefront, including the product subtotal
                                    and estimated delivery fee. Battlefront
                                    arranges shipment and pays the carrier at
                                    dispatch.
                                </template>
                            </p>
                            <div class="grid gap-4">
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div>
                                        <p class="font-semibold">
                                            {{ selectedPaymentMethod.label }}
                                            receiving account
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 text-sm"
                                        >
                                            Send payment only to the account
                                            shown for this selected wallet.
                                        </p>
                                    </div>
                                    <span
                                        v-if="selectedPaymentAccount.is_demo"
                                        class="border-primary/40 bg-primary/10 text-primary-ink shrink-0 border px-2 py-1 text-xs font-semibold tracking-wide uppercase"
                                    >
                                        Demo details
                                    </span>
                                </div>

                                <dl
                                    class="border-border bg-background grid gap-3 border p-4 sm:grid-cols-2"
                                >
                                    <div class="grid gap-1">
                                        <dt
                                            class="text-muted-foreground text-xs font-medium"
                                        >
                                            Account name
                                        </dt>
                                        <dd class="font-semibold">
                                            {{
                                                selectedPaymentAccount.account_name
                                            }}
                                        </dd>
                                    </div>
                                    <div class="grid gap-1">
                                        <dt
                                            class="text-muted-foreground text-xs font-medium"
                                        >
                                            Account/mobile number
                                        </dt>
                                        <dd
                                            class="font-semibold tracking-wide tabular-nums"
                                        >
                                            {{
                                                selectedPaymentAccount.account_number
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="border-border flex gap-3 border-t pt-4">
                                <Upload
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <p class="font-semibold">
                                        Pay first, then capture proof
                                    </p>
                                    <p
                                        class="text-muted-foreground mt-1 text-sm leading-6"
                                    >
                                        Complete payment to the account above
                                        first. After
                                        {{ selectedPaymentMethod.label }} shows
                                        a successful transaction, take a
                                        screenshot or snapshot and upload it
                                        below. Your proof remains pending manual
                                        admin verification and is not treated as
                                        verified.
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="payment-proof">Payment proof</Label>
                                <FileInput
                                    :key="paymentProofKey"
                                    id="payment-proof"
                                    name="payment_proof"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    :aria-invalid="
                                        Boolean(errors.payment_proof)
                                    "
                                    required
                                />
                                <p class="text-muted-foreground text-xs">
                                    JPEG, PNG, or WebP up to 5 MB. Proof is
                                    handled as private evidence.
                                </p>
                                <InputError :message="errors.payment_proof" />
                            </div>
                        </div>
                    </section>
                </div>

                <aside
                    class="surface-panel border-border bg-card border p-6 xl:sticky xl:top-6"
                    aria-labelledby="checkout-summary-heading"
                >
                    <p
                        class="text-primary-ink text-xs font-semibold tracking-widest uppercase"
                    >
                        Current cart
                    </p>
                    <h2
                        id="checkout-summary-heading"
                        class="mt-2 text-xl font-bold"
                    >
                        Hardware summary
                    </h2>

                    <ul class="border-border mt-6 divide-y border-y">
                        <li
                            v-for="item in cart.items"
                            :key="item.id"
                            class="grid grid-cols-[1fr_auto] gap-4 py-4"
                        >
                            <div class="min-w-0">
                                <p
                                    v-if="item.pc_build_group"
                                    class="text-primary-ink mb-1 text-xs font-semibold tracking-wide uppercase"
                                >
                                    PC build · {{ item.pc_build_group.name }}
                                </p>
                                <p class="truncate text-sm font-semibold">
                                    {{ item.product.name }}
                                </p>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    <template v-if="item.product.brand">
                                        {{ item.product.brand }} ·
                                    </template>
                                    Qty
                                    {{ item.quantity }}
                                </p>
                            </div>
                            <p class="text-sm font-semibold tabular-nums">
                                {{ formatCurrency(item.line_total) }}
                            </p>
                        </li>
                    </ul>

                    <dl class="grid gap-3 py-5">
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-muted-foreground">Products</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ cart.item_count }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-muted-foreground">Units</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ cart.total_quantity }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-muted-foreground">
                                Product subtotal
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{
                                    formatCurrency(pickupQuote.product_subtotal)
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 text-sm"
                            aria-live="polite"
                        >
                            <dt class="text-muted-foreground">Delivery fee</dt>
                            <dd class="font-semibold tabular-nums">
                                {{
                                    selectedQuote?.pricing_status ===
                                    'not_configured'
                                        ? 'Not quoted'
                                        : selectedQuote
                                          ? formatCurrency(
                                                selectedQuote.delivery_fee,
                                            )
                                          : quoteNotice
                                            ? 'Unavailable'
                                            : 'Select destination'
                                }}
                            </dd>
                        </div>
                        <div
                            class="border-border flex items-end justify-between gap-4 border-t pt-4"
                        >
                            <dt class="font-semibold">
                                {{
                                    selectedQuote?.pricing_status ===
                                    'not_configured'
                                        ? isCod
                                            ? 'Product subtotal payable upon delivery'
                                            : 'Product subtotal payable'
                                        : isCod
                                          ? 'Total COD amount payable'
                                          : 'Final total'
                                }}
                            </dt>
                            <dd class="text-2xl font-bold tabular-nums">
                                {{
                                    selectedQuote?.pricing_status ===
                                    'not_configured'
                                        ? formatCurrency(
                                              selectedQuote.product_subtotal,
                                          )
                                        : selectedQuote
                                          ? formatCurrency(selectedQuote.total)
                                          : '—'
                                }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="progress && requiresPaymentProof"
                        class="mb-4 grid gap-2"
                        aria-live="polite"
                    >
                        <div
                            class="flex justify-between gap-4 text-xs font-medium"
                        >
                            <span>Uploading payment proof</span>
                            <span>{{ progress.percentage }}%</span>
                        </div>
                        <progress
                            class="accent-primary h-2 w-full"
                            :value="progress.percentage"
                            max="100"
                        />
                    </div>

                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="
                            processing ||
                            !selectedQuote ||
                            !cart.items.length ||
                            !paymentMethod
                        "
                    >
                        <Spinner v-if="processing" />
                        <BadgeCheck v-else aria-hidden="true" />
                        {{
                            processing
                                ? 'Placing order...'
                                : quoteNotice && !selectedQuote
                                  ? 'Delivery unavailable'
                                  : 'Place order'
                        }}
                    </Button>

                    <p
                        class="text-muted-foreground mt-3 text-center text-xs leading-5"
                    >
                        Stock is deducted and your cart is cleared only after
                        the complete order succeeds.
                    </p>

                    <Button as-child variant="outline" class="mt-5 w-full">
                        <Link :href="cartIndex()">
                            <ArrowLeft aria-hidden="true" />
                            Back to cart
                        </Link>
                    </Button>
                </aside>
            </Form>
        </main>
    </div>
</template>
