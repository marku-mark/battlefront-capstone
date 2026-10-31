<script setup>
import { Form, Link, usePage } from '@inertiajs/vue3';
import { Image, Save } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import InputError from '@/components/InputError.vue';
import FileInput from '@/components/FileInput.vue';
import { formatCurrency } from '@/lib/currency';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    shippingProfiles: { type: Array, required: true },
    packageEstimates: { type: Object, required: true },
    showPreparation: { type: Boolean, default: true },
    product: { type: Object, default: null },
    form: { type: Object, required: true },
    methodOverride: { type: String, default: null },
    submitLabel: { type: String, required: true },
});
const page = usePage();
const canEditPrice = computed(
    () => page.props.auth?.can?.priceProducts === true,
);
const canEditAcquisitionCost = computed(
    () => page.props.auth?.can?.manageProductCosts === true,
);

const categoryId = ref(
    String(props.product?.category_id ?? props.categories[0]?.id ?? ''),
);
const isFeatured = ref(props.product?.is_featured ?? false);
const shippingProfile = ref(props.product?.shipping_profile ?? 'standard');
const packageMeasurementSource = ref(
    props.product?.package_measurement_source ?? 'estimated',
);
const packageEstimate = computed(
    () =>
        props.packageEstimates.categories[
            props.categories.find(
                (category) => String(category.id) === categoryId.value,
            )?.name
        ] ?? props.packageEstimates.fallback,
);
const packageWeight = ref(
    props.product?.package_weight_kg ?? packageEstimate.value.weight_kg,
);
const packageLength = ref(
    props.product?.package_length_cm ?? packageEstimate.value.length_cm,
);
const packageWidth = ref(
    props.product?.package_width_cm ?? packageEstimate.value.width_cm,
);
const packageHeight = ref(
    props.product?.package_height_cm ?? packageEstimate.value.height_cm,
);
watch([categoryId, packageMeasurementSource], () => {
    if (packageMeasurementSource.value === 'estimated' && !props.product) {
        packageWeight.value = packageEstimate.value.weight_kg;
        packageLength.value = packageEstimate.value.length_cm;
        packageWidth.value = packageEstimate.value.width_cm;
        packageHeight.value = packageEstimate.value.height_cm;
    }
});
const selectedHandling = computed(() =>
    props.shippingProfiles.find(
        (profile) => profile.profile === shippingProfile.value,
    ),
);
const selectedTagIds = ref([...(props.product?.tag_ids ?? [])]);
const selectedImagePreviewUrl = ref(null);
const imagePreviewFailed = ref(false);
const imagePreviewUrl = computed(
    () => selectedImagePreviewUrl.value ?? props.product?.image_url ?? null,
);

function updateImagePreview(event) {
    if (selectedImagePreviewUrl.value) {
        URL.revokeObjectURL(selectedImagePreviewUrl.value);
    }

    const image = event.target.files?.[0];
    selectedImagePreviewUrl.value = image ? URL.createObjectURL(image) : null;
    imagePreviewFailed.value = false;
}

onBeforeUnmount(() => {
    if (selectedImagePreviewUrl.value) {
        URL.revokeObjectURL(selectedImagePreviewUrl.value);
    }
});

function setTag(tagId, checked) {
    if (checked === true && !selectedTagIds.value.includes(tagId)) {
        selectedTagIds.value.push(tagId);
    }

    if (checked !== true) {
        selectedTagIds.value = selectedTagIds.value.filter(
            (selectedTagId) => selectedTagId !== tagId,
        );
    }
}

function firstTagError(errors) {
    return (
        errors.tag_ids ??
        Object.entries(errors).find(([key]) => key.startsWith('tag_ids.'))?.[1]
    );
}
</script>

<template>
    <Form v-bind="form" class="space-y-8" v-slot="{ errors, processing }">
        <input
            v-if="product"
            type="hidden"
            name="edit_version"
            :value="product.edit_version"
        />
        <InputError v-if="product" :message="errors.edit_version" />
        <input
            v-if="methodOverride"
            type="hidden"
            name="_method"
            :value="methodOverride"
        />
        <section aria-labelledby="product-details-heading" class="space-y-5">
            <div>
                <h2 id="product-details-heading" class="font-semibold">
                    Product details
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Provide the catalog information customers use to identify
                    this item.
                </p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="grid gap-2 md:col-span-2">
                    <Label for="shipping-profile">Shipping handling</Label>
                    <Select v-model="shippingProfile">
                        <SelectTrigger
                            id="shipping-profile"
                            class="w-full"
                            aria-describedby="shipping-handling-help"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="profile in shippingProfiles"
                                :key="profile.profile"
                                :value="profile.profile"
                            >
                                {{ profile.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <input
                        type="hidden"
                        name="shipping_profile"
                        :value="shippingProfile"
                    />
                    <p
                        v-if="selectedHandling"
                        id="shipping-handling-help"
                        class="text-muted-foreground text-sm"
                    >
                        {{ formatCurrency(selectedHandling.surcharge) }}
                        additional handling per delivery order
                        <template v-if="showPreparation">
                            · {{ selectedHandling.preparation_days }}
                            {{
                                selectedHandling.preparation_days === 1
                                    ? 'day'
                                    : 'days'
                            }}
                            preparation on configured routes.
                        </template>
                    </p>
                    <InputError :message="errors.shipping_profile" />
                </div>
                <div v-if="product" class="grid gap-2 md:col-span-2">
                    <Label for="product_code">Product code</Label>
                    <Input
                        id="product_code"
                        name="product_code"
                        :default-value="product?.product_code"
                        :readonly="product?.is_catalog_imported"
                        maxlength="64"
                        pattern="[A-Za-z0-9]{1,64}"
                        :aria-invalid="Boolean(errors.product_code)"
                        required
                    />
                    <p class="text-muted-foreground text-sm">
                        {{
                            product?.is_catalog_imported
                                ? 'Imported codes are fixed so future imports update the same product.'
                                : 'Use a unique code with 1 to 64 letters or digits.'
                        }}
                    </p>
                    <InputError :message="errors.product_code" />
                </div>
                <div v-else class="grid gap-2 md:col-span-2">
                    <p class="text-muted-foreground text-sm">
                        A unique product code will be assigned automatically
                        when you create this product.
                    </p>
                    <InputError :message="errors.product_code" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <h3 class="font-medium">Packed parcel measurements</h3>
                    <p class="text-muted-foreground text-sm">
                        Enter the packed item weight and outside box dimensions.
                        New products start with a category estimate that you can
                        adjust.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="package_weight_kg">Package weight (kg)</Label>
                    <Input
                        id="package_weight_kg"
                        v-model="packageWeight"
                        name="package_weight_kg"
                        type="number"
                        min="0.001"
                        step="0.001"
                        required
                        :aria-invalid="Boolean(errors.package_weight_kg)"
                    />
                    <InputError :message="errors.package_weight_kg" />
                </div>
                <div class="grid gap-2">
                    <Label for="package_length_cm">Length (cm)</Label>
                    <Input
                        id="package_length_cm"
                        v-model="packageLength"
                        name="package_length_cm"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                        :aria-invalid="Boolean(errors.package_length_cm)"
                    />
                    <InputError :message="errors.package_length_cm" />
                </div>
                <div class="grid gap-2">
                    <Label for="package_width_cm">Width (cm)</Label>
                    <Input
                        id="package_width_cm"
                        v-model="packageWidth"
                        name="package_width_cm"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                        :aria-invalid="Boolean(errors.package_width_cm)"
                    />
                    <InputError :message="errors.package_width_cm" />
                </div>
                <div class="grid gap-2">
                    <Label for="package_height_cm">Height (cm)</Label>
                    <Input
                        id="package_height_cm"
                        v-model="packageHeight"
                        name="package_height_cm"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                        :aria-invalid="Boolean(errors.package_height_cm)"
                    />
                    <InputError :message="errors.package_height_cm" />
                </div>
                <div class="grid gap-2">
                    <Label for="package_measurement_source"
                        >Measurement source</Label
                    >
                    <Select v-model="packageMeasurementSource">
                        <SelectTrigger
                            id="package_measurement_source"
                            class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="estimated"
                                >Category estimate</SelectItem
                            >
                            <SelectItem value="measured"
                                >Measured package</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <input
                        type="hidden"
                        name="package_measurement_source"
                        :value="packageMeasurementSource"
                    />
                    <InputError :message="errors.package_measurement_source" />
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <Label for="name">Product name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="product?.name"
                        maxlength="255"
                        placeholder="AMD Ryzen 7 9700X"
                        :aria-invalid="Boolean(errors.name)"
                        required
                        autofocus
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="brand">Brand (optional)</Label>
                    <Input
                        id="brand"
                        name="brand"
                        :default-value="product?.brand"
                        maxlength="255"
                        placeholder="Enter a verified brand"
                        :aria-invalid="Boolean(errors.brand)"
                    />
                    <InputError :message="errors.brand" />
                </div>

                <div class="grid gap-2">
                    <Label for="category">Category</Label>
                    <input
                        type="hidden"
                        name="category_id"
                        :value="categoryId"
                    />
                    <Select
                        v-model="categoryId"
                        :disabled="categories.length === 0"
                    >
                        <SelectTrigger
                            id="category"
                            class="w-full"
                            :aria-invalid="Boolean(errors.category_id)"
                        >
                            <SelectValue placeholder="Select a category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                                {{ category.is_active ? '' : '(Inactive)' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.category_id" />
                    <p v-if="categories.length === 0" class="text-sm">
                        Add a category before creating a product.
                        <Link
                            :href="CategoryController.create()"
                            class="text-primary-ink underline underline-offset-4"
                        >
                            Add category
                        </Link>
                    </p>
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="description">Description</Label>
                    <Textarea
                        id="description"
                        name="description"
                        :default-value="product?.description"
                        maxlength="5000"
                        rows="5"
                        placeholder="Describe the product and its important specifications."
                        :aria-invalid="Boolean(errors.description)"
                    />
                    <InputError :message="errors.description" />
                </div>
            </div>
        </section>

        <section
            aria-labelledby="pricing-heading"
            class="border-border border-t pt-8"
        >
            <div>
                <h2 id="pricing-heading" class="font-semibold">
                    Pricing and presentation
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Prices are stored and displayed in Philippine pesos.
                </p>
                <p
                    v-if="!canEditPrice"
                    class="text-muted-foreground mt-1 text-sm"
                >
                    Only the owner can change product prices.
                </p>
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="price">Regular price (PHP)</Label>
                    <Input
                        id="price"
                        name="price"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        inputmode="decimal"
                        :default-value="product?.price"
                        :readonly="!canEditPrice"
                        placeholder="0.00"
                        :aria-invalid="Boolean(errors.price)"
                        required
                    />
                    <InputError :message="errors.price" />
                </div>

                <div class="grid gap-2">
                    <Label for="discount_price">Discount price (PHP)</Label>
                    <Input
                        id="discount_price"
                        name="discount_price"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        inputmode="decimal"
                        :default-value="product?.discount_price"
                        :readonly="!canEditPrice"
                        placeholder="Optional"
                        :aria-invalid="Boolean(errors.discount_price)"
                    />
                    <InputError :message="errors.discount_price" />
                </div>
                <div v-if="canEditAcquisitionCost" class="grid gap-2">
                    <Label for="acquisition_cost">Acquisition cost (PHP)</Label>
                    <Input
                        id="acquisition_cost"
                        name="acquisition_cost"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        inputmode="decimal"
                        :default-value="product?.acquisition_cost"
                        placeholder="0.00"
                        :aria-invalid="Boolean(errors.acquisition_cost)"
                    />
                    <InputError :message="errors.acquisition_cost" />
                </div>
                <div
                    v-if="product && canEditAcquisitionCost"
                    class="grid gap-2 md:col-span-2"
                >
                    <Label for="cost_change_reason"
                        >Reason for an acquisition cost change</Label
                    >
                    <Textarea
                        id="cost_change_reason"
                        name="cost_change_reason"
                        rows="2"
                        maxlength="1000"
                        placeholder="Required when the acquisition cost changes"
                        :aria-invalid="Boolean(errors.cost_change_reason)"
                    />
                    <InputError :message="errors.cost_change_reason" />
                </div>
                <div
                    v-if="product && canEditPrice"
                    class="grid gap-2 md:col-span-2"
                >
                    <Label for="price_change_reason"
                        >Reason for a price change</Label
                    >
                    <Textarea
                        id="price_change_reason"
                        name="price_change_reason"
                        rows="2"
                        maxlength="1000"
                        placeholder="Required when the regular or discount price changes"
                        :aria-invalid="Boolean(errors.price_change_reason)"
                    />
                    <InputError :message="errors.price_change_reason" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="image">Product image</Label>
                    <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div>
                            <FileInput
                                id="image"
                                name="image"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                :aria-invalid="Boolean(errors.image)"
                                @change="updateImagePreview"
                            />
                            <p class="text-muted-foreground mt-2 text-sm">
                                JPG, JPEG, PNG, or WebP up to 5 MB. Images are
                                center-cropped to a square and saved as 1024 ×
                                1024 WebP.
                                <span v-if="product?.image_url">
                                    Leave empty to keep the current image.
                                </span>
                            </p>
                            <InputError class="mt-2" :message="errors.image" />
                        </div>
                        <div
                            class="border-border bg-muted/40 relative flex aspect-square items-center justify-center overflow-hidden border"
                        >
                            <Image class="text-muted-foreground size-7" />
                            <img
                                v-if="imagePreviewUrl && !imagePreviewFailed"
                                :key="imagePreviewUrl"
                                :src="imagePreviewUrl"
                                alt="Product image preview"
                                class="absolute inset-0 size-full object-cover"
                                @error="imagePreviewFailed = true"
                            />
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <input
                        type="hidden"
                        name="is_featured"
                        :value="isFeatured ? '1' : '0'"
                    />
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="is_featured"
                            v-model="isFeatured"
                            class="mt-0.5"
                        />
                        <div>
                            <Label for="is_featured">Featured product</Label>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Mark this product for featured catalog
                                placement.
                            </p>
                        </div>
                    </div>
                    <InputError class="mt-2" :message="errors.is_featured" />
                </div>
            </div>
        </section>

        <section
            aria-labelledby="tags-heading"
            class="border-border border-t pt-8"
        >
            <div>
                <h2 id="tags-heading" class="font-semibold">Product tags</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Select the approved tags used by catalog and recommendation
                    workflows.
                </p>
            </div>

            <div
                v-if="tags.length > 0"
                class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <label
                    v-for="tag in tags"
                    :key="tag.id"
                    :for="`tag-${tag.id}`"
                    class="border-border hover:bg-accent flex cursor-pointer items-center gap-3 border p-3 text-sm transition-colors"
                >
                    <Checkbox
                        :id="`tag-${tag.id}`"
                        :model-value="selectedTagIds.includes(tag.id)"
                        @update:model-value="setTag(tag.id, $event)"
                    />
                    {{ tag.name }}
                </label>
                <input
                    v-for="tagId in selectedTagIds"
                    :key="`selected-${tagId}`"
                    type="hidden"
                    name="tag_ids[]"
                    :value="tagId"
                />
            </div>
            <p v-else class="text-muted-foreground mt-5 text-sm">
                No product tags are available yet. This product can still be
                saved without tags.
            </p>
            <InputError class="mt-2" :message="firstTagError(errors)" />
        </section>

        <div
            class="border-border flex flex-wrap items-center gap-3 border-t pt-6"
        >
            <Button :disabled="processing || categories.length === 0">
                <Spinner v-if="processing" />
                <Save v-else />
                {{ submitLabel }}
            </Button>
            <Button type="button" variant="outline" as-child>
                <Link :href="ProductController.index()">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
