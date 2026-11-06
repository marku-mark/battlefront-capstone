<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Menu } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GuestAppearanceToggle from '@/components/GuestAppearanceToggle.vue';
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { dashboard, home, login, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as productIndex } from '@/routes/products';
import { index as pcBuilderIndex } from '@/routes/pc-builder';

const props = defineProps({
    activeSection: { type: String, default: null },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const storefrontLinks = [
    {
        title: 'Build a PC',
        mobileTitle: 'Builder',
        section: 'pc-builder',
        href: pcBuilderIndex(),
    },
    {
        title: 'Products',
        mobileTitle: 'Shop',
        section: 'products',
        href: productIndex(),
    },
    {
        title: 'Our stores',
        mobileTitle: 'Stores',
        section: 'branches',
        href: branchIndex(),
    },
];
</script>

<template>
    <header
        v-if="!isAuthenticated"
        class="border-border bg-card/95 sticky top-0 z-30 border-b backdrop-blur"
    >
        <div
            class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-2 px-4 sm:gap-6 sm:px-8"
        >
            <Link
                :href="home()"
                aria-label="Battlefront Computer Trading home"
                class="focus-visible:ring-ring focus-visible:ring-offset-background w-20 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:outline-none sm:w-52"
            >
                <AppLogo class="hidden sm:block" />
                <AppLogoIcon class="mx-auto size-10 sm:hidden" />
            </Link>

            <nav class="hidden items-center gap-2 sm:flex" aria-label="Primary">
                <Button
                    v-for="item in storefrontLinks"
                    :key="item.section"
                    as-child
                    size="sm"
                    class="min-w-11 px-3"
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
                        {{ item.title }}
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
                    <Button
                        as-child
                        size="sm"
                        variant="ghost"
                        class="min-w-11 px-3"
                    >
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child size="sm" class="hidden sm:inline-flex">
                        <Link :href="register()">Register</Link>
                    </Button>
                </template>
                <GuestAppearanceToggle />
            </nav>
            <div class="flex items-center gap-2 sm:hidden">
                <GuestAppearanceToggle />
                <Sheet>
                    <SheetTrigger :as-child="true">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Open menu"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="right"
                        class="w-[min(20rem,calc(100vw-2rem))] overflow-y-auto px-5 pt-12"
                    >
                        <SheetHeader>
                            <SheetTitle>Menu</SheetTitle>
                        </SheetHeader>
                        <nav
                            class="flex flex-col gap-2"
                            aria-label="Mobile primary"
                        >
                            <Button
                                v-for="item in storefrontLinks"
                                :key="item.section"
                                as-child
                                variant="ghost"
                                class="min-h-11 justify-start"
                            >
                                <Link
                                    :href="item.href"
                                    :aria-current="
                                        props.activeSection === item.section
                                            ? 'page'
                                            : undefined
                                    "
                                >
                                    {{ item.title }}
                                </Link>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                class="min-h-11 justify-start"
                            >
                                <Link :href="login()">Log in</Link>
                            </Button>
                            <Button as-child class="min-h-11 justify-start">
                                <Link :href="register()">Register</Link>
                            </Button>
                        </nav>
                    </SheetContent>
                </Sheet>
            </div>
        </div>
        <CustomerChatAssistant />
    </header>
</template>
