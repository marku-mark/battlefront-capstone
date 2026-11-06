<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { FolderTree, Pencil, Plus, RotateCcw } from '@lucide/vue';
import CategoryActivationController from '@/actions/App/Http/Controllers/Administration/CategoryActivationController';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import CatalogNavigation from '@/components/CatalogNavigation.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import RecoverableDeletionDialog from '@/components/RecoverableDeletionDialog.vue';
import SearchField from '@/components/SearchField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { computed, watch } from 'vue';

const props = defineProps({
    categories: { type: Object, required: true },
    deleted: { type: Boolean, default: false },
    filters: { type: Object, required: true },
});
const page = usePage();
const canDeleteCatalog = computed(
    () => page.props.auth?.can?.deleteCatalog === true,
);
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters.q,
    currentSearch: () => props.filters.q,
    route: CategoryController.index,
    query: () => ({ deleted: props.deleted ? 1 : undefined }),
});
watch(
    () => props.filters.q,
    (query) => {
        search.value = query ?? '';
    },
);

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
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6"
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
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canDeleteCatalog"
                        variant="outline"
                        as-child
                        class="shrink-0"
                    >
                        <Link
                            :href="
                                CategoryController.index({
                                    query: { deleted: deleted ? undefined : 1 },
                                })
                            "
                        >
                            <RotateCcw />
                            {{
                                deleted
                                    ? 'Active categories'
                                    : 'Archived categories'
                            }}
                        </Link>
                    </Button>
                    <Button v-if="!deleted" as-child class="shrink-0">
                        <Link :href="CategoryController.create()">
                            <Plus />
                            Add category
                        </Link>
                    </Button>
                </div>
            </div>
            <CatalogNavigation />
        </section>

        <section aria-labelledby="category-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ categories.total }}
                    {{ deleted ? 'archived categories' : 'categories' }}
                </p>
                <h2 id="category-list-heading" class="text-xl font-semibold">
                    {{ deleted ? 'Archived categories' : 'Catalog categories' }}
                </h2>
            </div>

            <SearchField
                id="category-search"
                v-model="search"
                class="mb-4 max-w-xl"
                label="Search categories"
                placeholder="Name or description"
                :is-searching="isSearching"
                @clear="clearSearch"
            />

            <div
                v-if="categories.data.length === 0"
                class="surface-panel border-border bg-card flex min-h-52 items-center justify-center border p-4 text-center sm:p-6"
            >
                <div>
                    <FolderTree class="text-muted-foreground mx-auto size-9" />
                    <p class="mt-3 font-medium">
                        {{
                            deleted
                                ? 'No archived categories'
                                : 'No categories available'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Add a category before creating catalog products.
                    </p>
                    <Button v-if="!deleted" as-child class="mt-5">
                        <Link :href="CategoryController.create()">
                            <Plus />
                            Add category
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
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
                                {{
                                    deleted
                                        ? 'Archived'
                                        : category.is_active
                                          ? 'Active'
                                          : 'Inactive'
                                }}
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
                        <p
                            v-if="deleted"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            Archived {{ category.deleted_at }} by
                            {{ category.deleted_by || 'an administrator' }}
                        </p>
                        <p
                            v-if="deleted && category.products_count > 0"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            Restore this category before restoring its products.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 md:justify-end"
                    >
                        <Button
                            v-if="!deleted"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="CategoryController.edit(category.id)">
                                <Pencil />
                                Edit
                            </Link>
                        </Button>

                        <DeactivationDialog
                            v-if="!deleted && category.is_active"
                            :name="category.name"
                            :form="
                                CategoryActivationController.form(category.id)
                            "
                            description="The category and its products will stop appearing in customer browsing. All records and product assignments are preserved for reactivation."
                        />

                        <Form
                            v-else-if="!deleted"
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
                        <Form
                            v-if="deleted"
                            v-bind="
                                CategoryController.restore.form(category.id)
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Restore inactive
                            </Button>
                        </Form>
                        <RecoverableDeletionDialog
                            v-if="!deleted && canDeleteCatalog"
                            :name="category.name"
                            resource-name="category"
                            collection-name="Archived categories"
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
