import { useState } from 'react';
import { AppCommandPalette } from '@/components/app-command-palette';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ScopeSwitcher } from '@/components/brain/scope-switcher';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { ShortcutsProvider } from '@/components/keyboard-shortcuts';
import { SkipToContent } from '@/components/skip-to-content';
import type { AppLayoutProps } from '@/types';

/**
 * Authenticated app layout with collapsible sidebar and header bar.
 * Holds keyboard shortcuts and the command palette (#130): the palette's search icon sits right
 * after the sidebar toggle (#299), the scope switcher and the page actions on the right.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    headerActions,
}: AppLayoutProps) {
    const [isPaletteOpen, setIsPaletteOpen] = useState(false);

    return (
        <ShortcutsProvider canUseSingleKeyShortcuts>
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
                        leading={
                            <CommandPaletteTrigger
                                onOpen={() => setIsPaletteOpen(true)}
                            />
                        }
                        actions={
                            <>
                                <ScopeSwitcher />
                                {headerActions}
                            </>
                        }
                    />
                    {children}
                </AppContent>
                <AppCommandPalette
                    open={isPaletteOpen}
                    onOpenChange={setIsPaletteOpen}
                />
            </AppShell>
        </ShortcutsProvider>
    );
}
