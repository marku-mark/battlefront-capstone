<script setup>
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { index as pcBuilderIndex } from '@/routes/pc-builder';

defineOptions({ layout: null });

defineProps({
    build: { type: Object, required: true },
});

function formatPrice(price) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(price ?? 0));
}
</script>

<template>
    <Head :title="`${build.name} · Shared PC Build`" />
    <div class="min-h-screen bg-zinc-950 text-zinc-100">
        <StorefrontHeader />
        <main class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
            <p
                class="text-sm font-semibold tracking-[0.2em] text-red-500 uppercase"
            >
                Shared build
            </p>
            <h1 class="mt-2 text-3xl font-bold">{{ build.name }}</h1>
            <p class="mt-2 text-zinc-400">
                {{ build.items.length }} selected component{{
                    build.items.length === 1 ? '' : 's'
                }}
            </p>
            <ul
                class="mt-8 divide-y divide-zinc-800 rounded-xl border border-zinc-800 bg-zinc-900/70 px-5"
            >
                <li
                    v-for="item in build.items"
                    :key="item.key"
                    class="flex items-center justify-between gap-4 py-4"
                >
                    <div>
                        <p class="font-medium">
                            {{ item.name || 'Catalog item unavailable' }}
                        </p>
                        <p class="mt-1 text-sm text-zinc-400">
                            {{ item.component_type.replaceAll('_', ' ') }} ·
                            quantity {{ item.quantity }}
                        </p>
                    </div>
                    <p class="shrink-0 text-sm">
                        {{
                            formatPrice(Number(item.price ?? 0) * item.quantity)
                        }}
                    </p>
                </li>
                <li
                    v-if="build.items.length === 0"
                    class="py-8 text-center text-zinc-400"
                >
                    This build has no selected components.
                </li>
            </ul>
            <p class="mt-4 text-sm text-zinc-400">
                Prices and availability can change. Sharing does not reserve
                inventory or confirm compatibility for a new configuration.
            </p>
            <Link
                :href="pcBuilderIndex.url()"
                class="mt-6 inline-flex rounded-md bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-500"
                >Start your own build</Link
            >
        </main>
    </div>
</template>
