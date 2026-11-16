import { AppAssistantPanel } from '@/components/ai/app-assistant-panel';
import { FlashToasts } from '@/components/flash-toasts';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

/**
 * Authenticated app layout wrapper. Passes header actions through to the sidebar shell.
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
    headerActions,
}: AppLayoutProps) {
    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            headerActions={headerActions}
        >
            <FlashToasts />
            {children}
            <AppAssistantPanel />
        </AppLayoutTemplate>
    );
}
