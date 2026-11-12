import { reveal } from '@/lib/motion';
import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { i18n, initializeLocale } from '@/lib/i18n';

const configuredName = import.meta.env.VITE_APP_NAME;
const appName =
    configuredName && configuredName !== 'Laravel'
        ? configuredName
        : 'MtaaFlow AI';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Invoices/Public':
                return null;
            case ['auth/login', 'auth/register'].includes(name.toLowerCase()):
                return null; // these pages draw their own full-screen split layout
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        initializeLocale();
        app.use(i18n);
        app.directive('reveal', reveal);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#15966b',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
