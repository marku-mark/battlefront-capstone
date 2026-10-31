<script setup>
import { Form, Link, usePage } from '@inertiajs/vue3';
import { Heart, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { useWishlistInteractions } from '@/composables/useWishlistInteractions';
import WishlistController from '@/actions/App/Http/Controllers/WishlistController';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';

const props = defineProps({
    productId: { type: Number, required: true },
    productName: { type: String, default: 'this product' },
    isWishlisted: { type: Boolean, default: false },
    canWishlist: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});
const page = usePage();
const wishlist = useWishlistInteractions();
const isSaved = computed(() =>
    wishlist.isSaved(props.productId, props.isWishlisted),
);
const isBusy = computed(() => wishlist.isBusy(props.productId));
</script>

<template>
    <Form
        v-if="canWishlist"
        v-bind="
            isSaved
                ? WishlistController.destroy.form(productId)
                : WishlistController.store.form()
        "
        :options="wishlist.formOptions(productId, { isSaved: !isSaved })"
        :show-progress="false"
    >
        <input
            v-if="!isSaved"
            type="hidden"
            name="product_id"
            :value="productId"
        />
        <Button
            type="submit"
            variant="outline"
            :size="compact ? 'icon' : 'default'"
            :disabled="isBusy"
            :aria-label="
                isSaved
                    ? `Remove ${productName} from wishlist`
                    : `Add ${productName} to wishlist`
            "
            :aria-pressed="isSaved"
            :aria-busy="isBusy"
            class="bg-background/90"
        >
            <LoaderCircle
                v-if="isBusy"
                class="size-4 animate-spin"
                aria-hidden="true"
            />
            <Heart
                v-else
                :class="['size-4', isSaved && 'text-destructive fill-current']"
                aria-hidden="true"
            />
            <span v-if="!compact">{{ isSaved ? 'Saved' : 'Save' }}</span>
            <span v-if="isBusy" class="sr-only">Updating wishlist</span>
        </Button>
        <p
            v-if="wishlist.error(productId)"
            role="alert"
            class="text-destructive mt-1 text-xs"
        >
            {{ wishlist.error(productId) }}
        </p>
    </Form>
    <Button
        v-else-if="!page.props.auth?.user"
        as-child
        variant="outline"
        :size="compact ? 'icon' : 'default'"
        :aria-label="compact ? 'Log in to save to wishlist' : undefined"
    >
        <Link :href="login()"
            ><Heart class="size-4" aria-hidden="true" /><span v-if="!compact"
                >Log in to save</span
            ></Link
        >
    </Button>
</template>
