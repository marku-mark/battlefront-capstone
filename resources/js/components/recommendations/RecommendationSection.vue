<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import { Button } from '@/components/ui/button';
import { store as storeRecommendationInteraction } from '@/routes/recommendations/interactions';
import { show as productShow } from '@/routes/products';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, required: true },
    placement: { type: String, required: true },
    recommendations: { type: Array, default: () => [] },
});

const page = usePage();
const recommendationCards = ref([]);
const dismissedProductIds = ref(new Set());
const visibleRecommendations = computed(() =>
    props.recommendations.filter(
        (recommendation) =>
            !dismissedProductIds.value.has(recommendation.product.id),
    ),
);
const visibleCards = new Set();
const impressionTimers = new Map();
let observer;
let isMounted = false;
let observationVersion = 0;

function createEventId() {
    if (!globalThis.crypto?.getRandomValues) {
        return null;
    }

    if (globalThis.crypto.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

function recordInteraction(recommendation, eventType, position) {
    const eventId = createEventId();

    if (!eventId) {
        return;
    }

    const route = storeRecommendationInteraction.post();
    const xsrfToken = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

    fetch(route.url, {
        method: route.method.toUpperCase(),
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken,
        },
        body: JSON.stringify({
            event_id: eventId,
            product_id: recommendation.product.id,
            event_type: eventType,
            placement: props.placement,
            position,
            reason_code: recommendation.reasons[0]?.code ?? null,
        }),
    }).catch(() => {});
}

function recommendationPosition(recommendation) {
    return (
        visibleRecommendations.value.findIndex(
            (item) => item.product.id === recommendation.product.id,
        ) + 1
    );
}

function dismissRecommendation(recommendation, eventType) {
    recordInteraction(
        recommendation,
        eventType,
        recommendationPosition(recommendation),
    );

    const nextDismissedIds = new Set(dismissedProductIds.value);
    nextDismissedIds.add(recommendation.product.id);
    dismissedProductIds.value = nextDismissedIds;

    try {
        const storage = page.props.auth?.user ? localStorage : sessionStorage;
        const saved = readDismissals();
        saved[recommendation.product.id] = Date.now();
        const entries = Object.entries(saved).slice(-100);
        storage.setItem(
            dismissalStorageKey.value,
            JSON.stringify(Object.fromEntries(entries)),
        );
    } catch {
        // Recommendation feedback should never interrupt shopping.
    }
}

const dismissalStorageKey = computed(() => {
    const customerId = page.props.auth?.user?.id;

    return customerId
        ? `battlefront:dismissed-recommendations:v2:customer:${customerId}`
        : `battlefront:dismissed-recommendations:v2:guest:${page.props.guest_recommendation_scope ?? 'session'}`;
});

function readDismissals() {
    const storage = page.props.auth?.user ? localStorage : sessionStorage;
    const saved = JSON.parse(
        storage.getItem(dismissalStorageKey.value) ?? '{}',
    );
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return {};
    return Object.fromEntries(
        Object.entries(saved).filter(
            ([id, time]) =>
                Number.isInteger(Number(id)) &&
                Number(id) > 0 &&
                Number.isFinite(time) &&
                time > Date.now() - 90 * 24 * 60 * 60 * 1000 &&
                time <= Date.now(),
        ),
    );
}

function loadDismissedRecommendations() {
    try {
        localStorage.removeItem(
            'battlefront:dismissed-recommendations:v1:guest',
        );
        const storage = page.props.auth?.user ? localStorage : sessionStorage;
        const saved = readDismissals();
        storage.setItem(dismissalStorageKey.value, JSON.stringify(saved));
        dismissedProductIds.value = new Set(Object.keys(saved).map(Number));
    } catch {
        dismissedProductIds.value = new Set();
    }
}

function clearImpressionObservation() {
    observationVersion++;
    observer?.disconnect();
    observer = undefined;
    visibleCards.clear();
    impressionTimers.forEach((timer) => window.clearTimeout(timer));
    impressionTimers.clear();
}

function observeRecommendationCards() {
    if (
        document.visibilityState !== 'visible' ||
        !('IntersectionObserver' in window)
    ) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                const card = entry.target;

                if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
                    visibleCards.add(card);

                    if (!impressionTimers.has(card)) {
                        const timer = window.setTimeout(() => {
                            const index = Number(
                                card.dataset.recommendationPosition,
                            );
                            const productId = Number(
                                card.dataset.recommendationProductId,
                            );
                            const recommendation =
                                visibleRecommendations.value.find(
                                    (item) => item.product.id === productId,
                                );

                            if (
                                document.visibilityState === 'visible' &&
                                visibleCards.has(card) &&
                                recommendation &&
                                card.dataset.impressionTracked !== 'true'
                            ) {
                                card.dataset.impressionTracked = 'true';
                                recordInteraction(
                                    recommendation,
                                    'impression',
                                    index,
                                );
                            }

                            impressionTimers.delete(card);
                        }, 1000);

                        impressionTimers.set(card, timer);
                    }

                    return;
                }

                visibleCards.delete(card);
                const timer = impressionTimers.get(card);

                if (timer) {
                    window.clearTimeout(timer);
                    impressionTimers.delete(card);
                }
            });
        },
        { threshold: 0.5 },
    );

    recommendationCards.value.forEach((card) => {
        if (card) {
            observer.observe(card);
        }
    });
}

async function refreshObservation() {
    clearImpressionObservation();
    const version = observationVersion;
    await nextTick();
    if (isMounted && version === observationVersion)
        observeRecommendationCards();
}

onMounted(() => {
    isMounted = true;
    loadDismissedRecommendations();
    refreshObservation();
    document.addEventListener('visibilitychange', refreshObservation);
});

watch(visibleRecommendations, refreshObservation);
watch(dismissalStorageKey, loadDismissedRecommendations);

onBeforeUnmount(() => {
    isMounted = false;
    document.removeEventListener('visibilitychange', refreshObservation);
    clearImpressionObservation();
});
</script>

<template>
    <section
        v-if="recommendations.length > 0"
        class="border-border border-t pt-10"
        aria-labelledby="recommendation-section-heading"
    >
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                >
                    Product recommendations
                </p>
                <h2
                    id="recommendation-section-heading"
                    class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                >
                    {{ title }}
                </h2>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    {{ description }}
                </p>
            </div>
        </div>

        <p
            v-if="visibleRecommendations.length === 0"
            class="text-muted-foreground mt-5 text-sm"
            role="status"
        >
            You have hidden these suggestions. Browse the catalog for more
            products.
        </p>
        <div v-else class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="(recommendation, index) in visibleRecommendations"
                :key="recommendation.product.id"
                ref="recommendationCards"
                :data-recommendation-position="index + 1"
                :data-recommendation-product-id="recommendation.product.id"
                class="border-border bg-card flex min-w-0 flex-col overflow-hidden border"
            >
                <Link
                    :href="productShow(recommendation.product.id)"
                    @click="
                        recordInteraction(recommendation, 'click', index + 1)
                    "
                    class="focus-visible:ring-ring block focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    :aria-label="`View ${recommendation.product.name}`"
                >
                    <div class="aspect-3/2 overflow-hidden">
                        <ProductImage
                            :image-url="recommendation.product.image_url"
                            :product-name="recommendation.product.name"
                        />
                    </div>
                </Link>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="min-w-0">
                        <p
                            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                        >
                            {{ recommendation.product.category.name }}
                        </p>
                        <Link
                            :href="productShow(recommendation.product.id)"
                            @click="
                                recordInteraction(
                                    recommendation,
                                    'click',
                                    index + 1,
                                )
                            "
                            class="focus-visible:ring-ring mt-1 block rounded-sm text-base leading-snug font-bold focus-visible:ring-2 focus-visible:outline-none"
                        >
                            {{ recommendation.product.name }}
                        </Link>
                    </div>

                    <p
                        class="text-muted-foreground line-clamp-2 text-xs leading-5"
                    >
                        {{ recommendation.reasons[0]?.value }}
                    </p>

                    <div
                        class="mt-auto flex flex-wrap items-end justify-between gap-3"
                    >
                        <ProductPrice
                            :price="recommendation.product.price"
                            :discount-price="
                                recommendation.product.discount_price
                            "
                        />
                        <StockAvailability
                            :inventory="recommendation.product.inventory"
                        />
                    </div>

                    <div
                        class="border-border flex flex-wrap gap-2 border-t pt-3"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-10 px-2 text-xs"
                            @click="
                                dismissRecommendation(recommendation, 'dismiss')
                            "
                        >
                            Hide
                        </Button>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
