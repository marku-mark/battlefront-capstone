<script setup>
import OperationalExports from '@/components/administration/OperationalExports.vue';
import { formatRelativeTime } from '@/lib/dateTime';
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import SupplierController from '@/actions/App/Http/Controllers/Administration/SupplierController';
import SearchField from '@/components/SearchField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import SearchableProductSelect from '@/components/administration/SearchableProductSelect.vue';
import { Label } from '@/components/ui/label';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { computed, ref, watch } from 'vue';

const requestReasonCode = ref('');
const selectedSupplierId = ref('');

const props = defineProps({
    returns: Object,
    products: Array,
    suppliers: Array,
    supplier_product_links: { type: Array, default: () => [] },
    selected_case: { type: Number, default: null },
    filters: Object,
});
const supplierProducts = computed(() => {
    if (!selectedSupplierId.value) return [];

    const links = props.supplier_product_links.filter(
        (link) => String(link.supplier_id) === String(selectedSupplierId.value),
    );
    if (links.length === 0) return props.products;

    const productIds = new Set(links.map((link) => String(link.product_id)));
    return props.products.filter((product) => productIds.has(String(product.id)));
});
const page = usePage();
const returnLabels = {
    pending: 'Awaiting review',
    approved: 'Ready to send',
    dispatched: 'Sent to supplier',
    closed: 'Received by supplier',
    rejected_follow_up: 'Follow-up needed',
    rejected: 'Rejected',
};
const supplierReturnProgress = {
    pending: 'Another administrator must review this request.',
    approved: 'Send the held units to the supplier.',
    dispatched: 'Waiting for the supplier to confirm receipt.',
    closed: 'Supplier received the items. Record the outcome if still missing.',
    rejected_follow_up:
        'Supplier rejected the return. Choose a stock action or try again.',
    rejected: 'Rejected; follow-up complete.',
};
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: SupplierReturnController.index,
    query: () => ({
        case: props.selected_case ?? undefined,
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
                title: 'Supplier returns',
                href: SupplierReturnController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Supplier returns" />
        <p v-if="selected_case" class="text-sm">
            Showing supplier return #{{ selected_case }} ·
            <Link
                :href="SupplierReturnController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <PageHeader title="Supplier returns">
            <template #actions
                ><Button variant="outline" as-child
                    ><Link :href="SupplierController.index()"
                        >Suppliers</Link
                    ></Button
                ></template
            >
        </PageHeader>
        <OperationalExports
            feature="supplier-returns"
            :filters="filters"
            :case-id="selected_case"
        />
        <Form
            v-if="page.props.auth.can?.requestReturns"
            v-bind="SupplierReturnController.store.form()"
            v-slot="{ errors, processing }"
            class="surface-card border-border bg-card grid max-w-full min-w-0 gap-4 border p-5 sm:grid-cols-2"
        >
            <h2 class="text-lg font-semibold sm:col-span-2">
                Request a return
            </h2>
            <div class="space-y-2">
                <Label for="return-supplier">Supplier</Label
                ><select
                    id="return-supplier"
                    name="supplier_id"
                    v-model="selectedSupplierId"
                    required
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
            <div class="space-y-2">
                <Label for="return-product">Product</Label
                ><SearchableProductSelect
                    :key="selectedSupplierId"
                    id="return-product"
                    :products="supplierProducts"
                    :disabled="!selectedSupplierId"
                    :empty-message="selectedSupplierId ? 'No linked products have available stock.' : 'Select a supplier first.'"
                /><InputError :message="errors.product_id" />
            </div>
            <div class="space-y-2">
                <Label for="return-quantity">Units</Label
                ><Input
                    class="min-h-11"
                    id="return-quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    required
                /><InputError :message="errors.quantity" />
            </div>
            <div class="space-y-2">
                <Label for="supplier-return-reason-code">Reason</Label>
                <select
                    id="supplier-return-reason-code"
                    name="reason_code"
                    v-model="requestReasonCode"
                    required
                    class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="">Select a reason</option>
                    <option value="defective">Defective</option>
                    <option value="wrong_item">Wrong item</option>
                    <option value="supplier_damage">Supplier damage</option>
                    <option value="other">Other</option>
                </select>
                <InputError :message="errors.reason_code" />
            </div>
            <div class="space-y-2">
                <Label for="return-reason">{{
                    requestReasonCode === 'other'
                        ? 'Additional notes (required)'
                        : 'Additional notes (optional)'
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
            <Button class="min-h-11" type="submit" :disabled="processing"
                >Hold stock and request return</Button
            >
        </Form>
        <section class="space-y-3" aria-label="Supplier return cases">
            <Link
                :href="
                    SupplierReturnController.index({
                        query: {
                            case: selected_case ?? undefined,
                            q: filters?.q ?? undefined,
                            overdue: filters?.overdue ? undefined : 1,
                        },
                    })
                "
                class="text-primary text-sm underline"
            >
                {{
                    filters?.overdue
                        ? 'Show all supplier returns'
                        : 'Open over 30 days'
                }}
            </Link>
            <SearchField
                id="supplier-return-search"
                v-model="search"
                class="mb-3 max-w-xl"
                label="Search supplier returns"
                placeholder="Product, supplier, requester, reason, or reference"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
            <p
                v-if="returns.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No supplier returns.
            </p>
            <article
                v-for="entry in returns.data"
                :key="entry.id"
                class="surface-card border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">
                            {{ entry.product_name }} ·
                            {{ entry.quantity }} units
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            {{ entry.supplier_name }} · Requested by
                            {{ entry.requester_name }}
                        </p>
                        <p class="mt-1 text-sm">{{ entry.reason }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ formatRelativeTime(entry.created_at) }}
                        </p>
                        <p
                            v-if="entry.dispatch_reference"
                            class="text-muted-foreground text-sm"
                        >
                            Dispatch: {{ entry.dispatch_reference }}
                        </p>
                        <p
                            v-if="entry.acknowledgement_reference"
                            class="text-muted-foreground text-sm"
                        >
                            Acknowledgment:
                            {{ entry.acknowledgement_reference }}
                        </p>
                        <p
                            v-if="entry.external_reference"
                            class="text-muted-foreground text-sm"
                        >
                            Legacy reference: {{ entry.external_reference }}
                        </p>
                    </div>
                    <span class="text-sm capitalize">{{
                        (entry.status === 'closed' && !entry.resolution_outcome
                            ? 'Received by supplier ? outcome pending'
                            : returnLabels[entry.status]) ??
                        entry.status.replaceAll('_', ' ')
                    }}</span>
                </div>
                <p class="text-muted-foreground text-sm">
                    Next step:
                    {{
                        supplierReturnProgress[entry.status] ??
                        'Review the case history.'
                    }}
                </p>
                <p
                    v-if="entry.follow_up_overdue"
                    class="text-destructive text-sm font-semibold"
                >
                    Follow-up overdue: over 30 days
                </p>
                <p class="text-sm">
                    On hold: {{ entry.held_quantity }} units · Origin:
                    {{
                        entry.damage_report_id
                            ? `damage report #${entry.damage_report_id}`
                            : entry.customer_return_item_id
                              ? `customer return item #${entry.customer_return_item_id}`
                              : 'direct supplier return'
                    }}
                </p>
                <Form
                    v-if="
                        entry.status === 'pending' &&
                        entry.requester_id !== page.props.auth.user.id &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="SupplierReturnController.decide.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                    ><label class="max-w-full min-w-0 text-sm"
                        >Review notes<Input
                            aria-label="Notes"
                            class="min-h-11"
                            name="reason"
                            required
                            minlength="5"
                            maxlength="2000" /></label
                    ><Button
                        class="min-h-11"
                        type="submit"
                        name="decision"
                        value="approve"
                        :disabled="processing"
                        >Approve</Button
                    ><Button
                        class="min-h-11"
                        type="submit"
                        name="decision"
                        value="reject"
                        variant="outline"
                        :disabled="processing"
                        >Reject</Button
                    ><InputError :message="errors.decision || errors.reason"
                /></Form>
                <Form
                    v-if="
                        entry.status === 'approved' &&
                        page.props.auth.can?.dispatchReturns
                    "
                    v-bind="SupplierReturnController.dispatch.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 flex-wrap gap-2"
                    ><Input
                        aria-label="Receipt or reference"
                        class="min-h-11"
                        name="external_reference"
                        placeholder="Dispatch reference"
                        required /><Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Record dispatch</Button
                    ><InputError
                        :message="errors.dispatch || errors.external_reference"
                /></Form>
                <Form
                    v-if="
                        entry.status === 'dispatched' &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="SupplierReturnController.acknowledge.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 flex-wrap gap-2"
                    ><Input
                        aria-label="Receipt or reference"
                        class="min-h-11"
                        name="external_reference"
                        placeholder="Supplier acknowledgement"
                        required
                        maxlength="255" /><Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Record supplier acknowledgment</Button
                    ><InputError
                        :message="
                            errors.acknowledgement || errors.external_reference
                        "
                /></Form>
                <Form
                    v-if="
                        entry.status === 'rejected_follow_up' &&
                        page.props.auth.can?.approveReturns &&
                        entry.requester_id !== page.props.auth.user.id &&
                        entry.reviewer_id !== page.props.auth.user.id
                    "
                    v-bind="SupplierReturnController.followUp.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-3 sm:grid-cols-2"
                >
                    <label class="max-w-full min-w-0 text-sm"
                        >Follow-up decision<select
                            aria-label="Decision"
                            name="decision"
                            required
                            class="border-input bg-background focus-visible:ring-ring mt-1 block min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="release">
                                Release after inspection
                            </option>
                            <option value="write_off">Write off</option>
                            <option value="retry">
                                Request another supplier return
                            </option>
                        </select></label
                    >
                    <label class="max-w-full min-w-0 text-sm"
                        >Supplier for retry<select
                            aria-label="Supplier"
                            name="supplier_id"
                            class="border-input bg-background focus-visible:ring-ring mt-1 block min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">Select if retrying</option>
                            <option
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                :value="supplier.id"
                            >
                                {{ supplier.name }}
                            </option>
                        </select></label
                    >
                    <label class="max-w-full min-w-0 text-sm sm:col-span-2"
                        >Reason<Input
                            aria-label="Notes"
                            class="min-h-11"
                            name="reason"
                            required
                            minlength="5"
                            maxlength="2000"
                    /></label>
                    <label
                        class="flex max-w-full min-w-0 items-center gap-2 text-sm sm:col-span-2"
                    >
                        <input
                            name="inspection_confirmed"
                            type="checkbox"
                            value="1"
                        />
                        I inspected the units and confirmed they are safe to
                        sell
                    </label>
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Record follow-up</Button
                    ><InputError
                        :message="
                            errors.decision ||
                            errors.reason ||
                            errors.supplier_id
                        "
                    />
                    <InputError
                        :message="
                            errors.inspection_confirmed || errors.evidence
                        "
                    />
                </Form>
                <p v-if="entry.resolution_outcome" class="text-sm">
                    Supplier outcome:
                    {{ entry.resolution_outcome.replaceAll('_', ' ') }} ·
                    {{ entry.resolution_reference }} ·
                    {{ entry.resolution_notes }}
                </p>
                <Form
                    v-if="
                        entry.status === 'closed' &&
                        !entry.resolution_outcome &&
                        page.props.auth.can?.resolveReturns
                    "
                    v-bind="SupplierReturnController.resolve.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-3 sm:grid-cols-2"
                >
                    <label class="max-w-full min-w-0 text-sm"
                        >Supplier outcome<select
                            name="outcome"
                            required
                            class="border-input bg-background focus-visible:ring-ring mt-1 block min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="replacement">Replacement</option>
                            <option value="credit">Credit</option>
                            <option value="refund">Refund</option>
                            <option value="rejected">Rejected</option>
                        </select></label
                    >
                    <label class="max-w-full min-w-0 text-sm"
                        >Supplier reference<Input
                            aria-label="Receipt or reference"
                            class="min-h-11"
                            name="external_reference"
                            required
                            maxlength="255"
                    /></label>
                    <label class="max-w-full min-w-0 text-sm sm:col-span-2"
                        >Outcome notes<Input
                            class="min-h-11"
                            name="notes"
                            required
                            minlength="5"
                            maxlength="2000"
                    /></label>
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Record supplier outcome</Button
                    ><InputError
                        :message="
                            errors.outcome ||
                            errors.external_reference ||
                            errors.notes
                        "
                    />
                </Form>
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
