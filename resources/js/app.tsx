import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { initI18n } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Instituta';

// Pages wrap themselves in AppLayout / AuthLayout / SettingsLayout, as in externa-core and
// excubia-app. A persistent layout fed through setLayoutProps re-rendered pages in a loop
// when they passed header actions, and wrapped the admin pages twice (#210).
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    strictMode: true,
    withApp(app, { page }) {
        initI18n(
            typeof page.props.locale === 'string' ? page.props.locale : 'en',
        );

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
