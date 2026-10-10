<script setup>
import { Form, Head, Link } from '@inertiajs/vue3';
import { FolderTree, Pencil, Plus, RotateCcw } from '@lucide/vue';
import CategoryActivationController from '@/actions/App/Http/Controllers/Administration/CategoryActivationController';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import CatalogNavigation from '@/components/CatalogNavigation.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import PermanentDeletionDialog from '@/components/PermanentDeletionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

defineProps({
    categories: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Categories',
                href: CategoryController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Categories" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <FolderTree class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Sagay City catalog
                        </p>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            Category management
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            Maintain the approved groups used to organize
                            Battlefront hardware and peripherals.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="CategoryController.create()">
                        <Plus />
                        Add category
                    </Link>
                </Button>
            </div>
            <CatalogNavigation />
        </section>

        <section aria-labelledby="category-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ categories.total }} categories
                </p>
                <h2 id="category-list-heading" class="text-xl font-semibold">
                    Catalog categories
                </h2>
            </div>

            <div
                v-if="categories.data.length === 0"
                class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
            >
                <div>
                    <FolderTree class="text-muted-foreground mx-auto size-9" />
                    <p class="mt-3 font-medium">No categories available</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Add a category before creating catalog products.
                    </p>
                    <Button as-child class="mt-5">
                        <Link :href="CategoryController.create()">
                            <Plus />
                            Add category
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="category in categories.data"
                    :key="category.id"
                    class="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ category.name }}</h3>
                            <Badge
                                :variant="
                                    category.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ category.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <Badge variant="outline">
                                {{ category.products_count }}
                                {{
                                    category.products_count === 1
                                        ? 'product'
                                        : 'products'
                                }}
                            </Badge>
                        </div>
                        <p
                            class="text-muted-foreground mt-2 max-w-3xl text-sm leading-6"
                        >
                            {{
                                category.description ||
                                'No category description provided.'
                            }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 md:justify-end"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="CategoryController.edit(category.id)">
                                <Pencil />
                                Edit
                            </Link>
                        </Button>

                        <DeactivationDialog
                            v-if="category.is_active"
                            :name="category.name"
                            :form="
                                CategoryActivationController.form(category.id)
                            "
                            description="The category and its products will stop appearing in customer browsing. All records and product assignments are preserved for reactivation."
                        />

                        <Form
                            v-else
                            v-bind="
                                CategoryActivationController.form(category.id)
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <input type="hidden" name="is_active" value="1" />
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Reactivate
                            </Button>
                        </Form>
                        <PermanentDeletionDialog
                            :name="category.name"
                            :form="CategoryController.destroy.form(category.id)"
                            :error-bag="`deleteCategory${category.id}`"
                        />
                    </div>
                </article>
            </div>

            <CatalogPagination
                :current-page="categories.current_page"
                :last-page="categories.last_page"
                :route="CategoryController.index"
                label="Category pages"
            />
        </section>
    </main>
</template>
