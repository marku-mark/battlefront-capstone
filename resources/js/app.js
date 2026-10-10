import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            case name === 'Welcome' ||
                name.startsWith('Branches/') ||
                name.startsWith('Products/'):
                return page.props.auth?.user ? AppLayout : null;
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
// This will set light / dark mode on page load...
initializeTheme();
// This will listen for flash toast data from the server...
initializeFlashToast();
