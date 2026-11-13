import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ScopeSwitcher } from '@/components/brain/scope-switcher';
import { SkipToContent } from '@/components/skip-to-content';
import type { AppLayoutProps } from '@/types';

/**
 * Authenticated app layout with collapsible sidebar and header bar.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <SkipToContent />
            <AppSidebar />
            <AppContent
                variant="sidebar"
                id="main-content"
                tabIndex={-1}
                className="min-w-0 overflow-x-clip"
            >
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={<ScopeSwitcher />}
                />
                {children}
            </AppContent>
        </AppShell>
    );
}
