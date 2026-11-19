import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import CustomerChatLayout from '@/layouts/CustomerChatLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
const appName = import.meta.env.VITE_APP_NAME || 'Battlefront Computer Trading';
const pages = import.meta.glob('./pages/**/*.vue');

function pageLayout(name, user) {
    if (name.startsWith('auth/')) return AuthLayout;
    if (name.startsWith('settings/')) return [AppLayout, SettingsLayout];
    if (name === 'PcBuilder/Shared') return null;
    if (
        !user &&
        (name === 'Welcome' ||
            name.startsWith('Branches/') ||
            name.startsWith('Products/') ||
            name.startsWith('PcBuilder/'))
    ) {
        return null;
    }

    return AppLayout;
}

void createInertiaApp({
    resolve: async (name) => {
        const loadPage = pages[`./pages/${name}.vue`];
        if (!loadPage) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return loadPage();
    },
    withApp: (_app, { page }) => {
        initializeTheme(page.props);
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        const auth = page.props.auth;
        const layout = pageLayout(name, auth?.user);
        if (!layout && !auth?.user) return CustomerChatLayout;
        if (layout === AuthLayout || auth?.can?.useCustomerCart !== true) {
            return layout;
        }

        return layout ? [CustomerChatLayout, ...[layout].flat()] : CustomerChatLayout;
    },
    progress: {
        color: '#EF1B1B',
    },
});
// This will listen for flash toast data from the server...
initializeFlashToast();
