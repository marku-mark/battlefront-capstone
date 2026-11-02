<script setup>
import OperationalExports from '@/components/administration/OperationalExports.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link, usePage, useForm } from '@inertiajs/vue3';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import AdministrationEvidenceController from '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import InputError from '@/components/InputError.vue';
import RefundAmountFields from '@/components/RefundAmountFields.vue';
import EvidenceFileInput from '@/components/EvidenceFileInput.vue';
import SearchField from '@/components/SearchField.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref, reactive, watch } from 'vue';
import { formatRelativeTime, formatDateTime } from '@/lib/dateTime';

const requestReasonCode = ref('');

const props = defineProps({
    returns: Object,
    customerRequests: Object,
    selected_request: { type: Number, default: null },
    selected_order: Object,
    suppliers: Array,
    refundPayments: Object,
    evidence: Object,
    paymentEvidence: Object,
    selected_case: { type: Number, default: null },
    selected_payment: { type: Number, default: null },
    filters: Object,
    can_receive_returns: { type: Boolean, default: false },
});
const page = usePage();
const receiptRequest = ref(null);
const receiptForm = useForm({ condition: '', files: [] });
function evidenceError(errors) {
    return (
        errors.files ||
        Object.entries(errors).find(([field]) =>
            field.startsWith('files.'),
        )?.[1]
    );
}
function receiveCustomerRequest(entry) {
    if (
        !props.can_receive_returns ||
        !entry.can_receive ||
        receiptForm.processing
    )
        return;
    if (receiptRequest.value !== entry.id) {
        receiptRequest.value = entry.id;
        receiptForm.reset();
        receiptForm.clearErrors();
        return;
    }
    receiptForm.submit(CustomerReturnController.receiveRequest(entry.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            receiptRequest.value = null;
            receiptForm.reset();
        },
    });
}
const returnLabels = {
    pending: 'Awaiting review',
    accepted: 'Accepted',
    rejected_pending_handover: 'Rejected, awaiting return to customer',
    rejected: 'Rejected, returned to customer',
};
const paymentLabels = {
    cash: 'Cash',
    gcash: 'GCash',
    maya: 'Maya',
    bank_transfer: 'Bank transfer',
    other: 'Other',
};
const outcomeLabels = {
    refund: 'Refund',
    replacement: 'Replacement',
    repair: 'Repair',
    other: 'Other outcome',
};
const decisions = reactive({});
const resolutionTypes = reactive({});
const stockDispositions = reactive({});
const paymentMethods = reactive({});
const returnStatusHelp = {
    pending: 'Another administrator must inspect and review this return.',
    accepted: 'Accepted. Check the stock and refund status below.',
    rejected_pending_handover:
        'Rejected items stay on hold until returned to the customer.',
    rejected: 'Rejected and handed back to the customer.',
};
const stockHandling = (entry) => {
    if (entry.status === 'pending') return 'Held while awaiting a decision';
    if (entry.status === 'rejected_pending_handover')
        return 'Held while awaiting customer handover';
    if (entry.status === 'rejected') return 'Handed back to the customer';
    if (entry.disposition === 'restock') return 'Returned to available stock';
    if (entry.disposition === 'return_to_customer')
        return entry.resolution_completed_at
            ? 'Original item handed back'
            : 'Original item held for customer handback';
    if (entry.disposition === 'write_off') return 'Written off';
    if (entry.disposition === 'supplier_return' && entry.held_quantity > 0)
        return 'Held for supplier return';

    return 'Supplier return: no units on hold';
};
const refundProgress = (entry) => {
    if (!entry.refund_id) return 'No refund approval recorded';
    if (entry.approved_amount === '0.00' || Number(entry.approved_amount) === 0)
        return 'No refund amount approved';
    if (entry.refund_fully_verified) return 'Approved amount fully confirmed';
    if (entry.refund_has_disputed_payment)
        return 'Payment disputed; review required';
    if (entry.refund_has_verified_payment)
        return 'Partly confirmed; balance remains';
    if (entry.refund_has_unreviewed_payment)
        return 'Payment recorded; awaiting review';

    return 'Approved; no payment has been confirmed';
};
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: CustomerReturnController.index,
    query: () => ({
        case: props.selected_case ?? undefined,
        request_id: props.selected_request ?? undefined,
        payment_id: props.selected_payment ?? undefined,
        overdue: props.filters?.overdue ? 1 : undefined,
    }),
});
watch(
    () => props.filters?.q,
    (query) => {
        search.value = query ?? '';
    },
);
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Customer returns',
                href: CustomerReturnController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Customer returns" />
        <p v-if="selected_case" class="text-sm">
            Showing customer return #{{ selected_case }} ·
            <Link
                :href="CustomerReturnController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <PageHeader title="Customer returns" />
        <OperationalExports
            feature="customer-returns"
            :filters="filters"
            :case-id="selected_case"
            :request-id="selected_request"
            :payment-id="selected_payment"
        />
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <SearchField
                id="return-search"
                v-model="search"
                class="min-w-0 flex-1"
                compact
                label="Search returns"
                placeholder="Product, customer, or order"
                :is-searching="isSearching"
                @clear="clearSearch"
            /><Link
                :href="
                    CustomerReturnController.index({
                        query: {
                            case: selected_case ?? undefined,
                            payment_id: selected_payment ?? undefined,
                            q: filters?.q ?? undefined,
                            overdue: filters?.overdue ? undefined : 1,
                        },
                    })
                "
                class="border-input bg-card hover:bg-accent inline-flex min-h-11 shrink-0 items-center justify-center rounded-md border px-3 text-sm font-medium sm:mt-5.5"
            >
                {{ filters?.overdue ? 'All returns' : 'Overdue only' }}
            </Link>
        </div>
        <section
            class="surface-card border-border bg-card space-y-4 border p-5"
            aria-labelledby="customer-requests-heading"
        >
            <h2 id="customer-requests-heading" class="text-lg font-semibold">
                Return requests
            </h2>
            <p class="text-muted-foreground text-sm">
                Requests do not change stock. Confirm receipt only after all
                requested items arrive.
            </p>
            <p v-if="selected_request" class="text-sm">
                Showing request #{{ selected_request }} ·
                <Link
                    :href="CustomerReturnController.index()"
                    class="text-primary-ink underline"
                    >Show all</Link
                >
            </p>
            <p
                v-if="!customerRequests?.data.length"
                class="text-muted-foreground text-sm"
            >
                No requests awaiting items.
            </p>
            <article
                v-for="entry in customerRequests?.data"
                :key="entry.id"
                class="border-border space-y-3 border-t pt-4"
            >
                <h3 class="font-semibold">
                    {{ entry.product_name }} · {{ entry.quantity }}
                    {{ entry.quantity === 1 ? 'unit' : 'units' }}
                </h3>
                <p
                    v-if="entry.eligibility_path === 'manual_review'"
                    class="text-sm font-medium"
                >
                    Eligibility needs review.
                </p>
                <p
                    v-if="entry.eligibility_receipt_at"
                    class="text-muted-foreground text-sm"
                >
                    Purchase received:
                    {{ formatDateTime(entry.eligibility_receipt_at) }} · Review
                    deadline:
                    {{ formatDateTime(entry.eligibility_deadline_at) }}
                </p>
                <p class="text-sm">
                    {{ entry.customer_name }} ·
                    <Link
                        :href="OrderController.show(entry.order_id)"
                        class="text-primary-ink underline"
                        >{{ entry.order_reference }}</Link
                    >
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ entry.reason_label }} · Requested
                    <span :title="formatDateTime(entry.created_at)">
                        {{ formatRelativeTime(entry.created_at) }}
                    </span>
                </p>
                <p
                    v-if="entry.reason"
                    class="text-sm wrap-anywhere whitespace-pre-wrap"
                >
                    {{ entry.reason }}
                </p>
                <Link
                    v-if="entry.customer_return_id"
                    :href="
                        CustomerReturnController.index({
                            query: { case: entry.customer_return_id },
                        })
                    "
                    class="text-primary-ink text-sm underline"
                    >View received return #{{ entry.customer_return_id }}</Link
                >
                <p
                    v-else-if="entry.withdrawn"
                    class="text-muted-foreground text-sm"
                >
                    Withdrawn by customer
                </p>
                <form
                    v-else-if="
                        entry.can_receive && page.props.auth.can.requestReturns
                    "
                    class="space-y-3"
                    @submit.prevent="receiveCustomerRequest(entry)"
                >
                    <template v-if="receiptRequest === entry.id">
                        <EvidenceFileInput
                            :key="entry.id"
                            label="Product photos"
                            images-only
                            :disabled="receiptForm.processing"
                            @update:files="receiptForm.files = $event"
                        />
                        <InputError
                            :message="evidenceError(receiptForm.errors)"
                        />
                        <Label :for="`receipt-condition-${entry.id}`"
                            >Condition received</Label
                        >
                        <select
                            :id="`receipt-condition-${entry.id}`"
                            v-model="receiptForm.condition"
                            required
                            class="border-input bg-background min-h-11 w-full rounded-md border px-3 py-2 sm:max-w-xs"
                        >
                            <option disabled value="">Select condition</option>
                            <option value="sealed">Sealed</option>
                            <option value="opened">Opened</option>
                            <option value="damaged">Damaged</option>
                        </select>
                        <InputError
                            :message="receiptForm.errors.condition"
                        /><InputError
                            :message="receiptForm.errors.quantity"
                        /><InputError
                            :message="receiptForm.errors.order_item_id"
                        /><InputError
                            :message="receiptForm.errors.return_request"
                        />
                        <p class="text-sm">
                            Confirm that you have physically received all
                            {{ entry.quantity }} requested units. They will be
                            held for review by another employee.
                        </p>
                    </template>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="receiptForm.processing"
                        >{{
                            receiptRequest === entry.id
                                ? 'Confirm receipt'
                                : 'Receive item'
                        }}</Button
                    >
                </form>
            </article>
            <nav
                v-if="customerRequests?.last_page > 1"
                aria-label="Customer requests pages"
                class="flex flex-wrap gap-2"
            >
                <Button
                    v-for="link in customerRequests.links.filter(
                        (entry) => entry.url,
                    )"
                    :key="link.label"
                    variant="outline"
                    size="sm"
                    as-child
                    ><Link
                        :href="link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        ><span v-html="link.label" /></Link
                ></Button>
            </nav>
        </section>
        <p class="text-muted-foreground text-sm" role="note">
            Returned items are held for inspection. Refunds are paid manually
            and confirmed here.
        </p>
        <details
            v-if="can_receive_returns"
            class="surface-card border-border bg-card border p-4 sm:p-5"
            :open="
                Boolean(selected_order) || Boolean(page.props.errors?.order_id)
            "
        >
            <summary
                class="focus-visible:ring-ring min-h-11 cursor-pointer content-center font-medium focus-visible:ring-2 focus-visible:outline-none"
            >
                Receive without a request
            </summary>
            <div class="mt-3 space-y-4">
                <p class="text-muted-foreground text-sm">
                    Items cannot be sold until inspection is complete.
                </p>
                <Form
                    v-bind="CustomerReturnController.index.form()"
                    class="flex max-w-full min-w-0 flex-wrap items-end gap-3"
                    ><div class="space-y-2">
                        <Label for="lookup-order">Order ID or reference</Label
                        ><Input
                            class="min-h-11"
                            id="lookup-order"
                            name="order_id"
                            type="text"
                            :default-value="selected_order?.reference"
                            placeholder="BF-000123"
                            required
                        />
                    </div>
                    <Button class="min-h-11" type="submit" variant="outline"
                        >Find order</Button
                    ></Form
                >
                <Form
                    v-if="selected_order && can_receive_returns"
                    v-bind="CustomerReturnController.store.form()"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-4 sm:grid-cols-2"
                >
                    <p class="text-sm sm:col-span-2">
                        Order {{ selected_order.reference }}
                    </p>
                    <div class="space-y-2 sm:col-span-2">
                        <EvidenceFileInput
                            label="Product photos"
                            images-only
                            :disabled="processing"
                        />
                        <InputError :message="evidenceError(errors)" />
                    </div>
                    <div class="space-y-2">
                        <Label for="return-item">Purchased item</Label
                        ><select
                            id="return-item"
                            name="order_item_id"
                            required
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">Select item</option>
                            <option
                                v-for="item in selected_order.items"
                                :key="item.id"
                                :value="item.id"
                                :disabled="item.remaining_returnable === 0"
                            >
                                {{ item.product }} ·
                                {{ item.remaining_returnable }} of
                                {{ item.quantity }} units still returnable · ₱{{
                                    item.price_at_time
                                }}
                                each
                            </option></select
                        ><InputError :message="errors.order_item_id" />
                    </div>
                    <div class="space-y-2">
                        <Label for="return-units">Units received</Label
                        ><Input
                            class="min-h-11"
                            id="return-units"
                            name="quantity"
                            type="number"
                            min="1"
                            required
                        /><InputError :message="errors.quantity" />
                    </div>
                    <div class="space-y-2">
                        <Label for="return-condition">Condition</Label
                        ><select
                            id="return-condition"
                            name="condition"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="sealed">Sealed</option>
                            <option value="opened">Opened</option>
                            <option value="damaged">Damaged</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <Label for="return-reason-code">Reason</Label
                        ><select
                            id="return-reason-code"
                            name="reason_code"
                            v-model="requestReasonCode"
                            required
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">Select a reason</option>
                            <option value="wrong_item">Wrong item</option>
                            <option value="defective">Defective</option>
                            <option value="transit_damage">
                                Damaged in transit
                            </option>
                            <option value="not_as_described">
                                Not as described
                            </option>
                            <option value="changed_mind">Changed mind</option>
                            <option value="other">Other</option></select
                        ><InputError :message="errors.reason_code" />
                    </div>
                    <div class="space-y-2">
                        <Label for="return-reason">{{
                            requestReasonCode === 'other'
                                ? 'Notes (required)'
                                : 'Notes (optional)'
                        }}</Label
                        ><Input
                            class="min-h-11"
                            id="return-reason"
                            name="reason"
                            :required="requestReasonCode === 'other'"
                            minlength="5"
                            maxlength="2000"
                        /><InputError :message="errors.reason" />
                    </div>
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Receive and hold items</Button
                    >
                </Form>
                <p v-else class="text-muted-foreground text-sm">
                    Find a completed order to select its purchased items.
                </p>
            </div>
        </details>
        <section class="space-y-3" aria-label="Return cases">
            <h2 class="text-lg font-semibold">Received returns</h2>
            <p
                v-if="returns.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No customer returns recorded.
            </p>
            <article
                v-for="entry in returns.data"
                :key="entry.id"
                class="surface-card border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h3 class="font-semibold">
                            {{ entry.product_name }} · {{ entry.quantity }}
                            {{ entry.quantity === 1 ? 'unit' : 'units' }}
                        </h3>
                        <p class="text-muted-foreground text-sm">
                            Customer: {{ entry.customer_name }} · Order
                            <Link
                                :href="
                                    OrderController.show(
                                        entry.purchase_order_id,
                                    )
                                "
                                class="text-primary underline"
                                >#{{ entry.purchase_order_id }}</Link
                            >
                            · {{ entry.condition }}
                        </p>
                        <p class="mt-1 text-sm">
                            {{ entry.reason }} ·
                            <span :title="formatDateTime(entry.created_at)">
                                {{ formatRelativeTime(entry.created_at) }}
                            </span>
                        </p>
                    </div>
                    <span class="text-sm font-semibold capitalize">{{
                        returnLabels[entry.status] ??
                        entry.status.replaceAll('_', ' ')
                    }}</span>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{
                        returnStatusHelp[entry.status] ??
                        'Review the case history and stock or refund details below.'
                    }}
                </p>
                <p
                    v-if="entry.inspection_overdue"
                    class="text-destructive text-sm font-semibold"
                >
                    Return follow-up overdue
                </p>
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <p>
                        <strong>Stock:</strong> {{ stockHandling(entry) }} ·
                        {{ entry.held_quantity }} held
                    </p>
                    <p><strong>Refund:</strong> {{ refundProgress(entry) }}</p>
                </div>
                <p v-if="entry.decision_reason" class="text-sm">
                    Review notes: {{ entry.decision_reason }}
                </p>
                <div class="border-border space-y-2 border-t pt-3 text-sm">
                    <p>
                        Received by: {{ entry.requester_name }} · Reviewed by:
                        {{ entry.reviewer_name ?? 'Awaiting review' }}
                    </p>
                    <template v-if="entry.status === 'accepted'">
                        <p v-if="entry.resolution_type">
                            Outcome:
                            {{
                                outcomeLabels[entry.resolution_type] ??
                                entry.resolution_type
                            }}
                            ·
                            {{
                                entry.resolution_type === 'refund'
                                    ? refundProgress(entry)
                                    : entry.resolution_completed_at
                                      ? 'Completed'
                                      : 'Not completed'
                            }}
                        </p>
                        <p
                            v-else-if="
                                !entry.refund_id ||
                                Number(entry.approved_amount) <= 0
                            "
                        >
                            Outcome not recorded. Confirm it with the customer.
                        </p>
                        <p
                            v-if="entry.resolution_summary"
                            class="whitespace-pre-wrap"
                        >
                            Internal resolution record:
                            {{ entry.resolution_summary }}
                        </p>
                        <p v-if="entry.resolution_reference">
                            Internal reference:
                            {{ entry.resolution_reference }}
                        </p>
                        <p v-if="entry.resolution_completed_at">
                            Completed by
                            {{ entry.resolution_completed_by_name }} ·
                            {{ formatDateTime(entry.resolution_completed_at) }}
                            · {{ entry.completion_notes }}
                        </p>
                        <p
                            v-if="
                                entry.resolution_type &&
                                entry.resolution_type !== 'refund'
                            "
                        >
                            No refund approved; the agreed
                            {{
                                outcomeLabels[entry.resolution_type] ??
                                entry.resolution_type
                            }}
                            outcome applies.
                        </p>
                    </template>
                </div>
                <Form
                    v-if="
                        entry.status === 'accepted' &&
                        entry.resolution_type &&
                        entry.resolution_type !== 'refund' &&
                        !entry.resolution_completed_at &&
                        page.props.auth.can?.dispatchReturns
                    "
                    v-bind="
                        CustomerReturnController.completeResolution.form(
                            entry.id,
                        )
                    "
                    v-slot="{ errors, processing }"
                    class="space-y-3"
                >
                    <Label :for="`complete-resolution-${entry.id}`"
                        >Completion notes (internal)</Label
                    >
                    <Input
                        :id="`complete-resolution-${entry.id}`"
                        name="completion_notes"
                        required
                        minlength="5"
                        maxlength="2000"
                    />
                    <label
                        v-if="entry.disposition === 'return_to_customer'"
                        class="flex gap-2 text-sm"
                        ><input
                            type="checkbox"
                            name="handover_confirmed"
                            value="1"
                            required
                        />I physically handed the original item back to the
                        customer.</label
                    >
                    <p class="text-muted-foreground text-sm">
                        Confirm after completing the agreed outcome. This does
                        not issue replacement stock or pay a refund.
                    </p>
                    <InputError
                        :message="
                            errors.resolution ||
                            errors.completion_notes ||
                            errors.handover_confirmed
                        "
                    />
                    <Button type="submit" :disabled="processing"
                        >Confirm completion</Button
                    >
                </Form>
                <h4 class="font-semibold">Product photos</h4>
                <p
                    v-if="entry.request_eligibility_path === 'manual_review'"
                    class="text-sm"
                >
                    Eligibility needs review.
                </p>
                <div
                    v-if="evidence[entry.id]?.length"
                    class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <a
                        v-for="file in evidence[entry.id]"
                        :key="file.id"
                        :href="
                            AdministrationEvidenceController.show(file.id).url
                        "
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary-ink min-w-0 space-y-2 underline underline-offset-4"
                    >
                        <img
                            v-if="
                                [
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                ].includes(file.mime_type)
                            "
                            :src="
                                AdministrationEvidenceController.show(file.id)
                                    .url
                            "
                            :alt="`Inspection photo: ${file.original_name}`"
                            class="bg-muted max-h-36 w-full rounded-md object-contain"
                            loading="lazy"
                        />
                        <span class="block">{{ file.original_name }}</span>
                    </a>
                </div>
                <Form
                    v-if="
                        entry.can_upload_evidence &&
                        (evidence[entry.id]?.length ?? 0) < 5
                    "
                    v-bind="
                        AdministrationEvidenceController.store.form({
                            subjectType: 'customer_return',
                            subjectId: entry.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    reset-on-success
                    class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                    ><EvidenceFileInput
                        :key="`return-${entry.id}-${evidence[entry.id]?.length ?? 0}`"
                        label="Product photos"
                        images-only
                        :disabled="processing" /><Button
                        class="min-h-11"
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Upload files</Button
                    ><InputError :message="evidenceError(errors)"
                /></Form>
                <Form
                    v-if="
                        entry.status === 'pending' &&
                        entry.requester_id !== page.props.auth.user.id &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="CustomerReturnController.decide.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-3 sm:grid-cols-2"
                >
                    <h4 class="font-semibold sm:col-span-2">Review return</h4>
                    <div class="space-y-1">
                        <Label>Decision</Label
                        ><select
                            aria-label="Decision"
                            v-model="decisions[entry.id]"
                            name="decision"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="accept">Accept</option>
                            <option value="reject">Reject</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label>Review notes</Label
                        ><Input
                            aria-label="Notes"
                            class="min-h-11"
                            name="reason"
                            required
                            minlength="5"
                            maxlength="2000"
                        /><InputError :message="errors.reason" />
                    </div>
                    <div class="space-y-1">
                        <Label :for="`public-review-${entry.id}`"
                            >Customer-visible feedback (optional)</Label
                        >
                        <Input
                            :id="`public-review-${entry.id}`"
                            name="public_feedback"
                            minlength="5"
                            maxlength="2000"
                        />
                        <InputError :message="errors.public_feedback" />
                    </div>
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-2 sm:col-span-2"
                    >
                        <Label :for="`resolution-type-${entry.id}`"
                            >Outcome</Label
                        >
                        <select
                            :id="`resolution-type-${entry.id}`"
                            v-model="resolutionTypes[entry.id]"
                            name="resolution_type"
                            required
                            class="border-input bg-background min-h-11 w-full rounded-md border px-3 py-2"
                        >
                            <option disabled value="">Select outcome</option>
                            <option value="refund">Refund</option>
                            <option value="replacement">Replacement</option>
                            <option value="repair">Repair</option>
                            <option value="other">Other agreed outcome</option>
                        </select>
                        <InputError
                            :message="errors.resolution_type || errors.type"
                        />
                        <template
                            v-if="
                                resolutionTypes[entry.id] &&
                                resolutionTypes[entry.id] !== 'refund'
                            "
                        >
                            <Label :for="`resolution-reference-${entry.id}`"
                                >Internal reference (optional)</Label
                            >
                            <Input
                                :id="`resolution-reference-${entry.id}`"
                                name="resolution_reference"
                                maxlength="255"
                            />
                        </template>
                    </div>
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-1"
                    >
                        <Label>Stock action</Label
                        ><select
                            aria-label="Stock action"
                            name="disposition"
                            v-model="stockDispositions[entry.id]"
                            required
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option disabled value="">
                                Select stock action
                            </option>
                            <option value="restock">
                                Restock after inspection
                            </option>
                            <option value="write_off">Write off</option>
                            <option value="supplier_return">
                                Hold for supplier return
                            </option>
                            <option
                                v-if="
                                    ['repair', 'other'].includes(
                                        resolutionTypes[entry.id],
                                    )
                                "
                                value="return_to_customer"
                            >
                                Hold for return to customer after completion
                            </option>
                        </select>
                    </div>
                    <label
                        v-if="decisions[entry.id] !== 'reject'"
                        class="flex max-w-full min-w-0 items-start gap-2 text-sm"
                        ><input
                            name="inspection_confirmed"
                            type="checkbox"
                            value="1"
                            class="mt-1" /><span
                            >I inspected the item and confirm it is suitable for
                            sale. Required when choosing restock.<InputError
                                :message="errors.inspection_confirmed" /></span
                    ></label>
                    <div
                        v-if="
                            decisions[entry.id] !== 'reject' &&
                            stockDispositions[entry.id] === 'restock'
                        "
                        class="space-y-2 sm:col-span-2"
                    >
                        <Label :for="`inspection-findings-${entry.id}`"
                            >Inspection notes (internal)</Label
                        >
                        <Input
                            :id="`inspection-findings-${entry.id}`"
                            name="inspection_notes"
                            required
                            minlength="5"
                            maxlength="2000"
                        />
                        <p class="text-muted-foreground text-sm">
                            Record tests confirming the item is safe to sell.
                            Faults, transit damage, Other, and unclassified
                            cases also need inspection photos.
                        </p>
                        <InputError :message="errors.inspection_notes" />
                    </div>
                    <RefundAmountFields
                        v-if="decisions[entry.id] !== 'reject'"
                        :maximum="entry.refundable_item_value"
                        :monetary="resolutionTypes[entry.id] === 'refund'"
                        :errors="errors"
                    />
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-1"
                    >
                        <Label>Supplier, if needed</Label
                        ><select
                            aria-label="Supplier"
                            name="supplier_id"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">Select supplier</option>
                            <option
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                :value="supplier.id"
                            >
                                {{ supplier.name }}
                            </option></select
                        ><InputError :message="errors.supplier_id" />
                    </div>
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="
                            processing ||
                            (decisions[entry.id] !== 'reject' &&
                                !entry.has_product_photo)
                        "
                        >Record decision</Button
                    ><InputError
                        :message="errors.decision || errors.evidence"
                    />
                    <p
                        v-if="!entry.has_product_photo"
                        class="text-sm sm:col-span-2"
                    >
                        A product photo is required before acceptance. Ask staff
                        to add missing photos.
                        {{
                            (evidence[entry.id]?.length ?? 0) >= 5
                                ? 'Attachment limit reached. Follow up with staff.'
                                : ''
                        }}
                    </p>
                </Form>
                <Form
                    v-if="
                        entry.status === 'rejected_pending_handover' &&
                        page.props.auth.can?.dispatchReturns
                    "
                    v-bind="CustomerReturnController.handBack.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 gap-2"
                    ><Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Confirm returned to customer</Button
                    ><InputError :message="errors.handover"
                /></Form>
                <div
                    v-if="entry.refund_id"
                    class="border-border space-y-4 border-t pt-4 text-sm"
                >
                    <h4 class="font-semibold">Refund</h4>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-muted-foreground">Approved</dt>
                            <dd class="font-medium tabular-nums">
                                ₱{{ entry.approved_amount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Confirmed paid
                            </dt>
                            <dd class="font-medium tabular-nums">
                                ₱{{ entry.verified_paid_amount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Awaiting review
                            </dt>
                            <dd class="tabular-nums">
                                ₱{{ entry.awaiting_review_amount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Disputed</dt>
                            <dd class="tabular-nums">
                                ₱{{ entry.disputed_amount }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Awaiting confirmation
                            </dt>
                            <dd class="tabular-nums">
                                ₱{{ entry.refund_balance }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Not recorded</dt>
                            <dd class="tabular-nums">
                                ₱{{ entry.unrecorded_refund_amount }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-for="payment in refundPayments[entry.refund_id] ?? []"
                        :id="`refund-payment-${payment.id}`"
                        :key="payment.id"
                        class="border-border space-y-3 border-t pt-3"
                    >
                        <p>
                            ₱{{ payment.amount }} ·
                            {{
                                paymentLabels[payment.payment_method] ??
                                payment.payment_method
                            }}
                            <template v-if="payment.external_reference">
                                · {{ payment.external_reference }}</template
                            >
                            ·
                            {{
                                payment.review?.confirmation_method ===
                                'approver'
                                    ? 'Confirmed by approver'
                                    : payment.review?.decision === 'voided'
                                      ? 'Voided after dispute'
                                      : (payment.review?.decision ??
                                        'Awaiting review')
                            }}
                        </p>
                        <p class="text-sm">
                            Recorded by: {{ payment.recorder_name }} ·
                            {{
                                payment.review?.confirmation_method ===
                                'approver'
                                    ? 'Confirmed by approver:'
                                    : 'Reviewed by:'
                            }}
                            {{
                                payment.review?.reviewer_name ??
                                'Awaiting review'
                            }}
                        </p>
                        <h4
                            v-if="
                                payment.payment_method !== 'cash' ||
                                paymentEvidence[payment.id]?.length ||
                                payment.can_upload_evidence
                            "
                            class="font-semibold"
                        >
                            Payment proof
                        </h4>
                        <div
                            v-if="
                                page.props.auth.can?.viewOrders &&
                                paymentEvidence[payment.id]?.length
                            "
                            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <a
                                v-for="file in paymentEvidence[payment.id]"
                                :key="file.id"
                                :href="
                                    AdministrationEvidenceController.show(
                                        file.id,
                                    ).url
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary-ink min-w-0 space-y-2 underline underline-offset-4"
                                ><img
                                    v-if="
                                        [
                                            'image/jpeg',
                                            'image/png',
                                            'image/webp',
                                        ].includes(file.mime_type)
                                    "
                                    :src="
                                        AdministrationEvidenceController.show(
                                            file.id,
                                        ).url
                                    "
                                    :alt="`Payment proof: ${file.original_name}`"
                                    class="bg-muted max-h-36 w-full rounded-md object-contain"
                                    loading="lazy"
                                /><span class="block">{{
                                    file.original_name
                                }}</span></a
                            >
                        </div>
                        <Form
                            v-if="
                                payment.can_upload_evidence &&
                                (paymentEvidence[payment.id]?.length ?? 0) < 5
                            "
                            v-bind="
                                AdministrationEvidenceController.store.form({
                                    subjectType: 'refund_payment',
                                    subjectId: payment.id,
                                })
                            "
                            v-slot="{ errors, processing }"
                            reset-on-success
                            class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                            ><EvidenceFileInput
                                :key="`payment-${payment.id}-${paymentEvidence[payment.id]?.length ?? 0}`"
                                label="Payment proof"
                                :disabled="processing" /><Button
                                class="min-h-11"
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                                >Upload files</Button
                            ><InputError :message="evidenceError(errors)"
                        /></Form>
                        <p
                            v-if="payment.review?.reason"
                            class="text-muted-foreground"
                        >
                            Review: {{ payment.review.reason }}
                        </p>
                        <Form
                            v-if="
                                page.props.auth.can?.reconcileReturns &&
                                payment.recorded_by !==
                                    page.props.auth.user.id &&
                                !['verified', 'voided'].includes(
                                    payment.review?.decision,
                                )
                            "
                            v-bind="
                                CustomerReturnController.reviewPayment.form(
                                    payment.id,
                                )
                            "
                            v-slot="{ errors, processing }"
                            class="grid max-w-full min-w-0 gap-2 sm:grid-cols-2"
                        >
                            <select
                                aria-label="Decision"
                                name="decision"
                                class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <option value="verified">
                                    Verify payment record
                                </option>
                                <option value="disputed">
                                    Dispute payment record
                                </option>
                                <option
                                    v-if="
                                        payment.review?.decision === 'disputed'
                                    "
                                    value="voided"
                                >
                                    Void disputed payment and reverse amount
                                </option>
                            </select>
                            <Input
                                aria-label="Notes"
                                class="min-h-11"
                                name="reason"
                                required
                                minlength="5"
                                maxlength="2000"
                                placeholder="Review notes"
                            />
                            <Button
                                class="min-h-11"
                                type="submit"
                                :disabled="processing"
                                >Record review</Button
                            ><InputError
                                :message="errors.decision || errors.reason"
                            />
                        </Form>
                    </div>
                    <Form
                        v-if="entry.can_record_payment"
                        v-bind="
                            CustomerReturnController.recordPayment.form(
                                entry.refund_id,
                            )
                        "
                        v-slot="{ errors, processing }"
                        class="grid max-w-full min-w-0 gap-2 sm:grid-cols-3"
                        ><h4 class="font-semibold sm:col-span-3">
                            Confirm payment
                        </h4>
                        <input
                            type="hidden"
                            name="submission_key"
                            :value="entry.payment_submission_key" /><Input
                            aria-label="Amount paid"
                            class="min-h-11"
                            name="amount"
                            type="text"
                            inputmode="decimal"
                            pattern="[0-9]+([.][0-9]{1,2})?"
                            :default-value="entry.unrecorded_refund_amount"
                            :key="`${entry.refund_id}-${entry.unrecorded_refund_amount}`"
                            placeholder="Amount paid"
                            required /><select
                            aria-label="Payment method"
                            name="payment_method"
                            :value="paymentMethods[entry.refund_id] ?? ''"
                            required
                            @change="
                                paymentMethods[entry.refund_id] =
                                    $event.target.value
                            "
                            class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="" disabled>
                                Select payment method
                            </option>
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                            <option value="maya">Maya</option>
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="other">Other</option>
                        </select>
                        <div
                            v-if="
                                paymentMethods[entry.refund_id] &&
                                paymentMethods[entry.refund_id] !== 'cash'
                            "
                            class="space-y-2 sm:col-span-3"
                        >
                            <EvidenceFileInput
                                :key="`${entry.refund_id}-${entry.unrecorded_refund_amount}-${paymentMethods[entry.refund_id]}`"
                                label="Payment proof"
                                name="proof"
                                :multiple="false"
                                images-only
                                :disabled="processing"
                            />
                            <p class="text-muted-foreground text-sm">
                                Customers can see this image. Hide unrelated
                                balances and account details.
                            </p>
                            <InputError :message="errors.proof" />
                        </div>
                        <label
                            v-if="paymentMethods[entry.refund_id]"
                            class="flex items-start gap-2 text-sm sm:col-span-3"
                            ><input
                                type="checkbox"
                                name="payment_confirmed"
                                value="1"
                                required
                            />{{
                                paymentMethods[entry.refund_id] === 'cash'
                                    ? 'I handed this cash refund to the customer.'
                                    : 'I sent this refund payment and attached its customer-visible proof.'
                            }}</label
                        >
                        <Button
                            class="min-h-11"
                            type="submit"
                            :disabled="processing"
                            >Confirm refund payment</Button
                        ><InputError
                            :message="
                                errors.amount ||
                                errors.payment_confirmed ||
                                errors.submission_key
                            "
                    /></Form>
                    <p
                        v-if="
                            !entry.can_record_payment &&
                            Number(entry.approved_amount) > 0
                        "
                        class="text-muted-foreground text-sm"
                    >
                        {{
                            entry.refund_fully_verified
                                ? 'Refund completed through confirmed payment.'
                                : entry.unrecorded_refund_amount === '0.00'
                                  ? 'The approved refund is already recorded. Legacy payments may still need review; do not record them again.'
                                  : 'Only the return approver may make and record this refund payment.'
                        }}
                    </p>
                </div>
            </article>
            <div
                v-if="returns.last_page > 1"
                class="flex justify-between text-sm"
            >
                <Link
                    v-if="returns.prev_page_url"
                    :href="returns.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span
                ><Link
                    v-if="returns.next_page_url"
                    :href="returns.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </div>
        </section>
    </main>
</template>
