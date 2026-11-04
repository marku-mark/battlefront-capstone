import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
const appName = import.meta.env.VITE_APP_NAME || 'Battlefront Computer Trading';
const pages = import.meta.glob('./pages/**/*.vue');
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
        switch (true) {
            case name === 'Welcome' ||
                name.startsWith('Branches/') ||
                name.startsWith('Products/'):
                return page.props.auth?.user ? AppLayout : null;
            case name.startsWith('PcBuilder/'):
                return name === 'PcBuilder/Shared' || !page.props.auth?.user
                    ? null
                    : AppLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#EF1B1B',
    },
});
// This will listen for flash toast data from the server...
initializeFlashToast();
