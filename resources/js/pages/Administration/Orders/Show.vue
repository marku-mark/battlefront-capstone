<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    Banknote,
    CircleAlert,
    CircleX,
    ExternalLink,
    MapPin,
    PackageCheck,
    ReceiptText,
    ShieldCheck,
    Truck,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import OrderHandlerController from '@/actions/App/Http/Controllers/Administration/OrderHandlerController';
import OrderPaymentProofController from '@/actions/App/Http/Controllers/Administration/OrderPaymentProofController';
import OrderPaymentStatusController from '@/actions/App/Http/Controllers/Administration/OrderPaymentStatusController';
import OrderManualShipmentController from '@/actions/App/Http/Controllers/Administration/OrderManualShipmentController';
import OrderLocalLbcBookingController from '@/actions/App/Http/Controllers/Administration/OrderLocalLbcBookingController';
import OrderStatusController from '@/actions/App/Http/Controllers/Administration/OrderStatusController';
import OrderShipmentStatusController from '@/actions/App/Http/Controllers/Administration/OrderShipmentStatusController';
import OrderShipmentReferenceController from '@/actions/App/Http/Controllers/Administration/OrderShipmentReferenceController';
import InputError from '@/components/InputError.vue';
import ShipmentTracking from '@/components/ShipmentTracking.vue';
import OrderCancellationPanel from '@/components/OrderCancellationPanel.vue';
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
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/dateTime';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';
import { store as confirmCodCollection } from '@/routes/administration/orders/cod-collection';
import { store as confirmCodRemittance } from '@/routes/administration/orders/cod-remittance';
import { store as confirmPickup } from '@/routes/administration/orders/pickup-confirmation';
import { update as updateShipmentOperations } from '@/routes/administration/orders/shipment/operations';

const props = defineProps({
    order: { type: Object, required: true },
    handler_options: { type: Array, default: () => [] },
});

const handlerForm = useForm({
    handler_id: '',
});
const claimForm = useForm({});
const page = usePage();
const canProcess = computed(() => props.order.can_process === true);
const unfinished = computed(
    () => !['completed', 'cancelled'].includes(props.order.status.value),
);
const cancellationPending = computed(
    () => props.order.cancellation?.status.value === 'requested',
);
const canClaim = computed(
    () =>
        unfinished.value &&
        !cancellationPending.value &&
        !props.order.assigned_handler_id &&
        page.props.auth.can?.processOrders === true,
);
const availableHandlers = computed(() =>
    props.handler_options.filter((handler) =>
        props.order.assigned_handler_id
            ? handler.id !== props.order.assigned_handler_id
            : handler.id !== page.props.auth.user.id,
    ),
);
const handlerAction = computed(() =>
    props.order.assigned_handler_id ? 'Reassign handler' : 'Assign handler',
);
const canAssign = computed(
    () =>
        unfinished.value &&
        !cancellationPending.value &&
        page.props.auth.can?.assignOrders === true,
);
const validHandlerSelection = computed(() =>
    availableHandlers.value.some(
        (handler) => String(handler.id) === String(handlerForm.handler_id),
    ),
);
const handlerNotice = computed(() => {
    if (props.order.cancellation?.status.value === 'requested') {
        return 'Order handling is paused for cancellation review.';
    }
    return '';
});

watch(
    () => [props.order.id, props.order.assigned_handler_id],
    () => {
        handlerForm.reset();
        handlerForm.clearErrors();
    },
);

function submitHandler() {
    if (
        !canAssign.value ||
        !validHandlerSelection.value ||
        handlerForm.processing
    ) {
        return;
    }
    handlerForm.patch(OrderHandlerController.assign(props.order.id), {
        onSuccess: () => handlerForm.reset(),
    });
}

function submitClaim() {
    if (!canClaim.value || claimForm.processing) {
        return;
    }
    claimForm.post(OrderHandlerController.claim(props.order.id));
}

const selectedOrderStatus = ref('');
const orderConfirmationOpen = ref(false);
const paymentConfirmationOpen = ref(false);
const codConfirmationOpen = ref(false);
const codCollectionForm = useForm({
    cod_collection_confirmed: false,
    carrier_cod_collection_reference: '',
});
const codRemittanceConfirmationOpen = ref(false);
const codRemittanceForm = useForm({
    cod_remittance_confirmed: false,
    carrier_cod_remittance_reference: '',
});
const pickupConfirmationOpen = ref(false);
const pickupConfirmationForm = useForm({
    payment_received_confirmed: false,
    products_handed_over_confirmed: false,
});
const orderStatusForm = useForm({
    status: '',
    products_handed_over_confirmed: false,
});
const manualShipmentDialogOpen = ref(false);
const localLbcBookingDialogOpen = ref(false);
const localLbcBookingForm = useForm({});
const manualShipmentForm = useForm({
    arrangement_confirmed: false,
    carrier: 'lbc',
    tracking_reference: '',
    eta_min_days: '',
    eta_max_days: '',
});
const shipmentConfirmationOpen = ref(false);
const shipmentStatusForm = useForm({ status: '', tracking_reference: null });
const shipmentReferenceForm = useForm({
    tracking_reference: props.order.shipment?.tracking_reference ?? '',
});
const shipmentOperations = props.order.shipment?.operations ?? {};
const shipmentOperationsSaved = ref(Boolean(shipmentOperations.package_count));
const shipmentPackageEditing = ref(!shipmentOperationsSaved.value);
const shipmentCostRecorded = ref(
    Boolean(shipmentOperations.carrier_cost_recorded_at),
);
const shipmentCostEditing = ref(!shipmentCostRecorded.value);
const shipmentExceptionEditing = ref(
    Boolean(
        shipmentOperations.exception_reported_at &&
        !shipmentOperations.exception_resolved_at,
    ),
);
const shipmentExceptionActive = ref(
    Boolean(
        shipmentOperations.exception_reported_at &&
        !shipmentOperations.exception_resolved_at,
    ),
);
const shipmentHandedOff = computed(() =>
    ['handed_to_lbc', 'in_transit', 'out_for_delivery', 'delivered'].includes(
        props.order.shipment?.status.value,
    ),
);
const shipmentOperationsForm = useForm({
    package_count: shipmentOperations.package_count ?? '',
    package_weight_kg: shipmentOperations.package_weight_kg ?? '',
    package_length_cm: shipmentOperations.package_length_cm ?? '',
    package_width_cm: shipmentOperations.package_width_cm ?? '',
    package_height_cm: shipmentOperations.package_height_cm ?? '',
    declared_value: shipmentOperations.declared_value ?? '',
    handoff_mode: shipmentOperations.handoff_mode ?? '',
    actual_carrier_cost: shipmentOperations.actual_carrier_cost ?? '',
    carrier_cost_reference: shipmentOperations.carrier_cost_reference ?? '',
    carrier_cost_confirmed: Boolean(
        shipmentOperations.carrier_cost_recorded_at,
    ),
    shipment_exception_code: shipmentOperations.exception_code ?? '',
    shipment_exception_note: shipmentOperations.exception_note ?? '',
    shipment_exception_resolved: Boolean(
        shipmentOperations.exception_resolved_at,
    ),
});
watch(
    () => props.order.id,
    () => {
        const operations = props.order.shipment?.operations ?? {};
        const values = {
            package_count: operations.package_count ?? '',
            package_weight_kg: operations.package_weight_kg ?? '',
            package_length_cm: operations.package_length_cm ?? '',
            package_width_cm: operations.package_width_cm ?? '',
            package_height_cm: operations.package_height_cm ?? '',
            declared_value: operations.declared_value ?? '',
            handoff_mode: operations.handoff_mode ?? '',
            actual_carrier_cost: operations.actual_carrier_cost ?? '',
            carrier_cost_reference: operations.carrier_cost_reference ?? '',
            carrier_cost_confirmed: Boolean(
                operations.carrier_cost_recorded_at,
            ),
            shipment_exception_code: operations.exception_code ?? '',
            shipment_exception_note: operations.exception_note ?? '',
            shipment_exception_resolved: Boolean(
                operations.exception_resolved_at,
            ),
        };

        shipmentOperationsForm.defaults(values);
        shipmentOperationsForm.reset();
        shipmentOperationsForm.clearErrors();
        shipmentOperationsSaved.value = Boolean(operations.package_count);
        shipmentPackageEditing.value = !shipmentOperationsSaved.value;
        shipmentCostRecorded.value = Boolean(
            operations.carrier_cost_recorded_at,
        );
        shipmentCostEditing.value = !shipmentCostRecorded.value;
        shipmentExceptionActive.value = Boolean(
            operations.exception_reported_at &&
            !operations.exception_resolved_at,
        );
        shipmentExceptionEditing.value = shipmentExceptionActive.value;
    },
);
const paymentStatusForm = useForm({
    payment_status: '',
    manual_verification_confirmed: false,
    rejection_reason: '',
    rejection_note: '',
});

const selectedOrderTransition = computed(() =>
    props.order.allowed_status_transitions.find(
        (transition) => transition.value === selectedOrderStatus.value,
    ),
);
const orderConfirmationTitle = computed(() =>
    selectedOrderStatus.value === 'completed'
        ? `Mark ${props.order.reference} completed?`
        : `Cancel ${props.order.reference}?`,
);
const orderConfirmationDescription = computed(() =>
    selectedOrderStatus.value === 'completed'
        ? 'Confirm that fulfillment is finished before completing this order.'
        : props.order.payment.status.value === 'verified'
          ? 'Cancelling restores reserved stock. Verified payment stays recorded; any refund requires separate manual handling.'
          : 'Cancelling restores reserved stock and keeps the order in history.',
);
const paymentConfirmationTitle = computed(() =>
    paymentStatusForm.payment_status === 'verified'
        ? `Mark payment for ${props.order.reference} verified?`
        : `Reject payment for ${props.order.reference}?`,
);
const paymentConfirmationDescription = computed(() =>
    paymentStatusForm.payment_status === 'verified'
        ? 'Confirm that the payment was manually cross-checked in the selected payment platform or account.'
        : 'Confirm that the payment could not be verified and should be rejected.',
);
const rejectingWalletPayment = computed(
    () =>
        paymentStatusForm.payment_status === 'rejected' &&
        props.order.payment.method.requires_proof,
);

function requestCodConfirmation() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.payment.can_confirm_cod_collection ||
        codCollectionForm.processing
    ) {
        return;
    }
    codCollectionForm.reset();
    codCollectionForm.clearErrors();
    codConfirmationOpen.value = true;
}

function submitCodCollection() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.payment.can_confirm_cod_collection ||
        !codCollectionForm.cod_collection_confirmed ||
        codCollectionForm.processing
    ) {
        return;
    }
    codCollectionForm.submit(confirmCodCollection(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            codConfirmationOpen.value = false;
            codCollectionForm.reset();
        },
    });
}

function requestCodRemittanceConfirmation() {
    if (
        !canProcess.value ||
        !props.order.payment.can_confirm_cod_remittance ||
        codRemittanceForm.processing
    ) {
        return;
    }

    codRemittanceForm.reset();
    codRemittanceForm.clearErrors();
    codRemittanceConfirmationOpen.value = true;
}

function submitCodRemittance() {
    if (
        !canProcess.value ||
        !props.order.payment.can_confirm_cod_remittance ||
        !codRemittanceForm.cod_remittance_confirmed ||
        codRemittanceForm.processing
    ) {
        return;
    }

    codRemittanceForm.post(confirmCodRemittance(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            codRemittanceConfirmationOpen.value = false;
            codRemittanceForm.reset();
        },
    });
}

function submitShipmentOperations(group) {
    if (
        !canProcess.value ||
        !props.order.shipment ||
        shipmentOperationsForm.processing
    ) {
        return;
    }

    const fields = {
        package: [
            'package_count',
            'package_weight_kg',
            'package_length_cm',
            'package_width_cm',
            'package_height_cm',
            'declared_value',
            'handoff_mode',
        ],
        carrier_cost: [
            'actual_carrier_cost',
            'carrier_cost_reference',
            'carrier_cost_confirmed',
        ],
        exception: [
            'shipment_exception_code',
            'shipment_exception_note',
            'shipment_exception_resolved',
        ],
    }[group];

    if (!fields) {
        return;
    }

    shipmentOperationsForm.transform((data) =>
        Object.fromEntries(fields.map((field) => [field, data[field]])),
    );
    shipmentOperationsForm.patch(updateShipmentOperations(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (group === 'package') {
                shipmentOperationsSaved.value = true;
                shipmentPackageEditing.value = false;
            } else if (group === 'carrier_cost') {
                shipmentCostRecorded.value = true;
                shipmentCostEditing.value = false;
            } else {
                shipmentExceptionActive.value =
                    !shipmentOperationsForm.shipment_exception_resolved;
                shipmentExceptionEditing.value = shipmentExceptionActive.value;
            }
        },
        onFinish: () => {
            shipmentOperationsForm.transform((data) => data);
        },
    });
}

function startShipmentException() {
    shipmentOperationsForm.shipment_exception_code = '';
    shipmentOperationsForm.shipment_exception_note = '';
    shipmentOperationsForm.shipment_exception_resolved = false;
    shipmentExceptionEditing.value = true;
}

function requestPickupConfirmation() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.payment.can_confirm_pickup ||
        pickupConfirmationForm.processing
    ) {
        return;
    }
    pickupConfirmationForm.reset();
    pickupConfirmationForm.clearErrors();
    pickupConfirmationOpen.value = true;
}

function submitPickupConfirmation() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.payment.can_confirm_pickup ||
        !pickupConfirmationForm.payment_received_confirmed ||
        !pickupConfirmationForm.products_handed_over_confirmed ||
        pickupConfirmationForm.processing
    ) {
        return;
    }
    pickupConfirmationForm.submit(confirmPickup(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            pickupConfirmationOpen.value = false;
            pickupConfirmationForm.reset();
        },
    });
}

function selectOrderStatus(status) {
    if (!canProcess.value) {
        return;
    }
    selectedOrderStatus.value = status;
    orderStatusForm.clearErrors();
}

function requestOrderStatusUpdate() {
    if (!canProcess.value) {
        return;
    }
    if (!selectedOrderTransition.value) {
        return;
    }

    orderStatusForm.status = selectedOrderStatus.value;

    if (['completed', 'cancelled'].includes(selectedOrderStatus.value)) {
        orderConfirmationOpen.value = true;

        return;
    }

    submitOrderStatus();
}

function submitOrderStatus() {
    if (!canProcess.value) {
        return;
    }
    if (
        orderStatusForm.status === 'completed' &&
        props.order.fulfillment.value === 'pickup' &&
        !orderStatusForm.products_handed_over_confirmed
    ) {
        return;
    }
    orderStatusForm.submit(OrderStatusController.update(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            orderConfirmationOpen.value = false;
            selectedOrderStatus.value = '';
            orderStatusForm.reset();
        },
        onError: () => {
            orderConfirmationOpen.value = false;
        },
    });
}

function requestShipmentUpdate() {
    if (!canProcess.value) {
        return;
    }
    const next = props.order.shipment_actions?.next_status;
    if (!next || shipmentStatusForm.processing) {
        return;
    }
    shipmentStatusForm.status = next.value;
    if (next.value === 'delivered') {
        shipmentConfirmationOpen.value = true;
        return;
    }
    submitShipmentStatus();
}

function submitShipmentStatus() {
    if (!canProcess.value) {
        return;
    }
    shipmentStatusForm.submit(
        OrderShipmentStatusController.update(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                shipmentConfirmationOpen.value = false;
                shipmentStatusForm.reset();
                shipmentReferenceForm.tracking_reference =
                    props.order.shipment?.tracking_reference ?? '';
            },
            onError: () => {
                shipmentConfirmationOpen.value = false;
            },
        },
    );
}

function submitShipmentReference() {
    if (!canProcess.value) {
        return;
    }
    shipmentReferenceForm.submit(
        OrderShipmentReferenceController.update(props.order.id),
        {
            preserveScroll: true,
        },
    );
}

function requestManualShipment() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.can_create_manual_shipment ||
        manualShipmentForm.processing
    ) {
        return;
    }

    manualShipmentForm.reset();
    manualShipmentForm.clearErrors();
    manualShipmentDialogOpen.value = true;
}

function submitManualShipment() {
    if (!canProcess.value) {
        return;
    }
    if (
        !props.order.can_create_manual_shipment ||
        !manualShipmentForm.arrangement_confirmed ||
        !manualShipmentForm.carrier ||
        manualShipmentForm.processing
    ) {
        return;
    }

    manualShipmentForm.post(
        OrderManualShipmentController.store(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                manualShipmentDialogOpen.value = false;
                manualShipmentForm.reset();
            },
        },
    );
}

function requestLocalLbcBookingSimulation() {
    if (
        !canProcess.value ||
        !props.order.shipment_actions?.can_simulate_local_lbc_booking
    ) {
        return;
    }

    localLbcBookingForm.clearErrors();
    localLbcBookingDialogOpen.value = true;
}

function submitLocalLbcBookingSimulation() {
    if (
        !canProcess.value ||
        !props.order.shipment_actions?.can_simulate_local_lbc_booking ||
        localLbcBookingForm.processing
    ) {
        return;
    }

    localLbcBookingForm.post(
        OrderLocalLbcBookingController.store(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                localLbcBookingDialogOpen.value = false;
            },
        },
    );
}

function selectPaymentStatus(status) {
    if (!canProcess.value) {
        return;
    }
    paymentStatusForm.payment_status = status;
    paymentStatusForm.clearErrors();

    if (status !== 'verified') {
        paymentStatusForm.manual_verification_confirmed = false;
    }

    if (status !== 'rejected') {
        paymentStatusForm.rejection_reason = '';
        paymentStatusForm.rejection_note = '';
    }
}

function requestPaymentStatusUpdate() {
    if (!canProcess.value) {
        return;
    }
    if (!paymentStatusForm.payment_status) {
        return;
    }

    paymentConfirmationOpen.value = true;
}

function submitPaymentStatus() {
    if (!canProcess.value) {
        return;
    }
    paymentStatusForm.submit(
        OrderPaymentStatusController.update(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                paymentConfirmationOpen.value = false;
                paymentStatusForm.reset();
            },
            onError: () => {
                paymentConfirmationOpen.value = false;
            },
        },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Orders',
                href: OrderController.index(),
            },
            {
                title: 'Order record',
                href: OrderController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="order.reference" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <OrderCancellationPanel
            v-if="order.cancellation"
            :order-id="order.id"
            :cancellation="order.cancellation"
            :payment-method="order.payment.method.value"
            administration
        />
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <ReceiptText class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Administrator order record
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <h1
                                class="text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"
                            >
                                {{ order.reference }}
                            </h1>
                            <Badge
                                variant="outline"
                                :class="
                                    orderStatusBadgeClass(order.status.value)
                                "
                            >
                                {{ order.status.label }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-2 text-sm">
                            Placed
                            {{ formatDateTime(order.created_at, 'long') }}
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="OrderController.index()">
                        <ArrowLeft />
                        Back to orders
                    </Link>
                </Button>
            </div>
        </section>

        <section
            class="border-border bg-card flex flex-wrap items-end gap-4 border p-5"
        >
            <div class="min-w-48 flex-1">
                <p class="font-semibold">
                    {{
                        order.assigned_handler_id
                            ? `Handled by ${order.assigned_handler_name}`
                            : 'Unassigned'
                    }}
                </p>
                <p
                    v-if="
                        unfinished && order.assigned_handler_id && !canProcess
                    "
                    class="text-muted-foreground mt-1 text-sm"
                >
                    View only
                </p>
                <p
                    v-if="handlerNotice"
                    class="text-muted-foreground mt-1 text-sm"
                >
                    {{ handlerNotice }}
                </p>
            </div>
            <form v-if="canClaim" @submit.prevent="submitClaim">
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="claimForm.processing"
                    >Claim order</Button
                >
                <InputError :message="claimForm.errors.handler" />
            </form>
            <form
                v-if="availableHandlers.length && canAssign"
                class="flex w-full flex-wrap items-end gap-2 sm:w-auto"
                @submit.prevent="submitHandler"
            >
                <div class="min-w-0 flex-1 sm:flex-none">
                    <Label for="assigned-handler">{{ handlerAction }}</Label>
                    <select
                        id="assigned-handler"
                        v-model="handlerForm.handler_id"
                        class="border-input bg-background mt-1 block h-9 w-full min-w-0 border px-2 text-sm sm:w-44"
                    >
                        <option value="">Select handler</option>
                        <option
                            v-for="handler in availableHandlers"
                            :key="handler.id"
                            :value="handler.id"
                        >
                            {{ handler.name }}
                        </option>
                    </select>
                </div>
                <Button
                    type="submit"
                    :disabled="!validHandlerSelection || handlerForm.processing"
                    >{{ handlerAction }}</Button
                >
                <InputError
                    :message="
                        handlerForm.errors.handler_id ||
                        handlerForm.errors.handler
                    "
                />
            </form>
        </section>

        <div
            class="grid gap-8 xl:grid-cols-[minmax(0,1.6fr)_minmax(20rem,0.8fr)]"
        >
            <div class="flex min-w-0 flex-col gap-8">
                <section
                    v-if="order.delivery_next_step"
                    class="border-border bg-card grid gap-1 border p-4"
                    aria-labelledby="delivery-next-step-heading"
                >
                    <h2
                        id="delivery-next-step-heading"
                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                    >
                        Next delivery step
                    </h2>
                    <p class="font-semibold">
                        {{ order.delivery_next_step.title }}
                    </p>
                    <p class="text-muted-foreground text-sm leading-5">
                        {{ order.delivery_next_step.detail }}
                    </p>
                </section>
                <ShipmentTracking
                    v-if="order.shipment"
                    :shipment="order.shipment"
                    :quote="order.delivery_quote"
                />
                <section
                    v-if="canProcess && order.shipment"
                    class="border-border bg-card grid gap-5 border p-5"
                    aria-labelledby="shipment-operations-heading"
                >
                    <div>
                        <h2
                            id="shipment-operations-heading"
                            class="font-semibold"
                        >
                            Package and carrier records
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Enter the packed parcel details before confirming
                            handoff. These records support manual LBC
                            processing; they are not an LBC quote or booking.
                        </p>
                    </div>
                    <fieldset
                        class="grid gap-4"
                        :disabled="
                            !order.shipment_actions
                                ?.can_update_package_details ||
                            !shipmentPackageEditing ||
                            shipmentHandedOff
                        "
                    >
                        <legend class="text-sm font-medium">
                            Packed shipment
                        </legend>
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="shipment-package-count"
                                    >Parcel count</Label
                                >
                                <Input
                                    id="shipment-package-count"
                                    v-model="
                                        shipmentOperationsForm.package_count
                                    "
                                    type="number"
                                    min="1"
                                    max="99"
                                    step="1"
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .package_count
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="shipment-weight"
                                    >Total weight (kg)</Label
                                >
                                <Input
                                    id="shipment-weight"
                                    v-model="
                                        shipmentOperationsForm.package_weight_kg
                                    "
                                    type="number"
                                    min="0.001"
                                    step="0.001"
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .package_weight_kg
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="shipment-declared-value"
                                    >Declared value (PHP)</Label
                                >
                                <Input
                                    id="shipment-declared-value"
                                    v-model="
                                        shipmentOperationsForm.declared_value
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .declared_value
                                    "
                                />
                            </div>
                        </div>
                        <fieldset class="grid gap-3">
                            <legend class="text-sm font-medium">
                                Parcel dimensions (cm)
                            </legend>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="shipment-length">Length</Label>
                                    <Input
                                        id="shipment-length"
                                        v-model="
                                            shipmentOperationsForm.package_length_cm
                                        "
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                    />
                                    <InputError
                                        :message="
                                            shipmentOperationsForm.errors
                                                .package_length_cm
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="shipment-width">Width</Label>
                                    <Input
                                        id="shipment-width"
                                        v-model="
                                            shipmentOperationsForm.package_width_cm
                                        "
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                    />
                                    <InputError
                                        :message="
                                            shipmentOperationsForm.errors
                                                .package_width_cm
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="shipment-height">Height</Label>
                                    <Input
                                        id="shipment-height"
                                        v-model="
                                            shipmentOperationsForm.package_height_cm
                                        "
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                    />
                                    <InputError
                                        :message="
                                            shipmentOperationsForm.errors
                                                .package_height_cm
                                        "
                                    />
                                </div>
                            </div>
                        </fieldset>
                        <div class="grid gap-2">
                            <Label for="shipment-handoff-mode"
                                >LBC handoff arrangement</Label
                            >
                            <Select
                                :model-value="
                                    shipmentOperationsForm.handoff_mode
                                "
                                :disabled="
                                    !order.shipment_actions
                                        ?.can_update_package_details ||
                                    !shipmentPackageEditing ||
                                    shipmentHandedOff
                                "
                                @update:model-value="
                                    shipmentOperationsForm.handoff_mode = $event
                                "
                            >
                                <SelectTrigger
                                    id="shipment-handoff-mode"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Select manually arranged handoff"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="rider_pickup"
                                        >Rider pickup</SelectItem
                                    >
                                    <SelectItem value="branch_dropoff"
                                        >Branch drop-off</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="
                                    shipmentOperationsForm.errors.handoff_mode
                                "
                            />
                        </div>
                    </fieldset>
                    <Button
                        v-if="
                            order.shipment_actions
                                ?.can_update_package_details &&
                            shipmentOperationsSaved &&
                            !shipmentPackageEditing
                        "
                        type="button"
                        variant="outline"
                        @click="shipmentPackageEditing = true"
                    >
                        Edit package details
                    </Button>
                    <Button
                        v-else-if="
                            order.shipment_actions
                                ?.can_update_package_details &&
                            shipmentPackageEditing
                        "
                        type="button"
                        :disabled="shipmentOperationsForm.processing"
                        @click="submitShipmentOperations('package')"
                    >
                        Save package details
                    </Button>
                    <div class="border-border grid gap-3 border-t pt-4">
                        <div>
                            <h3 class="text-sm font-medium">
                                Carrier cost reconciliation
                            </h3>
                            <p class="text-muted-foreground mt-1 text-sm">
                                The variance compares the saved customer
                                delivery fee with the recorded carrier cost. It
                                never changes the order total.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="shipment-carrier-cost"
                                    >Actual carrier cost (PHP)</Label
                                >
                                <Input
                                    id="shipment-carrier-cost"
                                    v-model="
                                        shipmentOperationsForm.actual_carrier_cost
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :disabled="
                                        !order.shipment_actions
                                            ?.can_record_carrier_cost ||
                                        !shipmentCostEditing ||
                                        shipmentCostRecorded
                                    "
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .actual_carrier_cost
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="shipment-carrier-cost-reference"
                                    >Receipt or carrier reference</Label
                                >
                                <Input
                                    id="shipment-carrier-cost-reference"
                                    v-model="
                                        shipmentOperationsForm.carrier_cost_reference
                                    "
                                    maxlength="100"
                                    :disabled="
                                        !order.shipment_actions
                                            ?.can_record_carrier_cost ||
                                        !shipmentCostEditing ||
                                        shipmentCostRecorded
                                    "
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .carrier_cost_reference
                                    "
                                />
                            </div>
                        </div>
                        <p
                            v-if="
                                order.shipment.operations
                                    ?.carrier_cost_recorded_at
                            "
                            class="text-sm"
                        >
                            Recorded cost:
                            {{
                                formatCurrency(
                                    order.shipment.operations
                                        .actual_carrier_cost,
                                )
                            }}
                            · variance
                            {{
                                formatCurrency(
                                    order.shipment.operations
                                        .delivery_fee_variance,
                                )
                            }}
                        </p>
                        <div
                            v-else-if="
                                shipmentOperationsForm.actual_carrier_cost !==
                                ''
                            "
                            class="flex items-start gap-3"
                        >
                            <Checkbox
                                id="carrier-cost-confirmed"
                                v-model="
                                    shipmentOperationsForm.carrier_cost_confirmed
                                "
                            />
                            <Label
                                for="carrier-cost-confirmed"
                                class="text-sm leading-5 font-normal"
                                >I checked this cost against the carrier receipt
                                or reference.</Label
                            >
                        </div>
                        <InputError
                            :message="
                                shipmentOperationsForm.errors
                                    .carrier_cost_confirmed
                            "
                        />
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                v-if="
                                    order.shipment_actions
                                        ?.can_record_carrier_cost &&
                                    !shipmentCostRecorded &&
                                    !shipmentCostEditing
                                "
                                type="button"
                                variant="outline"
                                @click="shipmentCostEditing = true"
                            >
                                Record carrier cost
                            </Button>
                            <Button
                                v-else-if="
                                    order.shipment_actions
                                        ?.can_record_carrier_cost &&
                                    !shipmentCostRecorded &&
                                    shipmentCostEditing
                                "
                                type="button"
                                :disabled="shipmentOperationsForm.processing"
                                @click="
                                    submitShipmentOperations('carrier_cost')
                                "
                            >
                                Save carrier cost
                            </Button>
                        </div>
                    </div>
                    <div class="border-border grid gap-3 border-t pt-4">
                        <div>
                            <h3 class="text-sm font-medium">
                                Shipment exception
                            </h3>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Record operational problems for staff follow-up.
                                This does not cancel the order or refund
                                payment.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="shipment-exception-code"
                                    >Issue</Label
                                >
                                <Select
                                    :model-value="
                                        shipmentOperationsForm.shipment_exception_code
                                    "
                                    :disabled="
                                        !order.shipment_actions
                                            ?.can_update_shipment_exception ||
                                        !shipmentExceptionEditing ||
                                        shipmentExceptionActive
                                    "
                                    @update:model-value="
                                        shipmentOperationsForm.shipment_exception_code =
                                            $event
                                    "
                                >
                                    <SelectTrigger
                                        id="shipment-exception-code"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            placeholder="No active exception"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="booking_failed"
                                            >Booking could not be
                                            arranged</SelectItem
                                        >
                                        <SelectItem value="pickup_missed"
                                            >Pickup was missed</SelectItem
                                        >
                                        <SelectItem
                                            value="delivery_attempt_failed"
                                            >Delivery attempt failed</SelectItem
                                        >
                                        <SelectItem value="damaged"
                                            >Package reported
                                            damaged</SelectItem
                                        >
                                        <SelectItem value="lost"
                                            >Package reported
                                            missing</SelectItem
                                        >
                                        <SelectItem value="return_to_sender"
                                            >Return to sender</SelectItem
                                        >
                                        <SelectItem value="other"
                                            >Other</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .shipment_exception_code
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="shipment-exception-note"
                                    >Staff note</Label
                                >
                                <Textarea
                                    id="shipment-exception-note"
                                    v-model="
                                        shipmentOperationsForm.shipment_exception_note
                                    "
                                    maxlength="2000"
                                    :disabled="
                                        !order.shipment_actions
                                            ?.can_update_shipment_exception ||
                                        !shipmentExceptionEditing ||
                                        shipmentExceptionActive
                                    "
                                />
                                <InputError
                                    :message="
                                        shipmentOperationsForm.errors
                                            .shipment_exception_note
                                    "
                                />
                            </div>
                        </div>
                        <div
                            v-if="shipmentExceptionActive"
                            class="flex items-start gap-3"
                        >
                            <Checkbox
                                id="shipment-exception-resolved"
                                v-model="
                                    shipmentOperationsForm.shipment_exception_resolved
                                "
                                :disabled="
                                    !order.shipment_actions
                                        ?.can_update_shipment_exception ||
                                    !shipmentExceptionEditing
                                "
                            />
                            <Label
                                for="shipment-exception-resolved"
                                class="text-sm leading-5 font-normal"
                                >Mark the recorded issue resolved.</Label
                            >
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                v-if="
                                    order.shipment_actions
                                        ?.can_update_shipment_exception &&
                                    !shipmentExceptionActive &&
                                    !shipmentExceptionEditing
                                "
                                type="button"
                                variant="outline"
                                @click="startShipmentException"
                            >
                                Record shipping issue
                            </Button>
                            <Button
                                v-else-if="
                                    order.shipment_actions
                                        ?.can_update_shipment_exception &&
                                    shipmentExceptionEditing
                                "
                                type="button"
                                :disabled="
                                    shipmentOperationsForm.processing ||
                                    (shipmentExceptionActive &&
                                        !shipmentOperationsForm.shipment_exception_resolved)
                                "
                                @click="submitShipmentOperations('exception')"
                            >
                                {{
                                    shipmentExceptionActive
                                        ? 'Resolve shipping issue'
                                        : 'Save shipping issue'
                                }}
                            </Button>
                        </div>
                    </div>
                    <InputError
                        :message="shipmentOperationsForm.errors.shipment"
                    />
                </section>
                <section
                    v-if="
                        canProcess &&
                        order.payment.method.value === 'cod' &&
                        order.shipment?.carrier_cod_settlement
                    "
                    class="border-border bg-card grid gap-3 border p-5"
                    aria-labelledby="lbc-cod-settlement-heading"
                >
                    <h2 id="lbc-cod-settlement-heading" class="font-semibold">
                        LBC COD settlement record
                    </h2>
                    <p class="text-muted-foreground text-sm">
                        LBC collects the full saved order total from the buyer.
                        Reconcile the same amount when recording seller
                        remittance.
                    </p>
                    <div class="grid gap-1 text-sm">
                        <p>
                            Collection:
                            {{
                                order.shipment.carrier_cod_settlement
                                    .collected_at
                                    ? formatCurrency(
                                          order.shipment.carrier_cod_settlement
                                              .collected_amount,
                                      )
                                    : 'Awaiting confirmation'
                            }}
                        </p>
                        <p
                            v-if="
                                order.shipment.carrier_cod_settlement
                                    .collection_reference
                            "
                            class="text-muted-foreground"
                        >
                            Collection reference:
                            {{
                                order.shipment.carrier_cod_settlement
                                    .collection_reference
                            }}
                        </p>
                        <p>
                            Remittance:
                            {{
                                order.shipment.carrier_cod_settlement
                                    .remitted_at
                                    ? formatCurrency(
                                          order.shipment.carrier_cod_settlement
                                              .remitted_amount,
                                      )
                                    : 'Awaiting reconciliation'
                            }}
                        </p>
                        <p
                            v-if="
                                order.shipment.carrier_cod_settlement
                                    .remittance_reference
                            "
                            class="text-muted-foreground"
                        >
                            Remittance reference:
                            {{
                                order.shipment.carrier_cod_settlement
                                    .remittance_reference
                            }}
                        </p>
                    </div>
                </section>
                <section
                    v-if="
                        order.shipment &&
                        canProcess &&
                        (order.shipment_actions
                            ?.can_simulate_local_lbc_booking ||
                            order.shipment.booking_simulation)
                    "
                    class="border-border bg-card grid gap-3 border p-5"
                    aria-labelledby="local-lbc-simulation-heading"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h2
                                id="local-lbc-simulation-heading"
                                class="font-semibold"
                            >
                                LBC booking simulation
                            </h2>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Local workflow check only. This does not contact
                                LBC or create a carrier shipment.
                            </p>
                        </div>
                        <Button
                            v-if="
                                order.shipment_actions
                                    ?.can_simulate_local_lbc_booking
                            "
                            type="button"
                            variant="outline"
                            @click="requestLocalLbcBookingSimulation"
                        >
                            Simulate local booking
                        </Button>
                    </div>
                    <div
                        v-if="order.shipment.booking_simulation"
                        class="border-border bg-secondary/40 grid gap-1 border p-4 text-sm"
                        role="status"
                    >
                        <p class="font-medium">
                            {{ order.shipment.booking_simulation.label }}
                        </p>
                        <p class="text-muted-foreground">
                            Reference:
                            {{ order.shipment.booking_simulation.reference }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ order.shipment.booking_simulation.notice }}
                        </p>
                    </div>
                    <InputError :message="localLbcBookingForm.errors.booking" />
                    <Dialog v-model:open="localLbcBookingDialogOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle
                                    >Run local booking simulation?</DialogTitle
                                >
                                <DialogDescription>
                                    Battlefront will save a synthetic reference
                                    for this eligible prepaid order. No LBC
                                    request, carrier booking, tracking number,
                                    payment change, or customer notification
                                    will be created.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="outline"
                                        >Keep shipment unchanged</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="button"
                                    :disabled="localLbcBookingForm.processing"
                                    @click="submitLocalLbcBookingSimulation"
                                >
                                    <Spinner
                                        v-if="localLbcBookingForm.processing"
                                    />
                                    Run simulation
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
                <div
                    v-if="
                        order.delivery_quote?.delivery_arrangement_required &&
                        !order.shipment
                    "
                    class="border-border bg-secondary/40 grid gap-3 border p-5 text-sm"
                    role="status"
                >
                    <p class="font-semibold">
                        <template
                            v-if="
                                order.delivery_quote.pricing_status ===
                                'regional_estimate'
                            "
                        >
                            Regional delivery estimate ·
                            {{ order.delivery_quote.destination }}
                        </template>
                        <template v-else>No configured delivery rate</template>
                    </p>
                    <p
                        v-if="
                            order.delivery_quote.pricing_status ===
                            'regional_estimate'
                        "
                        class="text-muted-foreground leading-6"
                    >
                        The saved regional delivery fee is included in the order
                        total. Confirm the actual delivery arrangement and ETA
                        before creating the manual shipment. The saved order
                        amount cannot be repriced.
                    </p>
                    <p v-else class="text-muted-foreground leading-6">
                        This order remains at its saved product subtotal with no
                        delivery fee included. This does not guarantee free
                        delivery. No LBC quote, booking, or estimate is implied.
                        Confirming a delivery arrangement will not change the
                        saved order amount or add a fee.
                    </p>
                    <p class="text-muted-foreground text-xs leading-5">
                        {{ order.delivery_quote.assumption_label }}
                    </p>
                    <div v-if="order.can_create_manual_shipment && canProcess">
                        <Button type="button" @click="requestManualShipment">
                            <Truck />
                            Confirm delivery arrangement
                        </Button>
                        <Dialog v-model:open="manualShipmentDialogOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Confirm delivery arrangement
                                    </DialogTitle>
                                    <DialogDescription>
                                        Confirm an LBC arrangement already made
                                        outside checkout. The order total stays
                                        unchanged.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-4">
                                    <div class="grid gap-2">
                                        <Label for="manual-shipment-carrier">
                                            Carrier
                                        </Label>
                                        <Input
                                            id="manual-shipment-carrier"
                                            model-value="LBC"
                                            readonly
                                        />
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .carrier
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="manual-tracking-reference">
                                            Tracking reference (optional)
                                        </Label>
                                        <Input
                                            id="manual-tracking-reference"
                                            v-model="
                                                manualShipmentForm.tracking_reference
                                            "
                                            maxlength="255"
                                            placeholder="Enter only a reference actually supplied by LBC"
                                        />
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .tracking_reference
                                            "
                                        />
                                    </div>
                                    <fieldset class="grid gap-2">
                                        <legend class="text-sm font-medium">
                                            Estimated delivery days (optional)
                                        </legend>
                                        <p
                                            class="text-muted-foreground text-xs leading-5"
                                        >
                                            Days from preparation. Leave both
                                            blank when unknown.
                                        </p>
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div class="grid gap-2">
                                                <Label for="manual-eta-min">
                                                    Minimum days
                                                </Label>
                                                <Input
                                                    id="manual-eta-min"
                                                    v-model="
                                                        manualShipmentForm.eta_min_days
                                                    "
                                                    type="number"
                                                    min="0"
                                                    max="3650"
                                                    step="1"
                                                />
                                                <InputError
                                                    :message="
                                                        manualShipmentForm
                                                            .errors.eta_min_days
                                                    "
                                                />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label for="manual-eta-max">
                                                    Maximum days
                                                </Label>
                                                <Input
                                                    id="manual-eta-max"
                                                    v-model="
                                                        manualShipmentForm.eta_max_days
                                                    "
                                                    type="number"
                                                    min="0"
                                                    max="3650"
                                                    step="1"
                                                />
                                                <InputError
                                                    :message="
                                                        manualShipmentForm
                                                            .errors.eta_max_days
                                                    "
                                                />
                                            </div>
                                        </div>
                                    </fieldset>
                                    <div class="grid gap-2">
                                        <div class="flex items-start gap-3">
                                            <Checkbox
                                                id="manual-arrangement-confirmed"
                                                v-model="
                                                    manualShipmentForm.arrangement_confirmed
                                                "
                                            />
                                            <Label
                                                for="manual-arrangement-confirmed"
                                                class="text-sm leading-5 font-normal"
                                            >
                                                I confirm that the delivery
                                                arrangement with LBC has
                                                actually been made.
                                            </Label>
                                        </div>
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .arrangement_confirmed
                                            "
                                        />
                                    </div>
                                </div>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        :disabled="
                                            !manualShipmentForm.arrangement_confirmed ||
                                            !manualShipmentForm.carrier ||
                                            manualShipmentForm.processing
                                        "
                                        @click="submitManualShipment"
                                    >
                                        <Spinner
                                            v-if="manualShipmentForm.processing"
                                        />
                                        Confirm delivery arrangement
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        Manual shipment setup is unavailable for this order's
                        current status or payment state.
                    </p>
                </div>
                <section aria-labelledby="processing-details-heading">
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            Order details at purchase
                        </p>
                        <h2
                            id="processing-details-heading"
                            class="text-xl font-semibold"
                        >
                            Processing details
                        </h2>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <div class="grid md:grid-cols-2 md:divide-x">
                            <div class="flex gap-3 p-5">
                                <UserRound
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div class="min-w-0">
                                    <p class="text-muted-foreground text-sm">
                                        Customer account
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.customer.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-sm"
                                    >
                                        {{ order.customer.email }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-3 p-5">
                                <Truck
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Fulfillment
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.fulfillment.label }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="grid md:grid-cols-2 md:divide-x">
                            <div class="flex gap-3 p-5">
                                <PackageCheck
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Recipient details at purchase
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.recipient.name }}
                                    </p>
                                    <p class="text-muted-foreground text-sm">
                                        {{ order.recipient.contact_number }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-3 p-5">
                                <MapPin
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Delivery address
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{
                                            order.fulfillment
                                                .delivery_address ??
                                            'Not applicable for store pickup'
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            order.fulfillment.value ===
                                                'delivery' &&
                                            order.fulfillment
                                                .delivery_destination
                                        "
                                        class="text-muted-foreground mt-1 text-sm"
                                    >
                                        {{
                                            order.fulfillment
                                                .delivery_destination
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="order-items-heading">
                    <div
                        class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <p class="text-muted-foreground text-sm">
                                {{ order.total_quantity }} units across
                                {{ order.item_count }} lines
                            </p>
                            <h2
                                id="order-items-heading"
                                class="text-xl font-semibold"
                            >
                                Purchase-time item ledger
                            </h2>
                        </div>
                        <dl
                            class="grid grid-cols-3 gap-4 text-sm sm:text-right"
                        >
                            <div>
                                <dt class="text-muted-foreground">
                                    Product subtotal
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{
                                        order.product_subtotal === null
                                            ? 'Not recorded'
                                            : formatCurrency(
                                                  order.product_subtotal,
                                              )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Delivery fee
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{ formatCurrency(order.delivery_fee) }}
                                    <p
                                        v-if="
                                            order.delivery_quote
                                                ?.billable_weight_kg
                                        "
                                        class="text-muted-foreground mt-1 text-xs font-normal"
                                    >
                                        Route
                                        {{
                                            formatCurrency(
                                                order.delivery_quote
                                                    .route_base_fee,
                                            )
                                        }}
                                        + weight
                                        {{
                                            formatCurrency(
                                                order.delivery_quote
                                                    .weight_surcharge,
                                            )
                                        }}
                                        + handling
                                        {{
                                            formatCurrency(
                                                order.delivery_quote
                                                    .handling_surcharge,
                                            )
                                        }}
                                        ·
                                        {{
                                            order.delivery_quote
                                                .billable_weight_kg
                                        }}
                                        kg billable
                                    </p>
                                    <p
                                        v-if="
                                            order.delivery_quote
                                                ?.fixed_delivery_fee
                                        "
                                        class="text-muted-foreground mt-1 text-xs font-normal"
                                    >
                                        Customer fee fixed at placement;
                                        Battlefront absorbs any courier cost
                                        difference.
                                    </p>
                                    <p
                                        v-if="
                                            order.delivery_quote
                                                ?.pricing_status ===
                                            'not_configured'
                                        "
                                        class="text-muted-foreground mt-1 text-xs font-normal"
                                    >
                                        No delivery fee included; this does not
                                        guarantee free delivery.
                                    </p>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Final total
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{ formatCurrency(order.total) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <article
                            v-for="item in order.items"
                            :key="item.id"
                            class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-4">
                                <div
                                    class="bg-secondary size-14 shrink-0 overflow-hidden rounded-md"
                                >
                                    <img
                                        v-if="item.product.image_url"
                                        :src="item.product.image_url"
                                        :alt="item.product.name"
                                        class="size-full object-cover"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p
                                        v-if="item.pc_build_group"
                                        class="text-primary-ink text-xs font-semibold tracking-wide uppercase"
                                    >
                                        PC build ·
                                        {{ item.pc_build_group.name }}
                                    </p>
                                    <h3 class="truncate font-semibold">
                                        {{ item.product.name }}
                                    </h3>
                                    <p class="text-muted-foreground text-sm">
                                        {{ item.product.brand }}
                                    </p>
                                    <p
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ item.quantity }} ×
                                        {{ formatCurrency(item.unit_price) }}
                                    </p>
                                </div>
                            </div>
                            <p class="font-semibold tabular-nums sm:text-right">
                                {{ formatCurrency(item.line_total) }}
                            </p>
                        </article>
                    </div>
                    <dl
                        v-if="order.profitability"
                        class="border-border mt-4 grid gap-3 border p-4 sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Cost of goods sold
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        order.profitability.cost_of_goods_sold,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Sales returns
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        order.profitability.sales_returns,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Gross profit
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    order.profitability.available
                                        ? formatCurrency(
                                              order.profitability.gross_profit,
                                          )
                                        : 'Unavailable'
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside class="flex flex-col gap-6" aria-label="Order actions">
                <section
                    v-if="order.shipment"
                    class="border-border bg-card grid gap-4 border p-5"
                    aria-label="Shipment actions"
                >
                    <h2 class="font-semibold">Manual shipment actions</h2>
                    <template
                        v-if="canProcess && order.shipment_actions?.next_status"
                    >
                        <div
                            v-if="
                                order.shipment_actions.next_status.value ===
                                    'handed_to_lbc' &&
                                !order.shipment.tracking_reference
                            "
                            class="grid gap-2"
                        >
                            <Label for="handoff-reference"
                                >Real LBC tracking / reference (optional)</Label
                            >
                            <Input
                                id="handoff-reference"
                                v-model="shipmentStatusForm.tracking_reference"
                                maxlength="255"
                            />
                            <p class="text-muted-foreground text-xs">
                                Leave blank unless LBC has supplied a real
                                value.
                            </p>
                            <InputError
                                :message="
                                    shipmentStatusForm.errors.tracking_reference
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            :disabled="
                                shipmentStatusForm.processing ||
                                shipmentReferenceForm.processing
                            "
                            @click="requestShipmentUpdate"
                        >
                            <Spinner v-if="shipmentStatusForm.processing" />
                            Mark {{ order.shipment_actions.next_status.label }}
                        </Button>
                    </template>
                    <p
                        v-else-if="
                            canProcess &&
                            ['pending', 'processing'].includes(
                                order.status.value,
                            ) &&
                            !['delivered', 'cancelled'].includes(
                                order.shipment.status.value,
                            )
                        "
                        class="text-muted-foreground text-sm leading-5"
                    >
                        {{
                            order.payment.method.value === 'cod'
                                ? 'Move the COD order to Processing before advancing shipment milestones. Payment remains Pending until collection is confirmed after delivery.'
                                : 'Verify payment and move the order to Processing before advancing shipment milestones.'
                        }}
                    </p>
                    <p
                        v-else-if="canProcess || !unfinished"
                        class="text-muted-foreground text-sm"
                    >
                        Shipment progression is closed for this order.
                    </p>
                    <InputError :message="shipmentStatusForm.errors.status" />

                    <form
                        v-if="
                            canProcess &&
                            order.shipment_actions?.can_update_reference
                        "
                        class="border-border grid gap-3 border-t pt-4"
                        @submit.prevent="submitShipmentReference"
                    >
                        <Label for="shipment-reference"
                            >LBC tracking / reference</Label
                        >
                        <Input
                            id="shipment-reference"
                            v-model="shipmentReferenceForm.tracking_reference"
                            maxlength="255"
                        />
                        <p class="text-muted-foreground text-xs">
                            Enter only a supplied reference. Once saved, the LBC
                            tracking number cannot be changed.
                        </p>
                        <InputError
                            :message="
                                shipmentReferenceForm.errors.tracking_reference
                            "
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="
                                shipmentReferenceForm.processing ||
                                shipmentStatusForm.processing
                            "
                        >
                            <Spinner v-if="shipmentReferenceForm.processing" />
                            Save reference
                        </Button>
                    </form>

                    <Dialog v-model:open="shipmentConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle
                                    >Mark
                                    {{ order.reference }}
                                    delivered?</DialogTitle
                                >
                                <DialogDescription>{{
                                    order.payment.method.value === 'cod'
                                        ? 'Confirm the delivery has finished. Payment remains Pending and the order remains Processing until COD collection is manually confirmed. No sale is recorded yet.'
                                        : 'Confirm the delivery has finished. This completes the order and records its sale.'
                                }}</DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child
                                    ><Button type="button" variant="outline"
                                        >Keep out for delivery</Button
                                    ></DialogClose
                                >
                                <Button
                                    type="button"
                                    :disabled="shipmentStatusForm.processing"
                                    @click="submitShipmentStatus"
                                >
                                    <Spinner
                                        v-if="shipmentStatusForm.processing"
                                    />
                                    Confirm delivered
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
                <section class="border-border bg-card border p-5">
                    <div class="flex items-start gap-3">
                        <ShieldCheck
                            class="text-primary mt-0.5 size-5 shrink-0"
                        />
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Current order status
                            </p>
                            <p class="font-semibold">
                                {{ order.status.label }}
                            </p>
                        </div>
                    </div>

                    <p
                        v-if="
                            order.cancellation_blocked_reason &&
                            ['pending', 'processing'].includes(
                                order.status.value,
                            )
                        "
                        class="text-muted-foreground mt-4 text-sm leading-5"
                    >
                        {{ order.cancellation_blocked_reason }}
                    </p>
                    <div
                        v-if="
                            canProcess &&
                            order.allowed_status_transitions.length
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <div class="grid gap-2">
                            <Label for="order-status">Move order to</Label>
                            <Select
                                :model-value="selectedOrderStatus"
                                @update:model-value="selectOrderStatus"
                            >
                                <SelectTrigger id="order-status" class="w-full">
                                    <SelectValue
                                        placeholder="Select next status"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="transition in order.allowed_status_transitions"
                                        :key="transition.value"
                                        :value="transition.value"
                                    >
                                        {{ transition.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="
                                    ['pending', 'processing'].includes(
                                        order.status.value,
                                    ) &&
                                    order.payment.method.value !== 'cod' &&
                                    !order.payment.pay_at_pickup &&
                                    order.payment.status.value !== 'verified'
                                "
                                class="text-muted-foreground text-xs leading-5"
                            >
                                Verify payment before moving this order to
                                {{
                                    order.status.value === 'pending'
                                        ? 'Processing'
                                        : 'Completed'
                                }}.<template
                                    v-if="
                                        order.allowed_status_transitions.some(
                                            (transition) =>
                                                transition.value ===
                                                'cancelled',
                                        )
                                    "
                                >
                                    Cancellation remains available.</template
                                >
                            </p>
                            <InputError
                                :message="orderStatusForm.errors.status"
                            />
                            <InputError
                                :message="
                                    orderStatusForm.errors
                                        .products_handed_over_confirmed
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            class="w-full"
                            :disabled="
                                !selectedOrderStatus ||
                                orderStatusForm.processing
                            "
                            @click="requestOrderStatusUpdate"
                        >
                            <Spinner v-if="orderStatusForm.processing" />
                            Update order status
                        </Button>
                    </div>
                    <p
                        v-else-if="canProcess || !unfinished"
                        class="text-muted-foreground mt-4 text-sm"
                    >
                        <template
                            v-if="
                                ['completed', 'cancelled'].includes(
                                    order.status.value,
                                )
                            "
                        >
                            This order is in a terminal state and cannot be
                            changed.
                        </template>
                        <template
                            v-else-if="
                                order.payment.method.value === 'cod' &&
                                order.payment.status.value === 'pending'
                            "
                        >
                            <template
                                v-if="order.payment.can_confirm_cod_collection"
                            >
                                Delivery is recorded. Record LBC collection
                                below; payment remains pending until remittance
                                is reconciled.
                            </template>
                            <template
                                v-else-if="
                                    order.payment.can_confirm_cod_remittance
                                "
                            >
                                LBC collection is recorded. Reconcile seller
                                remittance below to complete the order.
                            </template>
                            <template v-else>
                                Continue the delivery milestones. Delivery alone
                                does not confirm collection or remittance.
                            </template>
                        </template>
                        <template v-else-if="order.payment.pay_at_pickup">
                            Confirm payment and product handover below to
                            complete pickup.
                        </template>
                        <template v-else>
                            Continue delivery using the shipment milestones
                            above.
                        </template>
                    </p>

                    <Dialog v-model:open="orderConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {{ orderConfirmationTitle }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ orderConfirmationDescription }}
                                </DialogDescription>
                            </DialogHeader>
                            <div
                                class="flex items-start gap-3 border p-3 text-sm"
                                :class="
                                    selectedOrderStatus === 'completed'
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200'
                                        : 'border-destructive/40 bg-destructive/10 text-destructive'
                                "
                            >
                                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                                <p class="leading-5 font-medium">
                                    {{
                                        selectedOrderStatus === 'completed'
                                            ? 'Completed is a terminal order status and cannot be undone.'
                                            : 'Cancelled is a terminal order status and cannot be undone.'
                                    }}
                                </p>
                            </div>
                            <label
                                v-if="
                                    selectedOrderStatus === 'completed' &&
                                    order.fulfillment.value === 'pickup'
                                "
                                class="flex items-start gap-2 text-sm"
                            >
                                <input
                                    v-model="
                                        orderStatusForm.products_handed_over_confirmed
                                    "
                                    type="checkbox"
                                />
                                I confirm that the products were physically
                                handed over to the customer.
                            </label>
                            <InputError
                                :message="
                                    orderStatusForm.errors
                                        .products_handed_over_confirmed
                                "
                            />
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="hover:bg-muted hover:text-foreground"
                                    >
                                        Keep current status
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    :variant="
                                        selectedOrderStatus === 'cancelled'
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :class="
                                        selectedOrderStatus === 'completed'
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500/40 dark:bg-emerald-600 dark:hover:bg-emerald-500'
                                            : 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40 dark:bg-red-700 dark:hover:bg-red-600'
                                    "
                                    :disabled="orderStatusForm.processing"
                                    @click="submitOrderStatus"
                                >
                                    <Spinner
                                        v-if="orderStatusForm.processing"
                                    />
                                    Confirm {{ selectedOrderTransition?.label }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>

                <section class="border-border bg-card border p-5">
                    <div class="flex items-start gap-3">
                        <Banknote class="text-primary mt-0.5 size-5 shrink-0" />
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Payment method
                            </p>
                            <p class="font-semibold">
                                {{ order.payment.method.label }}
                            </p>
                            <Badge
                                variant="outline"
                                class="mt-2"
                                :class="
                                    orderStatusBadgeClass(
                                        order.payment.status.value,
                                    )
                                "
                            >
                                {{ order.payment.status.label }}
                            </Badge>
                        </div>
                    </div>

                    <div
                        v-if="order.payment.gateway"
                        class="border-border mt-5 border-t pt-5"
                    >
                        <p class="text-sm font-medium">Online payment</p>
                        <dl
                            class="text-muted-foreground mt-3 grid gap-2 text-sm"
                        >
                            <div class="flex justify-between gap-4">
                                <dt>Provider flow</dt>
                                <dd class="text-foreground text-right">
                                    {{
                                        order.payment.gateway.channel ===
                                        'hosted_checkout'
                                            ? 'Hosted Checkout'
                                            : 'Direct Online Banking'
                                    }}
                                </dd>
                            </div>
                            <div
                                v-if="order.payment.gateway.bank"
                                class="flex justify-between gap-4"
                            >
                                <dt>Bank</dt>
                                <dd class="text-foreground uppercase">
                                    {{ order.payment.gateway.bank }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Reconciliation status</dt>
                                <dd class="text-foreground">
                                    {{
                                        order.payment.gateway.status.replaceAll(
                                            '_',
                                            ' ',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Merchant cutoff</dt>
                                <dd class="text-foreground text-right">
                                    {{
                                        formatDateTime(
                                            order.payment.gateway
                                                .merchant_cutoff_at,
                                        )
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="
                                [
                                    'closing',
                                    'failed',
                                    'retrying',
                                    'reconciliation_required',
                                ].includes(order.payment.gateway.status)
                            "
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            Provider confirmation is still required. Keep this
                            order pending and inventory reserved until
                            reconciliation completes.
                        </p>
                    </div>

                    <div
                        v-if="order.payment.method.requires_proof"
                        class="border-border mt-5 border-t pt-5"
                    >
                        <p class="text-sm font-medium">Submitted evidence</p>
                        <template v-if="order.payment.proof_available">
                            <a
                                :href="
                                    OrderPaymentProofController(order.id).url
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="border-border bg-secondary mt-3 block overflow-hidden border"
                            >
                                <img
                                    :src="
                                        OrderPaymentProofController(order.id)
                                            .url
                                    "
                                    :alt="`Payment proof for ${order.reference}`"
                                    class="max-h-80 w-full object-contain"
                                />
                            </a>
                            <Button
                                variant="outline"
                                size="sm"
                                class="mt-3"
                                as-child
                            >
                                <a
                                    :href="
                                        OrderPaymentProofController(order.id)
                                            .url
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Open full proof
                                    <ExternalLink />
                                </a>
                            </Button>
                        </template>
                        <div
                            v-else
                            class="border-destructive/40 text-destructive mt-3 flex gap-2 border p-3 text-sm"
                        >
                            <CircleAlert class="mt-0.5 size-4 shrink-0" />
                            <p>
                                {{
                                    order.payment.proof_submitted
                                        ? 'The recorded proof file is unavailable.'
                                        : 'No payment proof was submitted.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="
                            canProcess &&
                            order.payment.pay_at_pickup &&
                            !['completed', 'cancelled'].includes(
                                order.status.value,
                            )
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <p class="font-semibold">
                            Payment and pickup confirmation
                        </p>
                        <p class="text-muted-foreground text-sm leading-6">
                            {{ order.payment.method.description }}
                            Move the order to Processing to prepare it. Confirm
                            payment and product handover together when the
                            customer collects it. This completes pickup and
                            records the sale once.
                        </p>
                        <p
                            v-if="order.payment.status.value === 'verified'"
                            class="text-muted-foreground text-sm"
                        >
                            Payment is already verified. Confirm product
                            handover to complete pickup.
                        </p>
                        <Button
                            v-if="
                                canProcess && order.payment.can_confirm_pickup
                            "
                            type="button"
                            class="w-full whitespace-normal"
                            :disabled="pickupConfirmationForm.processing"
                            @click="requestPickupConfirmation"
                        >
                            Confirm Payment &amp; Complete Pickup
                        </Button>
                        <p v-else class="text-muted-foreground text-sm">
                            Confirmation requires an eligible Processing pickup
                            order.
                        </p>
                        <InputError
                            :message="
                                pickupConfirmationForm.errors
                                    .payment_received_confirmed
                            "
                        />
                        <InputError
                            :message="
                                pickupConfirmationForm.errors
                                    .products_handed_over_confirmed
                            "
                        />
                        <Dialog v-model:open="pickupConfirmationOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle
                                        >Confirm Payment &amp; Complete
                                        Pickup</DialogTitle
                                    >
                                    <DialogDescription>
                                        Confirm payment of
                                        {{ formatCurrency(order.total) }} and
                                        product handover for
                                        {{ order.reference }}. This verifies
                                        payment, completes pickup, and records
                                        its sale.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="pickup-payment-confirmed"
                                        v-model="
                                            pickupConfirmationForm.payment_received_confirmed
                                        "
                                    />
                                    <Label
                                        for="pickup-payment-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        {{
                                            order.payment.method.value ===
                                            'cash'
                                                ? 'I confirm that the full cash payment was received.'
                                                : 'I confirm that the physical debit/credit card terminal transaction succeeded for the full amount.'
                                        }}
                                    </Label>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="pickup-handover-confirmed"
                                        v-model="
                                            pickupConfirmationForm.products_handed_over_confirmed
                                        "
                                    />
                                    <Label
                                        for="pickup-handover-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        I confirm that the products were handed
                                        over to the customer.
                                    </Label>
                                </div>
                                <InputError
                                    :message="
                                        pickupConfirmationForm.errors
                                            .payment_received_confirmed
                                    "
                                />
                                <InputError
                                    :message="
                                        pickupConfirmationForm.errors
                                            .products_handed_over_confirmed
                                    "
                                />
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline"
                                            >Keep preparing pickup</Button
                                        >
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        class="whitespace-normal"
                                        :disabled="
                                            !pickupConfirmationForm.payment_received_confirmed ||
                                            !pickupConfirmationForm.products_handed_over_confirmed ||
                                            pickupConfirmationForm.processing
                                        "
                                        @click="submitPickupConfirmation"
                                    >
                                        <Spinner
                                            v-if="
                                                pickupConfirmationForm.processing
                                            "
                                        />
                                        Confirm Payment &amp; Complete Pickup
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <div
                        v-else-if="
                            canProcess &&
                            order.payment.method.value === 'cod' &&
                            order.payment.status.value === 'pending' &&
                            order.status.value !== 'cancelled'
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <p class="font-semibold">COD — Awaiting Collection</p>
                        <p class="text-muted-foreground text-sm leading-6">
                            LBC collection and seller remittance are recorded
                            separately. The order remains unpaid until the full
                            saved order total is reconciled as remitted.
                        </p>
                        <div
                            v-if="
                                order.shipment?.carrier_cod_settlement
                                    ?.collected_at
                            "
                            class="border-border bg-secondary/40 grid gap-1 border p-4 text-sm"
                        >
                            <p>
                                Collected
                                {{
                                    formatCurrency(
                                        order.shipment.carrier_cod_settlement
                                            .collected_amount,
                                    )
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                {{
                                    formatDateTime(
                                        order.shipment.carrier_cod_settlement
                                            .collected_at,
                                    )
                                }}
                                <span
                                    v-if="
                                        order.shipment.carrier_cod_settlement
                                            .collection_reference
                                    "
                                >
                                    ·
                                    {{
                                        order.shipment.carrier_cod_settlement
                                            .collection_reference
                                    }}
                                </span>
                            </p>
                        </div>
                        <Button
                            v-if="
                                canProcess &&
                                order.payment.can_confirm_cod_collection
                            "
                            type="button"
                            :disabled="codCollectionForm.processing"
                            @click="requestCodConfirmation"
                        >
                            Record LBC COD Collection
                        </Button>
                        <Button
                            v-if="order.payment.can_confirm_cod_remittance"
                            type="button"
                            variant="outline"
                            :disabled="codRemittanceForm.processing"
                            @click="requestCodRemittanceConfirmation"
                        >
                            Record LBC Remittance
                        </Button>
                        <div
                            v-if="
                                order.shipment?.carrier_cod_settlement
                                    ?.remitted_at
                            "
                            class="border-border bg-secondary/40 grid gap-1 border p-4 text-sm"
                            role="status"
                        >
                            <p>
                                Remitted
                                {{
                                    formatCurrency(
                                        order.shipment.carrier_cod_settlement
                                            .remitted_amount,
                                    )
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                {{
                                    order.shipment.carrier_cod_settlement
                                        .remittance_reference
                                }}
                                ·
                                {{
                                    formatDateTime(
                                        order.shipment.carrier_cod_settlement
                                            .remitted_at,
                                    )
                                }}
                            </p>
                        </div>
                        <p
                            v-if="
                                !order.shipment?.carrier_cod_settlement
                                    ?.collected_at &&
                                !order.payment.can_confirm_cod_collection
                            "
                            class="text-muted-foreground text-sm"
                        >
                            Collection confirmation becomes available after the
                            shipment reaches Delivered.
                        </p>
                        <p class="text-muted-foreground text-xs leading-5">
                            Handle failed delivery, unpaid orders, and returns
                            manually. No automatic cancellation, refund, return,
                            or stock restoration occurs. No live LBC settlement
                            is verified.
                        </p>
                        <Dialog v-model:open="codConfirmationOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Confirm COD Collection for
                                        {{ order.reference }}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        Confirm that you manually verified LBC
                                        collected
                                        {{ formatCurrency(order.total) }}. The
                                        order stays unpaid until the seller
                                        remittance is reconciled.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="cod-collection-confirmed"
                                        v-model="
                                            codCollectionForm.cod_collection_confirmed
                                        "
                                    />
                                    <Label
                                        for="cod-collection-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        I manually verified that LBC collected
                                        the full order total.
                                    </Label>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="cod-collection-reference"
                                        >LBC collection reference
                                        (optional)</Label
                                    >
                                    <Input
                                        id="cod-collection-reference"
                                        v-model="
                                            codCollectionForm.carrier_cod_collection_reference
                                        "
                                        maxlength="100"
                                    />
                                    <InputError
                                        :message="
                                            codCollectionForm.errors
                                                .carrier_cod_collection_reference
                                        "
                                    />
                                </div>
                                <InputError
                                    :message="
                                        codCollectionForm.errors
                                            .cod_collection_confirmed
                                    "
                                />
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">
                                            Keep awaiting collection
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        :disabled="
                                            !codCollectionForm.cod_collection_confirmed ||
                                            codCollectionForm.processing
                                        "
                                        @click="submitCodCollection"
                                    >
                                        <Spinner
                                            v-if="codCollectionForm.processing"
                                        />
                                        Record Collection
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        <Dialog v-model:open="codRemittanceConfirmationOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle
                                        >Reconcile LBC COD remittance for
                                        {{ order.reference }}?</DialogTitle
                                    >
                                    <DialogDescription>
                                        Confirm LBC remitted the full saved
                                        order total of
                                        {{ formatCurrency(order.total) }}. This
                                        verifies payment, completes the order,
                                        and records the sale.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Label for="cod-remittance-reference"
                                        >LBC remittance reference</Label
                                    >
                                    <Input
                                        id="cod-remittance-reference"
                                        v-model="
                                            codRemittanceForm.carrier_cod_remittance_reference
                                        "
                                        maxlength="100"
                                    />
                                    <InputError
                                        :message="
                                            codRemittanceForm.errors
                                                .carrier_cod_remittance_reference
                                        "
                                    />
                                </div>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="cod-remittance-confirmed"
                                        v-model="
                                            codRemittanceForm.cod_remittance_confirmed
                                        "
                                    />
                                    <Label
                                        for="cod-remittance-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        I reconciled the LBC remittance against
                                        the saved order total.
                                    </Label>
                                </div>
                                <InputError
                                    :message="
                                        codRemittanceForm.errors
                                            .cod_remittance_confirmed
                                    "
                                />
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline"
                                            >Keep payment pending</Button
                                        >
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        :disabled="
                                            !codRemittanceForm.cod_remittance_confirmed ||
                                            !codRemittanceForm.carrier_cod_remittance_reference ||
                                            codRemittanceForm.processing
                                        "
                                        @click="submitCodRemittance"
                                    >
                                        <Spinner
                                            v-if="codRemittanceForm.processing"
                                        />
                                        Reconcile and Complete
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <div
                        v-else-if="
                            canProcess &&
                            !order.payment.pay_at_pickup &&
                            order.payment.status.value === 'pending' &&
                            order.status.value !== 'cancelled'
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <div class="text-muted-foreground text-sm leading-6">
                            <p v-if="order.payment.method.requires_proof">
                                Proof is evidence only. Cross-check the
                                transaction in the selected
                                {{ order.payment.method.label }} platform and
                                Battlefront account before verification.
                            </p>
                            <p v-else>
                                Confirm this
                                {{ order.payment.method.label.toLowerCase() }}
                                payment manually at the store before
                                verification.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="payment-status">Payment decision</Label>
                            <Select
                                :model-value="paymentStatusForm.payment_status"
                                @update:model-value="selectPaymentStatus"
                            >
                                <SelectTrigger
                                    id="payment-status"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Select decision"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        value="verified"
                                        :disabled="
                                            order.payment.method
                                                .requires_proof &&
                                            !order.payment.proof_available
                                        "
                                    >
                                        Verified
                                    </SelectItem>
                                    <SelectItem
                                        value="rejected"
                                        :disabled="
                                            order.status.value === 'completed'
                                        "
                                    >
                                        Rejected
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="
                                    paymentStatusForm.errors.payment_status
                                "
                            />
                        </div>

                        <div
                            v-if="
                                paymentStatusForm.payment_status === 'verified'
                            "
                            class="grid gap-3"
                        >
                            <div class="flex items-start gap-3">
                                <Checkbox
                                    id="manual-verification-confirmed"
                                    v-model="
                                        paymentStatusForm.manual_verification_confirmed
                                    "
                                />
                                <Label
                                    for="manual-verification-confirmed"
                                    class="text-sm leading-5 font-normal"
                                >
                                    I manually cross-checked and confirmed this
                                    payment.
                                </Label>
                            </div>
                            <InputError
                                :message="
                                    paymentStatusForm.errors
                                        .manual_verification_confirmed
                                "
                            />
                        </div>

                        <div v-if="rejectingWalletPayment" class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="rejection-reason">
                                    Rejection reason
                                </Label>
                                <Select
                                    v-model="paymentStatusForm.rejection_reason"
                                >
                                    <SelectTrigger
                                        id="rejection-reason"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            placeholder="Select reason"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="reason in order.payment
                                                .rejection_reasons"
                                            :key="reason.value"
                                            :value="reason.value"
                                        >
                                            {{ reason.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        paymentStatusForm.errors
                                            .rejection_reason
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="rejection-note">
                                    Additional note
                                    <span
                                        v-if="
                                            paymentStatusForm.rejection_reason !==
                                            'other'
                                        "
                                        class="text-muted-foreground font-normal"
                                    >
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="rejection-note"
                                    v-model="paymentStatusForm.rejection_note"
                                    maxlength="255"
                                    rows="3"
                                    placeholder="Add short context for the customer"
                                />
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <InputError
                                        :message="
                                            paymentStatusForm.errors
                                                .rejection_note
                                        "
                                    />
                                    <span
                                        class="text-muted-foreground ml-auto text-xs tabular-nums"
                                    >
                                        {{
                                            paymentStatusForm.rejection_note
                                                .length
                                        }}/255
                                    </span>
                                </div>
                            </div>
                        </div>

                        <Button
                            type="button"
                            class="w-full"
                            :disabled="
                                !paymentStatusForm.payment_status ||
                                paymentStatusForm.processing ||
                                (paymentStatusForm.payment_status ===
                                    'verified' &&
                                    (!paymentStatusForm.manual_verification_confirmed ||
                                        (order.payment.method.requires_proof &&
                                            !order.payment.proof_available))) ||
                                (rejectingWalletPayment &&
                                    (!paymentStatusForm.rejection_reason ||
                                        (paymentStatusForm.rejection_reason ===
                                            'other' &&
                                            !paymentStatusForm.rejection_note.trim())))
                            "
                            @click="requestPaymentStatusUpdate"
                        >
                            Apply payment decision
                        </Button>
                    </div>
                    <div v-else class="mt-4 grid gap-3">
                        <p class="text-muted-foreground text-sm">
                            <template
                                v-if="
                                    order.cancellation?.status.value ===
                                    'requested'
                                "
                            >
                                Payment review is paused until the cancellation
                                request is decided.
                            </template>
                            <template
                                v-else-if="order.status.value === 'cancelled'"
                            >
                                Cancelled orders cannot accept payment
                                decisions. Any recorded payment remains
                                unchanged.
                            </template>
                            <template v-else>
                                This payment decision is final for this review.
                            </template>
                        </p>
                        <div
                            v-if="
                                order.payment.status.value === 'rejected' &&
                                order.payment.method.requires_proof
                            "
                            class="border-destructive/40 bg-destructive/10 text-destructive border p-3 text-sm"
                        >
                            <p class="font-semibold">
                                {{
                                    order.payment.rejection?.reason ??
                                    'No rejection reason was recorded.'
                                }}
                            </p>
                            <p
                                v-if="order.payment.rejection?.note"
                                class="mt-1 leading-5"
                            >
                                {{ order.payment.rejection.note }}
                            </p>
                        </div>
                    </div>

                    <Dialog v-model:open="paymentConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {{ paymentConfirmationTitle }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ paymentConfirmationDescription }}
                                </DialogDescription>
                            </DialogHeader>
                            <div
                                class="flex items-start gap-3 border p-3 text-sm"
                                :class="
                                    paymentStatusForm.payment_status ===
                                    'verified'
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200'
                                        : 'border-destructive/40 bg-destructive/10 text-destructive'
                                "
                            >
                                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                                <p class="leading-5 font-medium">
                                    {{
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'Verification is a final payment decision and cannot be undone.'
                                            : 'Rejection is final and does not cancel the order.'
                                    }}
                                </p>
                            </div>
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="hover:bg-muted hover:text-foreground"
                                    >
                                        Keep payment pending
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    :variant="
                                        paymentStatusForm.payment_status ===
                                        'rejected'
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :class="
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500/40 dark:bg-emerald-600 dark:hover:bg-emerald-500'
                                            : 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40 dark:bg-red-700 dark:hover:bg-red-600'
                                    "
                                    :disabled="paymentStatusForm.processing"
                                    @click="submitPaymentStatus"
                                >
                                    <Spinner
                                        v-if="paymentStatusForm.processing"
                                    />
                                    <BadgeCheck
                                        v-else-if="
                                            paymentStatusForm.payment_status ===
                                            'verified'
                                        "
                                    />
                                    <CircleX v-else />
                                    Confirm
                                    {{
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'verification'
                                            : 'rejection'
                                    }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
            </aside>
        </div>
    </main>
</template>
