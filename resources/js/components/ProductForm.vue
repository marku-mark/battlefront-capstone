<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { Image, Save } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import InputError from '@/components/InputError.vue';
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
    product: { type: Object, default: null },
    form: { type: Object, required: true },
    methodOverride: { type: String, default: null },
    submitLabel: { type: String, required: true },
});

const categoryId = ref(
    String(props.product?.category_id ?? props.categories[0]?.id ?? ''),
);
const isFeatured = ref(props.product?.is_featured ?? false);
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
                            class="text-primary underline underline-offset-4"
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
                        placeholder="Optional"
                        :aria-invalid="Boolean(errors.discount_price)"
                    />
                    <InputError :message="errors.discount_price" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="image">Product image</Label>
                    <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div>
                            <Input
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
