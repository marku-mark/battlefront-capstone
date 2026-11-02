import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import * as icons from '@lucide/vue';
import { reactiveOmit } from '@vueuse/core';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import { cva } from 'class-variance-authority';
import { Primitive } from 'reka-ui';
import * as Vue from 'vue';
import { cn, toUrl } from '../lib/utils.js';

function resolveImports(code) {
    return code
        .replace(
            /import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"];?/g,
            (_, names, name) =>
                `const { ${names.replace(/\s+as\s+/g, ': ')} } = modules[${JSON.stringify(name)}];`,
        )
        .replace(
            /import\s+(\w+)\s+from\s*['"]([^'"]+)['"];?/g,
            (_, name, moduleName) =>
                `const ${name} = modules[${JSON.stringify(moduleName)}].default;`,
        );
}

function navigationHarness({
    quantity = 0,
    administrator = false,
    catalogRole = false,
    manageCatalog = false,
    mode = 'expanded',
    capabilities = {},
    workQueueCounts = {},
} = {}) {
    const sidebarState = Vue.ref(
        mode === 'collapsed' ? 'collapsed' : 'expanded',
    );
    const isMobile = Vue.ref(mode === 'mobile');
    const page = Vue.reactive({
        props: {
            cartQuantity: quantity,
            workQueueCounts,
            auth: {
                can: {
                    accessAdministration: administrator,
                    browseProductCatalog: catalogRole,
                    manageProducts: manageCatalog,
                    viewOrders: administrator,
                    useCustomerCart: !administrator,
                    ...capabilities,
                },
            },
        },
    });
    const primitive = {
        render: function () {
            return Vue.h('div', this.$slots.default?.());
        },
    };
    const route = (url) => () => ({ url, method: 'get' });
    const generic = { default: { index: route('/administration') } };
    const modules = new Proxy(
        {
            vue: Vue,
            'reka-ui': { Primitive },
            'class-variance-authority': { cva },
            '@vueuse/core': { reactiveOmit },
            '@lucide/vue': icons,
            '@inertiajs/vue3': {
                usePage: () => page,
                Link: {
                    props: ['href'],
                    render() {
                        return Vue.h(
                            'a',
                            { ...this.$attrs, href: toUrl(this.href) },
                            this.$slots.default?.(),
                        );
                    },
                },
            },
            '@/lib/utils': { cn },
            '@/routes': { dashboard: route('/dashboard') },
            '@/actions/App/Http/Controllers/Administration/BranchController': {
                index: route('/administration/branches'),
            },
            '@/actions/App/Http/Controllers/Administration/ProductController': {
                default: { index: route('/administration/products') },
            },
            '@/actions/App/Http/Controllers/Administration/ComponentSpecificationController':
                {
                    default: {
                        index: route(
                            '/administration/component-specifications',
                        ),
                    },
                },
            '@/actions/App/Http/Controllers/Administration/PcBuildTemplateController':
                {
                    default: {
                        index: route('/administration/pc-build-templates'),
                    },
                },
            '@/actions/App/Http/Controllers/Administration/PcBuildAnalyticsController':
                {
                    default: {
                        index: route('/administration/pc-build-analytics'),
                    },
                },
            '@/actions/App/Http/Controllers/Administration/InventoryController':
                {
                    default: { index: route('/administration/inventory') },
                },
            '@/routes/branches': { index: route('/branches') },
            '@/routes/cart': { index: route('/cart') },
            '@/routes/orders': { index: route('/orders') },
            '@/routes/products': { index: route('/products') },
            '@/routes/pc-builder': { index: route('/pc-builder') },
            '@/routes/profile': { edit: route('/settings/profile') },
            '@/routes/wishlist': { index: route('/wishlist') },
            '@/components/ui/tooltip': {
                Tooltip: primitive,
                TooltipContent: primitive,
                TooltipTrigger: primitive,
            },
            './utils': {
                useSidebar: () => ({
                    state: sidebarState,
                    isMobile,
                }),
            },
            '@/composables/useCurrentUrl': {
                useCurrentUrl: () => ({
                    isCurrentUrl: () => false,
                    isCurrentOrParentUrl: () => false,
                }),
            },
        },
        { get: (target, key) => target[key] ?? generic },
    );

    function component(path) {
        const { descriptor } = parse(
            readFileSync(new URL(path, import.meta.url), 'utf8'),
        );
        const script = compileScript(descriptor, {
            id: 'nav-main-test',
            inlineTemplate: true,
        });
        return new Function(
            'modules',
            resolveImports(script.content).replace('export default', 'return'),
        )(modules);
    }

    const variants = readFileSync(
        new URL('./ui/sidebar/index.js', import.meta.url),
        'utf8',
    )
        .replace(/^export \{.*$/gm, '')
        .replace('export const', 'const');
    modules['.'] = new Function(
        'modules',
        resolveImports(variants) + '\nreturn { sidebarMenuButtonVariants };',
    )(modules);
    modules['./SidebarMenuButtonChild.vue'] = {
        default: component('./ui/sidebar/SidebarMenuButtonChild.vue'),
    };
    modules['@/components/ui/sidebar'] = Object.fromEntries(
        [
            'SidebarGroup',
            'SidebarGroupLabel',
            'SidebarMenu',
            'SidebarMenuButton',
            'SidebarMenuItem',
        ].map((name) => [name, component(`./ui/sidebar/${name}.vue`)]),
    );
    modules['@/components/ui/sidebar'].useSidebar =
        modules['./utils'].useSidebar;
    const navigationSource = readFileSync(
        new URL('../composables/useAppNavigation.js', import.meta.url),
        'utf8',
    );
    const { useAppNavigation } = new Function(
        'modules',
        resolveImports(navigationSource).replace(
            'export function',
            'function',
        ) + '\nreturn { useAppNavigation };',
    )(modules);
    const navigation = useAppNavigation();
    const nav = component('./NavMain.vue');
    return {
        page,
        navigation,
        sidebarState,
        isMobile,
        render: () =>
            renderToString(
                Vue.h(
                    'div',
                    {
                        class: 'group',
                        'data-collapsible':
                            sidebarState.value === 'collapsed' &&
                            !isMobile.value
                                ? 'icon'
                                : '',
                        'data-mobile': isMobile.value ? 'true' : undefined,
                    },
                    [
                        Vue.h(nav, {
                            items: navigation.mainNavItems.value,
                            label: navigation.sectionLabel.value,
                        }),
                    ],
                ),
            ),
    };
}

function cartLink(html) {
    return html.match(/<a\b[^>]*href="\/cart"[^>]*>.*?<\/a>/s)?.[0];
}

test('PC Builder destinations have distinct icons from catalog and reporting destinations', () => {
    const admin = navigationHarness({
        administrator: true,
        manageCatalog: true,
        capabilities: { manageProducts: true, viewReports: true },
    });
    const items = admin.navigation.mainNavItems.value;
    const titles = [
        'Manage products',
        'PC specifications',
        'PC build templates',
        'PC Builder analytics',
        'Sales reports',
    ];
    assert.equal(
        new Set(
            titles.map(
                (title) => items.find((item) => item.title === title).icon,
            ),
        ).size,
        titles.length,
    );
    const customer = navigationHarness().navigation.mainNavItems.value;
    assert.notEqual(
        customer.find((item) => item.title === 'Build a PC').icon,
        customer.find((item) => item.title === 'Products').icon,
    );
});

for (const mode of ['expanded', 'collapsed', 'mobile']) {
    test(`${mode} navigation presents actionable counts and clears resolved badges`, async () => {
        const harness = navigationHarness({
            administrator: true,
            catalogRole: true,
            capabilities: { viewInventory: true },
            mode,
            workQueueCounts: {
                stock_adjustment: 2,
                customer_return: 1,
                refund: 3,
                order: 101,
            },
        });
        const items = harness.navigation.mainNavItems.value;
        assert.equal(
            items.find((item) => item.title === 'Tasks').pendingCount,
            107,
        );
        assert.equal(
            items.find((item) => item.title === 'Customer returns')
                .pendingCount,
            4,
        );
        assert.equal(
            items.find((item) => item.title === 'Stock requests').pendingCount,
            2,
        );
        const html = await harness.render();
        assert.match(html, /aria-label="Orders, 101 pending actions"/);
        assert.match(
            html,
            /data-slot="pending-actions-badge"[^>]*>99\+<\/span>/,
        );
        const badges = [
            ...html.matchAll(
                /<span\b[^>]*data-slot="pending-actions-badge"[^>]*>.*?<\/span>/gs,
            ),
        ];
        assert.equal(badges.length, 4);
        for (const [badge] of badges) {
            assert.match(badge, /aria-hidden="true"/);
            assert.match(badge, /bg-destructive-action /);
            if (mode === 'collapsed') {
                assert.match(badge, /absolute -top-1\.5 -right-1\.5/);
            } else {
                assert.match(badge, /ml-auto/);
                assert.doesNotMatch(badge, /absolute/);
            }
            assert.doesNotMatch(
                badge.match(/class="([^"]*)"/)[1],
                /hidden|truncate|overflow-hidden|group-data/,
            );
        }
        if (mode === 'collapsed') {
            assert.match(
                html,
                /class="relative size-4 shrink-0"><svg[^>]*>.*?<\/svg><span[^>]*data-slot="pending-actions-badge"/s,
            );
        } else {
            assert.match(
                html,
                /<span[^>]*>Orders<\/span><span[^>]*data-slot="pending-actions-badge"/,
            );
        }
        assert.doesNotMatch(html, /aria-label="Damage reports,/);
        harness.page.props.workQueueCounts = {};
        assert.doesNotMatch(await harness.render(), /pending-actions-badge/);
        harness.page.props.workQueueCounts = { stock_adjustment: 2 };
        harness.page.props.auth.can.viewInventory = false;
        assert.equal(
            harness.navigation.mainNavItems.value.find(
                (item) => item.title === 'Stock requests',
            ),
            undefined,
        );
    });
}

test('customer navigation does not present administration counts', async () => {
    const harness = navigationHarness({ workQueueCounts: { order: 4 } });
    assert.doesNotMatch(await harness.render(), /pending-actions-badge/);
});

for (const administrator of [false, true]) {
    test(`${administrator ? 'administration' : 'cart'} badges move between row and icon as the sidebar changes`, async () => {
        const harness = navigationHarness({
            administrator,
            catalogRole: administrator,
            quantity: 3,
            workQueueCounts: administrator ? { order: 3 } : {},
        });
        for (const [state, mobile, iconBadge] of [
            ['expanded', false, false],
            ['collapsed', false, true],
            ['collapsed', true, false],
            ['expanded', false, false],
        ]) {
            harness.sidebarState.value = state;
            harness.isMobile.value = mobile;
            const html = await harness.render();
            const badges = [
                ...html.matchAll(
                    /<span\b[^>]*data-slot="(?:cart-quantity|pending-actions)-badge"[^>]*>.*?<\/span>/gs,
                ),
            ];
            assert.equal(badges.length, administrator ? 2 : 1);
            for (const [badge] of badges) {
                assert.match(badge, />3<\/span>/);
                if (iconBadge) {
                    assert.match(badge, /absolute -top-1\.5 -right-1\.5/);
                } else {
                    assert.match(badge, /ml-auto/);
                    assert.doesNotMatch(badge, /absolute/);
                }
            }
        }
    });
}

test('store navigation follows the branch capability and reacts to permission changes', async () => {
    const harness = navigationHarness({ administrator: true });
    const stores = () =>
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Stores',
        );

    assert.equal(stores(), undefined);
    assert.doesNotMatch(
        await harness.render(),
        /href="\/(?:administration\/)?branches"/,
    );
    harness.page.props.auth.can.manageBranches = true;
    assert.equal(toUrl(stores().href), '/administration/branches');
    assert.match(await harness.render(), /href="\/administration\/branches"/);
    harness.page.props.auth.can.manageBranches = false;
    assert.equal(stores(), undefined);
    assert.doesNotMatch(
        await harness.render(),
        /href="\/(?:administration\/)?branches"/,
    );
});

for (const audience of ['guest', 'customer']) {
    test(`${audience} navigation keeps public Products and Our stores links`, async () => {
        const harness = navigationHarness();
        if (audience === 'guest') {
            harness.page.props.auth = null;
        }

        const html = await harness.render();
        assert.match(
            html,
            /href="\/products"[^>]*>.*?<span[^>]*>Products<\/span>/s,
        );
        assert.match(
            html,
            /href="\/branches"[^>]*>.*?<span[^>]*>Our stores<\/span>/s,
        );
        assert.doesNotMatch(html, /href="\/administration(?:\/|")/);
    });
}

for (const mode of ['expanded', 'collapsed', 'mobile']) {
    for (const [quantity, label] of [
        [0, null],
        [1, '1'],
        [12, '12'],
        [99, '99'],
        [100, '99+'],
    ]) {
        test(`${mode} sidebar shows ${label ?? 'no badge'} for ${quantity} cart units`, async () => {
            const html = await navigationHarness({ quantity, mode }).render();
            const cart = cartLink(html);
            assert.match(cart, /lucide-shopping-cart/);
            assert.match(
                cart,
                new RegExp(
                    `aria-label="Cart, ${quantity} ${quantity === 1 ? 'unit' : 'units'} in cart"`,
                ),
            );
            if (label === null) {
                assert.doesNotMatch(cart, /data-slot="cart-quantity-badge"/);
            } else {
                const badge = cart.match(
                    /<span\b[^>]*data-slot="cart-quantity-badge"[^>]*>.*?<\/span>/s,
                )?.[0];
                assert.match(
                    badge,
                    new RegExp(`>\\s*${label.replace('+', '\\+')}\\s*</span>`),
                );
                assert.match(badge, /aria-hidden="true"/);
                assert.match(badge, /bg-destructive-action /);
                assert.doesNotMatch(
                    badge.match(/class="([^"]*)"/)[1],
                    /hidden|truncate|overflow-hidden|group-data/,
                );
                assert.equal(
                    [...cart.matchAll(/data-slot="cart-quantity-badge"/g)]
                        .length,
                    1,
                );
                if (mode === 'collapsed') {
                    assert.match(cart, /class="relative size-4 shrink-0"/);
                    assert.match(badge, /absolute -top-1\.5 -right-1\.5/);
                } else {
                    assert.match(badge, /ml-auto/);
                    assert.doesNotMatch(badge, /absolute/);
                    assert.match(
                        cart,
                        /<span[^>]*>Cart<\/span><span[^>]*data-slot="cart-quantity-badge"/,
                    );
                }
            }
            const orders = html.match(
                /<a\b[^>]*href="\/orders"[^>]*>.*?<\/a>/s,
            )?.[0];
            assert.match(orders, /<svg\b[^>]*class="[^"]*lucide-receipt-text/);
            assert.doesNotMatch(orders, /cart-quantity-badge|relative size-4/);
        });
    }
}

test('shared count updates recompute the existing navigation and clear the badge', async () => {
    const harness = navigationHarness({ quantity: 2 });
    const cart = () =>
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Cart',
        );
    assert.equal(cart().badgeCount, 2);
    harness.page.props.cartQuantity = 103;
    assert.equal(cart().badgeCount, 103);
    assert.match(cartLink(await harness.render()), />99\+<\/span>/);
    harness.page.props.cartQuantity = 0;
    assert.doesNotMatch(
        cartLink(await harness.render()),
        /cart-quantity-badge/,
    );
});

test('administrator navigation keeps Orders and never receives a Cart badge', async () => {
    const harness = navigationHarness({ quantity: 103, administrator: true });
    const html = await harness.render();
    assert.equal(harness.navigation.sectionLabel.value, 'Administration');
    assert.equal(
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Orders',
        ).icon,
        icons.ReceiptText,
    );
    assert.equal(
        harness.navigation.mainNavItems.value.some(
            (item) => item.badgeCount !== undefined,
        ),
        false,
    );
    assert.doesNotMatch(html, /cart-quantity-badge|lucide-shopping-cart/);
});

test('business navigation uses Inventory and reacts to customizable product management permission', async () => {
    const harness = navigationHarness({
        administrator: true,
        catalogRole: true,
        capabilities: { viewInventory: true },
    });
    const inventory = () =>
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Inventory',
        );
    const manageProducts = () =>
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'Manage products',
        );

    assert.equal(toUrl(inventory().href), '/administration/inventory');
    assert.equal(manageProducts(), undefined);
    assert.doesNotMatch(
        await harness.render(),
        /href="\/(?:products|branches)"|lucide-shopping-cart/,
    );

    harness.page.props.auth.can.manageProducts = true;
    assert.equal(toUrl(manageProducts().href), '/administration/products');
    assert.match(await harness.render(), /href="\/administration\/products"/);
    assert.doesNotMatch(await harness.render(), /href="\/products"/);

    harness.page.props.auth.can.manageProducts = false;
    assert.equal(manageProducts(), undefined);
    assert.equal(toUrl(inventory().href), '/administration/inventory');
    harness.page.props.auth.can.viewInventory = false;
    assert.equal(inventory(), undefined);
    assert.ok(
        harness.navigation.mainNavItems.value.some(
            (item) => item.title === 'Tasks',
        ),
    );
});

test('Manager and Owner navigation retains permitted management links without public shopping links', async () => {
    const harness = navigationHarness({
        administrator: true,
        catalogRole: true,
        manageCatalog: true,
        capabilities: { manageBranches: true },
    });

    assert.equal(
        toUrl(
            harness.navigation.mainNavItems.value.find(
                (item) => item.title === 'Manage products',
            ).href,
        ),
        '/administration/products',
    );
    assert.equal(
        toUrl(
            harness.navigation.mainNavItems.value.find(
                (item) => item.title === 'Stores',
            ).href,
        ),
        '/administration/branches',
    );
    assert.doesNotMatch(
        await harness.render(),
        /href="\/(?:products|branches|cart)"/,
    );
    assert.ok(
        harness.navigation.mainNavItems.value.some(
            (item) => item.title === 'Tasks',
        ),
    );
});

test('permission-based administration navigation keeps granted links without business modules', async () => {
    const harness = navigationHarness({
        administrator: true,
        capabilities: { viewOrders: false, manageUsers: true, viewAudit: true },
    });

    assert.deepEqual(
        harness.navigation.mainNavItems.value.map((item) => item.title),
        ['Dashboard', 'Activity log', 'Team', 'Account'],
    );
    assert.doesNotMatch(
        await harness.render(),
        /href="\/(?:products|branches|cart|administration\/(?:products|branches|inventory))"/,
    );
});

test('administration tasks have distinct icons and clear labels without granting access', async () => {
    const harness = navigationHarness({
        administrator: true,
        catalogRole: true,
        capabilities: {
            viewInventory: true,
            manageSuppliers: true,
            viewAudit: true,
            manageUsers: true,
            viewStaffReports: true,
            viewReports: true,
        },
    });
    const expected = {
        Tasks: icons.ListTodo,
        'Stock requests': icons.ClipboardList,
        'Stock checks': icons.ClipboardCheck,
        'Damage reports': icons.PackageX,
        'Supplier returns': icons.Truck,
        'Customer returns': icons.Undo2,
        Suppliers: icons.Handshake,
        'Sales reports': icons.ChartPie,
        'Sales by handler': icons.ChartColumnIncreasing,
        'Activity log': icons.Logs,
        Team: icons.UserRoundCog,
    };

    const html = await harness.render();
    for (const [title, icon] of Object.entries(expected)) {
        const item = harness.navigation.mainNavItems.value.find(
            (entry) => entry.title === title,
        );
        assert.equal(item?.icon, icon, title);
        assert.ok(html.includes(title), title + ' is visible');
    }
    assert.equal(new Set(Object.values(expected)).size, 11);
    for (const svg of html.matchAll(/<svg\b[^>]*>/g)) {
        assert.match(svg[0], /aria-hidden="true"/);
    }

    harness.page.props.auth.can.viewInventory = false;
    harness.page.props.auth.can.manageSuppliers = false;
    harness.page.props.auth.can.viewAudit = false;
    harness.page.props.auth.can.manageUsers = false;
    harness.page.props.auth.can.viewStaffReports = false;
    harness.page.props.auth.can.viewReports = false;
    const titles = harness.navigation.mainNavItems.value.map(
        (item) => item.title,
    );
    assert.ok(titles.includes('My sales'));
    assert.equal(
        harness.navigation.mainNavItems.value.find(
            (item) => item.title === 'My sales',
        ).icon,
        icons.ChartColumnIncreasing,
    );
    harness.page.props.auth.can.viewOrders = false;
    assert.ok(
        !harness.navigation.mainNavItems.value.some(
            (item) => item.title === 'My sales',
        ),
    );
    for (const hidden of [
        'Stock requests',
        'Stock checks',
        'Damage reports',
        'Supplier returns',
        'Suppliers',
        'Activity log',
        'Team',
        'Sales reports',
    ]) {
        assert.ok(
            !titles.includes(hidden),
            hidden + ' requires its existing permission',
        );
    }
});
