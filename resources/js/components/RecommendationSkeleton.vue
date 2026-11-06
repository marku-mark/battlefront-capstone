<script setup>
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <section class="space-y-4" aria-hidden="true">
        <h2 class="text-xl font-semibold tracking-tight">
            Suggestions for you
        </h2>
        <p class="text-muted-foreground mt-1 text-sm leading-6">
            Finding available products for you.
        </p>
        <div
            class="mt-5 grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
        >
            <article
                v-for="card in 4"
                :key="card"
                data-slot="product-card-skeleton"
                class="surface-card border-border bg-card flex min-w-0 flex-col overflow-hidden border"
            >
                <Skeleton
                    class="aspect-square w-full rounded-none sm:aspect-3/2"
                />
                <div class="product-card-content">
                    <div class="grid gap-1">
                        <Skeleton class="h-4 w-1/3" />
                        <Skeleton class="h-5 w-full" />
                        <Skeleton class="h-5 w-3/4" />
                    </div>
                    <Skeleton class="h-10 w-full" />
                    <div class="mt-auto grid gap-3">
                        <Skeleton class="h-6 w-2/3" />
                        <Skeleton class="h-4 w-1/2" />
                    </div>
                    <div class="border-border border-t pt-3">
                        <Skeleton class="h-10 w-16" />
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
