<script setup>
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeliveryAddressFields from '@/components/DeliveryAddressFields.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

const props = defineProps({
    emailVerified: { type: Boolean, required: true },
    administrationRoleCode: { type: String, default: null },
    canManageDefaultDeliveryAddress: { type: Boolean, required: true },
    defaultDeliveryAddress: { type: String, default: '' },
    defaultDeliveryContactNumber: { type: String, default: '' },
    deliveryLocations: { type: Array, default: () => [] },
    defaultDeliveryAddressPrefill: {
        type: Object,
        default: () => ({
            delivery_destination: '',
            delivery_barangay_code: '',
            delivery_address_details: '',
        }),
    },
    searchRecommendationsEnabled: { type: Boolean, default: true },
    productViewRecommendationsEnabled: { type: Boolean, default: true },
    personalizedRecommendationsEnabled: { type: Boolean, default: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});
const page = usePage();
const user = computed(() => page.props.auth.user);
const accountRoleLabel = computed(() => {
    if (user.value.role === 'customer') return 'Customer';

    return (
        {
            owner: 'Owner',
            manager: 'Manager',
            staff: 'Staff',
        }[props.administrationRoleCode] ?? 'Administrator'
    );
});
const defaultAddress = ref({ ...props.defaultDeliveryAddressPrefill });
const defaultAddressAction = ref('keep');
const hasAddress = computed(
    () =>
        Boolean(props.defaultDeliveryAddress) ||
        Object.values(defaultAddress.value).some(Boolean),
);

function updateDefaultAddress(address) {
    defaultAddress.value = address;
    defaultAddressAction.value = 'save';
}

function clearDefaultAddress() {
    defaultAddress.value = {
        delivery_destination: '',
        delivery_barangay_code: '',
        delivery_address_details: '',
    };
    defaultAddressAction.value = 'clear';
}

function profileSaved() {
    defaultAddress.value = { ...props.defaultDeliveryAddressPrefill };
    defaultAddressAction.value = 'keep';
}
const personalizedRecommendationsEnabled = ref(
    props.personalizedRecommendationsEnabled,
);
const searchRecommendationsEnabled = ref(props.searchRecommendationsEnabled);
const productViewRecommendationsEnabled = ref(
    props.productViewRecommendationsEnabled,
);
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Profile settings" />
        <h1 class="sr-only">Profile settings</h1>
        <Heading
            variant="small"
            title="Profile"
            description="Update your account details, contact number, and default delivery address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
            @success="profileSaved"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    readonly
                    aria-describedby="email-description"
                    autocomplete="username"
                    placeholder="Email address"
                />
                <p id="email-description" class="text-muted-foreground text-sm">
                    Your account email address cannot be changed.
                </p>
                <InputError class="mt-2" :message="errors.email" />
                <dl class="flex flex-wrap gap-2 text-xs">
                    <div
                        class="bg-muted flex items-center gap-1 rounded-md px-2 py-1"
                    >
                        <dt class="text-muted-foreground">Email Verified:</dt>
                        <dd class="font-medium">
                            {{ props.emailVerified ? 'Yes' : 'No' }}
                        </dd>
                    </div>
                    <div
                        class="bg-muted flex items-center gap-1 rounded-md px-2 py-1"
                    >
                        <dt class="text-muted-foreground">Account Role:</dt>
                        <dd class="font-medium">{{ accountRoleLabel }}</dd>
                    </div>
                </dl>
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-2"
            >
                <Label for="default-delivery-contact-number"
                    >Default contact number (optional)</Label
                >
                <Input
                    id="default-delivery-contact-number"
                    name="default_delivery_contact_number"
                    type="tel"
                    maxlength="20"
                    autocomplete="tel"
                    placeholder="e.g. 0917 123 4567"
                    :default-value="props.defaultDeliveryContactNumber"
                />
                <InputError :message="errors.default_delivery_contact_number" />
                <p class="text-muted-foreground text-sm">
                    This number will pre-fill checkout and can be changed for
                    each order.
                </p>
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-4"
            >
                <p class="font-medium">Default delivery address (optional)</p>
                <input
                    type="hidden"
                    name="default_delivery_address_action"
                    :value="defaultAddressAction"
                />
                <DeliveryAddressFields
                    nationwide
                    @hydrate="defaultAddress = $event"
                    :model-value="defaultAddress"
                    @update:model-value="updateDefaultAddress"
                    :locations="props.deliveryLocations"
                    :errors="errors"
                    name-prefix="default_delivery"
                    :required="defaultAddressAction === 'save'"
                />
                <p
                    v-if="
                        props.defaultDeliveryAddress &&
                        defaultAddressAction !== 'clear'
                    "
                    class="text-muted-foreground text-sm"
                >
                    Saved address: {{ props.defaultDeliveryAddress }}
                    Your saved address is kept until you replace or clear it.
                    Choose a complete delivery location and enter the street or
                    building details to replace it.
                </p>
                <p
                    v-if="defaultAddressAction === 'clear'"
                    class="text-muted-foreground text-sm"
                    role="status"
                >
                    Your saved address will be cleared when you save.
                </p>
                <p class="text-muted-foreground text-sm">
                    Pre-fills delivery checkout. You can change it for each
                    order.
                </p>
                <InputError :message="errors.default_delivery_address_action" />
                <Button
                    v-if="hasAddress && defaultAddressAction !== 'clear'"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="processing"
                    @click="clearDefaultAddress"
                >
                    Clear saved address
                </Button>
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-2"
            >
                <Label for="personalized-recommendations-enabled"
                    >Personalized recommendations</Label
                >
                <input
                    type="hidden"
                    name="personalized_recommendations_enabled"
                    value="0"
                />
                <label
                    class="text-muted-foreground flex items-start gap-3 text-sm"
                >
                    <input
                        id="personalized-recommendations-enabled"
                        type="checkbox"
                        name="personalized_recommendations_enabled"
                        value="1"
                        v-model="personalizedRecommendationsEnabled"
                        class="border-input accent-primary mt-0.5 size-4"
                    />
                    <span
                        >Use shopping activity for suggestions. Turning this off
                        hides dashboard and catalog recommendations, pauses
                        personalization, and stops recording searches and views.
                        Popular picks can still appear on product pages and in
                        your cart. Search and view history expires after 90 days
                        and may be reused if you turn this back on sooner. Cart
                        and order records remain available for store
                        services.</span
                    >
                </label>
                <div class="border-border grid gap-3 border-l pl-4">
                    <p class="text-muted-foreground text-sm">
                        {{
                            personalizedRecommendationsEnabled
                                ? 'Choose which browsing signals to use while personalization is on.'
                                : 'Turn on personalization to change these options. Browsing signals are currently off.'
                        }}
                    </p>
                    <input
                        type="hidden"
                        name="search_recommendations_enabled"
                        value="0"
                        :disabled="!personalizedRecommendationsEnabled"
                    />
                    <label
                        class="text-muted-foreground flex items-start gap-3 text-sm"
                    >
                        <input
                            id="search-recommendations-enabled"
                            type="checkbox"
                            name="search_recommendations_enabled"
                            value="1"
                            :checked="
                                personalizedRecommendationsEnabled &&
                                searchRecommendationsEnabled
                            "
                            @change="
                                searchRecommendationsEnabled =
                                    $event.target.checked
                            "
                            :disabled="!personalizedRecommendationsEnabled"
                            class="border-input accent-primary mt-0.5 size-4"
                        />
                        <span
                            >Use catalog searches for suggestions. Turning this
                            off stops search recording and search-based
                            suggestions.</span
                        >
                    </label>
                    <input
                        type="hidden"
                        name="product_view_recommendations_enabled"
                        value="0"
                        :disabled="!personalizedRecommendationsEnabled"
                    />
                    <label
                        class="text-muted-foreground flex items-start gap-3 text-sm"
                    >
                        <input
                            id="product-view-recommendations-enabled"
                            type="checkbox"
                            name="product_view_recommendations_enabled"
                            value="1"
                            :checked="
                                personalizedRecommendationsEnabled &&
                                productViewRecommendationsEnabled
                            "
                            @change="
                                productViewRecommendationsEnabled =
                                    $event.target.checked
                            "
                            :disabled="!personalizedRecommendationsEnabled"
                            class="border-input accent-primary mt-0.5 size-4"
                        />
                        <span
                            >Use viewed products for suggestions. Turning this
                            off stops view recording and view-based
                            suggestions.</span
                        >
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    class="cursor-pointer"
                    >Save</Button
                >
            </div>
        </Form>
    </div>
</template>
