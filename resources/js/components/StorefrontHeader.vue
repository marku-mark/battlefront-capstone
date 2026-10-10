<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as productIndex } from '@/routes/products';

const props = defineProps({
    activeSection: { type: String, default: null },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const storefrontLinks = [
    {
        title: 'Products',
        mobileTitle: 'Shop',
        section: 'products',
        href: productIndex(),
    },
    {
        title: 'Branches',
        mobileTitle: 'Stores',
        section: 'branches',
        href: branchIndex(),
    },
];
</script>

<template>
    <header
        v-if="!isAuthenticated"
        class="border-border bg-background/95 sticky top-0 z-30 border-b backdrop-blur"
    >
        <div
            class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-2 px-4 sm:gap-6 sm:px-8"
        >
            <Link
                :href="home()"
                aria-label="Battlefront Computer Trading home"
                class="focus-visible:ring-ring focus-visible:ring-offset-background w-20 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:outline-none sm:w-52"
            >
                <AppLogo />
            </Link>

            <nav class="flex items-center gap-1 sm:gap-2" aria-label="Primary">
                <Button
                    v-for="item in storefrontLinks"
                    :key="item.section"
                    as-child
                    size="sm"
                    :variant="
                        props.activeSection === item.section
                            ? 'outline'
                            : 'ghost'
                    "
                >
                    <Link
                        :href="item.href"
                        :aria-label="item.title"
                        :aria-current="
                            props.activeSection === item.section
                                ? 'page'
                                : undefined
                        "
                    >
                        <span class="sm:hidden">{{ item.mobileTitle }}</span>
                        <span class="hidden sm:inline">{{ item.title }}</span>
                    </Link>
                </Button>

                <Button
                    v-if="isAuthenticated"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="dashboard()">Dashboard</Link>
                </Button>
                <template v-else>
                    <Button as-child size="sm" variant="ghost">
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child size="sm" class="hidden sm:inline-flex">
                        <Link :href="register()">Register</Link>
                    </Button>
                </template>
            </nav>
        </div>
        <CustomerChatAssistant />
    </header>
</template>
