<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Cpu,
    HardDrive,
    Headphones,
    Keyboard,
    MapPinned,
    Monitor,
    PackageCheck,
    Search,
    SlidersHorizontal,
} from '@lucide/vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';

const hardwareAreas = [
    { name: 'Processors', icon: Cpu },
    { name: 'Storage', icon: HardDrive },
    { name: 'Displays', icon: Monitor },
    { name: 'Peripherals', icon: Keyboard },
];

const customerHighlights = [
    {
        title: 'Find the right hardware',
        description:
            'Browse computer products by category, brand, and price using the catalog filters.',
        icon: Search,
    },
    {
        title: 'Choose with confidence',
        description:
            'Explore product suggestions shaped by your catalog searches, viewed products, cart, and completed purchases. You can disable search or view personalization in your profile.',
        icon: SlidersHorizontal,
    },
    {
        title: 'Keep track of your order',
        description:
            'Use your Battlefront account to manage purchases and follow order progress in one place.',
        icon: PackageCheck,
    },
];
</script>

<template>
    <div class="dark bg-background text-foreground min-h-screen">
        <Head title="Home">
            <meta
                head-key="description"
                name="description"
                content="Battlefront Computer Trading provides computer hardware, guided product discovery, and branch information."
            />
        </Head>

        <StorefrontHeader />

        <main>
            <section
                class="border-border relative overflow-hidden border-b"
                aria-labelledby="hero-heading"
            >
                <div
                    class="bg-size[48px_48px] pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,#2a2e36_1px,transparent_1px),linear-gradient(to_bottom,#2a2e36_1px,transparent_1px)] mask-[linear-gradient(to_bottom,black,transparent_82%)] opacity-35"
                    aria-hidden="true"
                />

                <div
                    class="relative mx-auto grid max-w-7xl gap-12 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-16 lg:py-28"
                >
                    <div>
                        <div
                            class="border-primary/40 bg-primary/10 mb-6 inline-flex items-center gap-2 border px-3 py-1.5 text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                        >
                            <Cpu class="size-4" aria-hidden="true" />
                            Computer hardware, made clearer
                        </div>

                        <h1
                            id="hero-heading"
                            class="max-w-3xl text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl"
                        >
                            Build your next setup with
                            <span class="text-[#ef1b1b]">confidence.</span>
                        </h1>

                        <p
                            class="text-muted-foreground mt-6 max-w-2xl text-base leading-7 sm:text-lg sm:leading-8"
                        >
                            Battlefront Computer Trading brings computer
                            hardware, guided product discovery, and local branch
                            information together for a more focused buying
                            experience.
                        </p>

                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <Button
                                v-if="$page.props.auth.user"
                                as-child
                                size="lg"
                            >
                                <Link :href="dashboard()">
                                    Go to dashboard
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button v-else as-child size="lg">
                                <Link :href="register()">
                                    Create an account
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button as-child size="lg" variant="outline">
                                <Link :href="branchIndex()">
                                    Find a branch
                                    <MapPinned aria-hidden="true" />
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div
                        class="border-border bg-card border shadow-2xl shadow-black/30"
                        aria-label="Hardware categories"
                    >
                        <div
                            class="border-border flex items-center justify-between border-b px-5 py-4"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold tracking-[0.16em] text-[#ef1b1b] uppercase"
                                >
                                    Build station
                                </p>
                                <p class="mt-1 font-semibold">
                                    Start with the essentials
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2">
                            <div
                                v-for="(area, index) in hardwareAreas"
                                :key="area.name"
                                class="group border-border motion-safe:hover:bg-secondary/60 flex min-h-36 flex-col justify-between p-5 motion-safe:transition-colors sm:min-h-44 sm:p-6"
                                :class="[
                                    index % 2 === 0 ? 'border-r' : '',
                                    index < 2 ? 'border-b' : '',
                                ]"
                            >
                                <component
                                    :is="area.icon"
                                    class="text-muted-foreground size-7 motion-safe:transition-colors motion-safe:group-hover:text-[#ef1b1b]"
                                    aria-hidden="true"
                                />
                                <p class="text-sm font-semibold sm:text-base">
                                    {{ area.name }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24"
                aria-labelledby="shopping-heading"
            >
                <div class="max-w-2xl">
                    <p
                        class="text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                    >
                        A focused buying experience
                    </p>
                    <h2
                        id="shopping-heading"
                        class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        From the first search to your order
                    </h2>
                    <p class="text-muted-foreground mt-4 leading-7">
                        Battlefront is designed around the practical steps of
                        choosing computer hardware and staying informed after
                        checkout.
                    </p>
                </div>

                <div class="border-border mt-10 grid border-y lg:grid-cols-3">
                    <article
                        v-for="(highlight, index) in customerHighlights"
                        :key="highlight.title"
                        class="border-border py-8 lg:px-8"
                        :class="[
                            index > 0
                                ? 'border-t lg:border-t-0 lg:border-l'
                                : '',
                            index === 0 ? 'lg:pl-0' : '',
                            index === customerHighlights.length - 1
                                ? 'lg:pr-0'
                                : '',
                        ]"
                    >
                        <div
                            class="border-primary/40 bg-primary/10 flex size-11 items-center justify-center border text-[#ef1b1b]"
                        >
                            <component
                                :is="highlight.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </div>
                        <h3 class="mt-6 text-xl font-bold">
                            {{ highlight.title }}
                        </h3>
                        <p class="text-muted-foreground mt-3 text-sm leading-6">
                            {{ highlight.description }}
                        </p>
                    </article>
                </div>
            </section>

            <section class="border-border bg-card border-y">
                <div
                    class="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[1fr_auto] lg:items-center"
                >
                    <div class="flex gap-5">
                        <div
                            class="border-primary/40 bg-primary/10 hidden size-12 shrink-0 items-center justify-center border text-[#ef1b1b] sm:flex"
                        >
                            <MapPinned class="size-6" aria-hidden="true" />
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                            >
                                Visit Battlefront
                            </p>
                            <h2
                                class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                            >
                                Find the branch that works for you
                            </h2>
                            <p
                                class="text-muted-foreground mt-3 max-w-2xl text-sm leading-6"
                            >
                                Review confirmed locations, contact details,
                                operating hours, and mapped branches before you
                                visit.
                            </p>
                        </div>
                    </div>

                    <Button as-child size="lg" variant="outline">
                        <Link :href="branchIndex()">
                            View all branches
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </section>
        </main>

        <footer class="mx-auto max-w-7xl px-5 py-8 sm:px-8">
            <div
                class="text-muted-foreground flex flex-col gap-4 text-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-foreground font-medium">
                    Battlefront Computer Trading
                </p>
                <p class="flex items-center gap-2">
                    <Headphones class="size-4" aria-hidden="true" />
                    Computer hardware and customer support
                </p>
            </div>
        </footer>
    </div>
</template>
