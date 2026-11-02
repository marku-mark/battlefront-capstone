<script setup>
import PageHeader from '@/components/PageHeader.vue';
import InlineEditor from '@/components/administration/InlineEditor.vue';
import TeamEmailVerification from '@/components/administration/TeamEmailVerification.vue';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import TeamController from '@/actions/App/Http/Controllers/Administration/TeamController';
import RolePermissionController from '@/actions/App/Http/Controllers/Administration/RolePermissionController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    users: Object,
    permissions: Array,
    grants: Array,
    availableRoles: Array,
    canManagePermissions: Boolean,
    roleVersions: Object,
    defaultPermissions: { type: Object, default: () => ({}) },
    staffAllowedPermissions: { type: Array, default: () => [] },
    filters: Object,
    canViewOrders: Boolean,
    canAssignOrders: Boolean,
    teamEmailVerification: { type: Object, default: null },
});
const teamEmailVerified = ref(false);
const teamEmailVerification = ref(null);
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'all');
const memberDrafts = reactive({});
watch(
    () => props.users.data,
    (users) => {
        for (const user of users) {
            memberDrafts[user.id] ??= {
                role: props.availableRoles.includes(user.role) ? user.role : '',
                is_active: user.is_active ? '1' : '0',
            };
        }
    },
    { immediate: true },
);
function applyFilters() {
    router.get(TeamController.index(), {
        q: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
    });
}
const permissionDrafts = reactive({ staff: [], manager: [] });
watch(
    () => props.roleVersions,
    (versions, previous) => {
        for (const role of ['staff', 'manager']) {
            if (!previous || versions?.[role] !== previous?.[role]) {
                permissionDrafts[role] = props.grants
                    .filter(
                        (grant) =>
                            grant.role === role &&
                            isPermissionEligible(role, grant.permission),
                    )
                    .map((grant) => grant.permission);
            }
        }
    },
    { immediate: true },
);
function useDefaultPermissions(role) {
    permissionDrafts[role] = (props.defaultPermissions[role] ?? []).filter(
        (code) => isPermissionEligible(role, code),
    );
}
function isPermissionEligible(role, code) {
    return role === 'staff'
        ? props.staffAllowedPermissions.includes(code)
        : !['users.manage', 'permissions.manage', 'audit.export'].includes(
              code,
          );
}
const roleLabel = (role) =>
    role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Unassigned';
const permissionLabels = {
    'orders.view': 'View orders',
    'orders.process': 'Update order status',
    'orders.assign': 'Assign orders',
    'inventory.view': 'View inventory',
    'inventory.request': 'Request stock changes',
    'inventory.approve': 'Approve stock changes',
    'inventory.reorder': 'Manage reorders',
    'products.manage': 'Manage product details',
    'products.price': 'Manage product prices',
    'products.cost': 'Manage acquisition costs',
    'catalog.delete': 'Delete products and categories',
    'customers.view': 'View customers',
    'reports.view': 'View sales reports',
    'reports.export': 'Export reports',
    'reports.profit': 'View profit reports',
    'forecast.manage': 'Manage forecasts',
    'returns.request': 'Request returns',
    'returns.approve': 'Approve returns',
    'returns.record_payment': 'Record return payments',
    'returns.dispatch': 'Dispatch returns',
    'returns.resolve': 'Resolve returns',
    'returns.reconcile': 'Review return payments',
    'suppliers.manage': 'Manage suppliers',
    'branches.manage': 'Manage branches',
    'staff_reports.view': 'View staff reports',
    'audit.view': 'View activity log',
    'audit.export': 'Export activity log',
    'users.manage': 'Manage user accounts',
    'permissions.manage': 'Manage role access',
};
const permissionLabel = (permission) =>
    permissionLabels[permission.code] ?? permission.name.replaceAll('.', ' ');

defineOptions({
    layout: { breadcrumbs: [{ title: 'Team', href: TeamController.index() }] },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Team" />
        <PageHeader title="Team" />

        <section
            class="surface-card border-border bg-card space-y-4 border p-5"
        >
            <h2 class="text-lg font-semibold">Add team member</h2>
            <Form
                v-bind="TeamController.store.form()"
                v-slot="{ errors, processing }"
                reset-on-success
                @success="teamEmailVerification?.reset()"
                class="grid max-w-full min-w-0 gap-4 md:grid-cols-2"
            >
                <div class="space-y-2">
                    <Label for="team-name">Name</Label
                    ><Input
                        class="min-h-11"
                        id="team-name"
                        name="name"
                        required
                    /><InputError :message="errors.name" />
                </div>
                <TeamEmailVerification
                    ref="teamEmailVerification"
                    :verification="props.teamEmailVerification"
                    :error="errors.email"
                    @verified="teamEmailVerified = $event"
                />
                <div class="space-y-2">
                    <Label for="team-role">Role</Label
                    ><select
                        id="team-role"
                        name="role"
                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        required
                    >
                        <option
                            v-for="role in availableRoles"
                            :key="role"
                            :value="role"
                        >
                            {{ roleLabel(role) }}
                        </option></select
                    ><InputError :message="errors.role" />
                </div>
                <div class="space-y-2">
                    <Label for="team-password">Initial password</Label
                    ><Input
                        class="min-h-11"
                        id="team-password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                    /><InputError :message="errors.password" />
                </div>
                <div class="space-y-2">
                    <Label for="team-password-confirmation"
                        >Confirm initial password</Label
                    ><Input
                        class="min-h-11"
                        id="team-password-confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                    />
                </div>
                <div class="flex items-end">
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing || !teamEmailVerified"
                        >Create account</Button
                    >
                </div>
            </Form>
            <p class="text-muted-foreground text-sm">
                Share the initial password privately. A password change is not
                required at first sign-in.
            </p>
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Team members</h2>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="applyFilters"
            >
                <div class="space-y-1">
                    <Label for="team-search">Name or email</Label
                    ><Input
                        class="min-h-11"
                        id="team-search"
                        v-model="search"
                        maxlength="100"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="team-status">Status</Label
                    ><select
                        id="team-status"
                        v-model="status"
                        class="border-input bg-background focus-visible:ring-ring block min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="all">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <Button class="min-h-11" type="submit" variant="outline"
                    >Apply filters</Button
                >
            </form>
            <p
                v-if="users.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No team members found.
            </p>
            <div
                v-for="user in users.data"
                :key="user.id"
                class="surface-card border-border bg-card flex min-w-0 flex-wrap items-center justify-between gap-4 border p-4"
            >
                <div>
                    <p class="font-medium">{{ user.name }}</p>
                    <p class="text-muted-foreground text-sm">
                        {{ user.email }} · {{ roleLabel(user.role) }} ·
                        {{ user.is_active ? 'Active' : 'Inactive' }}
                    </p>
                </div>
                <div v-if="user.open_order_count" class="w-full text-sm">
                    <p>
                        {{ user.open_order_count }} open assigned
                        {{ user.open_order_count === 1 ? 'order' : 'orders' }}.
                        Deactivation takes effect immediately; these orders will
                        need reassignment.
                    </p>
                    <div v-if="canViewOrders" class="mt-1 flex flex-wrap gap-2">
                        <Link
                            v-for="orderId in user.open_order_ids"
                            :key="orderId"
                            :href="OrderController.show(orderId)"
                            class="text-primary underline"
                            >BF-{{ String(orderId).padStart(6, '0') }}</Link
                        ><Link
                            v-if="canAssignOrders"
                            :href="WorkQueueController.index()"
                            class="text-primary underline"
                            >Tasks</Link
                        >
                    </div>
                </div>
                <InlineEditor
                    v-if="user.can_edit"
                    label="Edit"
                    :trigger-label="'Edit ' + user.name"
                    v-slot="{ reveal }"
                    ><Form
                        @error="reveal"
                        v-if="user.can_edit"
                        v-bind="TeamController.update.form(user.id)"
                        v-slot="{ errors, processing }"
                        class="flex max-w-full min-w-0 flex-wrap items-center gap-2"
                    >
                        <select
                            :aria-label="`Role for ${user.name}`"
                            name="role"
                            v-model="memberDrafts[user.id].role"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option
                                v-if="!availableRoles.includes(user.role)"
                                value=""
                                disabled
                            >
                                Select a supported role
                            </option>
                            <option
                                v-for="role in availableRoles"
                                :key="role"
                                :value="role"
                            >
                                {{ roleLabel(role) }}
                            </option>
                        </select>
                        <select
                            :aria-label="`Account status for ${user.name}`"
                            name="is_active"
                            v-model="memberDrafts[user.id].is_active"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="1">Active</option>
                            <option value="0">Deactivated</option>
                        </select>
                        <Button
                            class="min-h-11"
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                            >Save</Button
                        >
                        <InputError
                            :message="
                                errors.user || errors.role || errors.is_active
                            "
                        /> </Form
                ></InlineEditor>
                <p v-else class="text-muted-foreground text-sm">
                    Only an owner can edit this account.
                </p>
            </div>
            <div
                v-if="users.last_page > 1"
                class="flex justify-between text-sm"
            >
                <Link
                    v-if="users.prev_page_url"
                    :href="users.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span
                ><Link
                    v-if="users.next_page_url"
                    :href="users.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </div>
        </section>

        <section v-if="canManagePermissions" class="space-y-4">
            <h2 class="text-lg font-semibold">Role access</h2>
            <p class="text-muted-foreground text-sm">
                Changes affect all accounts assigned to that role. Default
                selections are only applied when you click Save permissions.
            </p>
            <Form
                v-for="role in ['staff', 'manager']"
                :key="role"
                v-bind="RolePermissionController.update.form(role)"
                v-slot="{ errors, processing }"
                class="surface-card border-border bg-card max-w-full min-w-0 space-y-4 border p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold capitalize">{{ role }}</h3>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        :aria-label="`Use default permissions for ${roleLabel(role)}`"
                        :disabled="processing"
                        @click="useDefaultPermissions(role)"
                        >Use default permissions</Button
                    >
                </div>
                <input
                    type="hidden"
                    name="edit_version"
                    :value="roleVersions[role]"
                />
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        v-for="permission in permissions.filter((entry) =>
                            isPermissionEligible(role, entry.code),
                        )"
                        :key="permission.code"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="permissions[]"
                            :value="permission.code"
                            v-model="permissionDrafts[role]"
                        />{{ permissionLabel(permission) }}
                    </label>
                </div>
                <InputError :message="errors.permissions" />
                <InputError :message="errors.edit_version" />
                <Button class="min-h-11" type="submit" :disabled="processing"
                    >Save permissions</Button
                >
            </Form>
        </section>
    </main>
</template>
