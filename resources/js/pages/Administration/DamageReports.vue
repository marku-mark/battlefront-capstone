<script setup>
import OperationalExports from '@/components/administration/OperationalExports.vue';
import { formatRelativeTime } from '@/lib/dateTime';
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import AdministrationEvidenceController from '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController';
import InputError from '@/components/InputError.vue';
import FileInput from '@/components/FileInput.vue';
import SearchableProductSelect from '@/components/administration/SearchableProductSelect.vue';
import SearchField from '@/components/SearchField.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref, watch } from 'vue';

const requestReasonCode = ref('');

const props = defineProps({
    reports: Object,
    products: Array,
    suppliers: Array,
    evidence: Object,
    selected_case: { type: Number, default: null },
    filters: Object,
});
const page = usePage();
const damageLabels = {
    pending: 'Awaiting review',
    release: 'Returned to stock',
    write_off: 'Written off',
    supplier_return: 'Supplier return',
};
const damageProgress = (report) => {
    if (report.status === 'pending')
        return 'Another administrator must review this report';
    if (report.disposition === 'release') return 'Returned to available stock';
    if (report.disposition === 'write_off')
        return 'Written off; removed from held stock';
    if (report.disposition === 'supplier_return' && report.held_quantity > 0)
        return 'On hold for supplier return';
    if (report.disposition === 'supplier_return')
        return 'Supplier follow-up complete; no units held';

    return report.status.replaceAll('_', ' ');
};
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: DamageReportController.index,
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
            { title: 'Damage reports', href: DamageReportController.index() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Damage reports" />
        <p v-if="selected_case" class="text-sm">
            Showing damage report #{{ selected_case }} ·
            <Link
                :href="DamageReportController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <PageHeader title="Damage reports" />
        <OperationalExports
            feature="damage-reports"
            :filters="filters"
            :case-id="selected_case"
        />
        <p class="text-muted-foreground text-sm" role="note">
            Reported units are removed from available stock and held until
            reviewed.
        </p>
        <Form
            v-if="page.props.auth.can?.requestReturns"
            v-bind="DamageReportController.store.form()"
            v-slot="{ errors, processing }"
            class="surface-card border-border bg-card grid max-w-full min-w-0 gap-4 border p-5 sm:grid-cols-2"
        >
            <div class="space-y-2">
                <Label for="damage-product">Product</Label
                ><SearchableProductSelect
                    id="damage-product"
                    :products="products"
                /><InputError :message="errors.product_id" />
            </div>
            <div class="space-y-2">
                <Label for="damage-quantity">Damaged units</Label
                ><Input
                    class="min-h-11"
                    id="damage-quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    required
                /><InputError :message="errors.quantity" />
            </div>
            <div class="space-y-2 sm:col-span-2">
                <Label for="damage-reason-code">Reason</Label>
                <select
                    id="damage-reason-code"
                    name="reason_code"
                    v-model="requestReasonCode"
                    required
                    class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="">Select a reason</option>
                    <option value="handling_damage">Warehouse handling</option>
                    <option value="storage_damage">Storage</option>
                    <option value="packing_damage">Packing</option>
                    <option value="defect_discovered">Defect discovered</option>
                    <option value="other">Other</option>
                </select>
                <InputError :message="errors.reason_code" />
                <Label for="damage-reason">{{
                    requestReasonCode === 'other'
                        ? 'Additional notes (required)'
                        : 'Additional notes (optional)'
                }}</Label
                ><Input
                    class="min-h-11"
                    id="damage-reason"
                    name="reason"
                    :required="requestReasonCode === 'other'"
                    minlength="5"
                    maxlength="2000"
                /><InputError :message="errors.reason" />
            </div>
            <Button class="min-h-11" type="submit" :disabled="processing"
                >Report and hold stock</Button
            >
        </Form>
        <section class="space-y-3" aria-label="Damage cases">
            <Link
                :href="
                    DamageReportController.index({
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
                        ? 'Show all damage reports'
                        : 'Show overdue inspections'
                }}
            </Link>
            <SearchField
                id="damage-search"
                v-model="search"
                class="mb-3 max-w-xl"
                label="Search damage reports"
                placeholder="Product, requester, or reason"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
            <p
                v-if="reports.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No damage reports.
            </p>
            <article
                v-for="report in reports.data"
                :key="report.id"
                class="surface-card border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">
                            {{ report.product_name }} ·
                            {{ report.quantity }} units
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            Reported by {{ report.requester_name }}:
                            {{ report.reason }}
                        </p>
                    </div>
                    <span class="text-sm capitalize">{{
                        damageLabels[report.disposition ?? report.status] ??
                        report.status.replaceAll('_', ' ')
                    }}</span>
                </div>
                <p class="text-sm">
                    Stock: {{ damageProgress(report) }} ·
                    {{ report.held_quantity }} units held ·
                    {{ formatRelativeTime(report.created_at) }}
                </p>
                <p
                    v-if="report.inspection_overdue"
                    class="text-destructive text-sm font-semibold"
                >
                    Inspection overdue
                </p>
                <p v-if="report.decision_reason" class="text-sm">
                    Review notes: {{ report.decision_reason }}
                </p>
                <div
                    v-if="evidence[report.id]?.length"
                    class="flex flex-wrap gap-3 text-sm"
                >
                    <a
                        v-for="file in evidence[report.id]"
                        :key="file.id"
                        :href="
                            AdministrationEvidenceController.show(file.id).url
                        "
                        class="text-primary underline"
                        >{{ file.original_name }}</a
                    >
                </div>
                <Form
                    v-if="
                        page.props.auth.can?.requestReturns &&
                        (evidence[report.id]?.length ?? 0) < 5
                    "
                    v-bind="
                        AdministrationEvidenceController.store.form({
                            subjectType: 'damage_report',
                            subjectId: report.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                >
                    <label class="max-w-full min-w-0 text-sm"
                        >Photos or documents<FileInput
                            name="files[]"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            class="mt-1 block w-full max-w-full min-w-0" /></label
                    ><Button
                        class="min-h-11"
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Upload files</Button
                    ><InputError :message="errors.files || errors['files.0']" />
                </Form>
                <Form
                    v-if="
                        report.status === 'pending' &&
                        report.requester_id !== page.props.auth.user.id &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="DamageReportController.decide.form(report.id)"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-3 sm:grid-cols-3"
                >
                    <select
                        aria-label="Stock action"
                        name="disposition"
                        required
                        class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="release">
                            Return to available stock after inspection
                        </option>
                        <option value="write_off">Write off</option>
                        <option value="supplier_return">
                            Return to supplier
                        </option>
                    </select>
                    <select
                        aria-label="Supplier"
                        name="supplier_id"
                        class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="">Select supplier if returning</option>
                        <option
                            v-for="supplier in suppliers"
                            :key="supplier.id"
                            :value="supplier.id"
                        >
                            {{ supplier.name }}
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
                    <label
                        class="flex max-w-full min-w-0 items-center gap-2 text-sm sm:col-span-3"
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
                        >Record decision</Button
                    >
                    <InputError
                        :message="
                            errors.decision ||
                            errors.disposition ||
                            errors.supplier_id ||
                            errors.reason ||
                            errors.inspection_confirmed ||
                            errors.evidence
                        "
                    />
                </Form>
            </article>
        </section>
        <div v-if="reports.last_page > 1" class="flex justify-between text-sm">
            <Link
                v-if="reports.prev_page_url"
                :href="reports.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span
            ><Link
                v-if="reports.next_page_url"
                :href="reports.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </div>
    </main>
</template>
