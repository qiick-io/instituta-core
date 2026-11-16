import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CircleHelp,
    FilePenLine,
    FolderGit2,
    GitFork,
    History,
    KeyRound,
    LayoutGrid,
    ListTree,
    Map as MapIcon,
    PanelRight,
    ScrollText,
    Sparkles,
    Users,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { index as assistantIndex } from '@/routes/ai';
import { toggleAssistantPanel } from '@/components/ai/assistant-panel';
import AppLogo from '@/components/app-logo';
import { AppUserMenuContent } from '@/components/app-user-menu-content';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { NotificationsBell } from '@/components/notifications/notifications-bell';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Kbd } from '@/components/ui/kbd';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useCan } from '@/hooks/use-can';
import { notificationsSource } from '@/lib/notifications';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { graph, keys, map, sessionReplay, sources, tree } from '@/routes/brain';
import { index as clientsIndex } from '@/routes/brain/clients';
import { index as gapsIndex } from '@/routes/brain/gaps';
import { index as proposalsIndex } from '@/routes/brain/proposals';
import users from '@/routes/users';
import type { NavItem } from '@/types';

/**
 * Primary application sidebar: logo, Platform nav, footer links, user menu, version.
 * Users appears when the actor can show users (Access), as in externa-core.
 */
export function AppSidebar() {
    const { t } = useTranslation();
    const { can } = useCan();
    const { auth, appVersion, notifications } = usePage().props;
    const unreadCount = notifications?.unread_count ?? 0;
    const isSoundEnabled = notifications?.sound_enabled ?? false;

    const mainNavItems: NavItem[] = [
        {
            title: t('brain.nav.dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: t('brain.nav.map'),
            href: map.url(),
            icon: MapIcon,
        },
        {
            title: t('brain.nav.tree'),
            href: tree.url(),
            icon: ListTree,
        },
        {
            title: t('brain.nav.graph'),
            href: graph.url(),
            icon: GitFork,
        },
        {
            title: t('brain.nav.sources'),
            href: sources.url(),
            icon: FolderGit2,
        },
        {
            title: t('brain.nav.proposals'),
            href: proposalsIndex.url(),
            icon: FilePenLine,
        },
        {
            title: t('brain.nav.gaps'),
            href: gapsIndex.url(),
            icon: CircleHelp,
        },
        {
            title: t('brain.nav.keys'),
            href: keys.url(),
            icon: KeyRound,
        },
        {
            title: t('brain.nav.clients'),
            href: clientsIndex.url(),
            icon: Building2,
        },
        {
            title: t('brain.nav.sessionReplay'),
            href: sessionReplay.url(),
            icon: History,
        },
        {
            title: t('brain.nav.activity'),
            href: activityIndex.url(),
            icon: ScrollText,
        },
        ...(can(PermissionEnum.CanShowUsers)
            ? [
                  {
                      title: t('nav.users'),
                      href: users.index.url(),
                      icon: Users,
                  },
              ]
            : []),
    ];

    // Docs site is not public yet; keep the repository link only.
    const footerNavItems: NavItem[] = [
        {
            title: t('nav.repository'),
            href: 'https://github.com/qiick-io/instituta-core',
            icon: FolderGit2,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/*
              Real <nav> (not role=navigation on a div) so desktop chrome and the
              mobile Sheet both expose a labelled navigation landmark.
            */}
            <nav
                aria-label={t('a11y.mainNav')}
                className="flex h-full w-full flex-col"
            >
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={dashboard()} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>

                <SidebarContent>
                    {can(PermissionEnum.CanUseAi) ? (
                        <SidebarGroup className="px-2 py-0">
                            <SidebarMenu>
                                <AssistantNavItem />
                            </SidebarMenu>
                        </SidebarGroup>
                    ) : null}
                    <NavMain items={mainNavItems} />
                </SidebarContent>

                <SidebarFooter>
                    <NavFooter items={footerNavItems} className="mt-auto" />
                    <NotificationsBell
                        source={notificationsSource}
                        unreadCount={unreadCount}
                        isSoundEnabled={isSoundEnabled}
                    />
                    <NavUser user={auth.user} connectionState="disabled">
                        {auth.user ? (
                            <AppUserMenuContent user={auth.user} />
                        ) : null}
                    </NavUser>
                    {appVersion ? (
                        <p className="truncate px-2 text-center text-xs leading-none text-muted-foreground group-data-[collapsible=icon]:hidden">
                            {t('nav.appVersion', { version: appVersion })}
                        </p>
                    ) : null}
                </SidebarFooter>
            </nav>
        </Sidebar>
    );
}

/** Keyboard shortcut hint of the assistant side panel (handled in the panel). */
const ASSISTANT_PANEL_SHORTCUT =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad|iPod/.test(navigator.userAgent)
        ? '⌘J'
        : 'Ctrl+J';

/**
 * The Assistant, pinned above the Platform group (app-ui `docs/shell.md`, copied from
 * externa-core `app-sidebar.tsx`): the item opens `/ai`; on other pages it shows the ⌘J hint,
 * which gives way on hover to an action that opens the side panel in place.
 */
function AssistantNavItem() {
    const { t } = useTranslation();
    const { isCurrentUrl } = useCurrentUrl();
    const isOnAssistantPage = isCurrentUrl(assistantIndex.url());

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={isOnAssistantPage}
                tooltip={{ children: t('assistant.title') }}
                className="sidebar-ai-nav"
            >
                <Link href={assistantIndex.url()} prefetch>
                    <Sparkles />
                    <span>{t('assistant.title')}</span>
                    {!isOnAssistantPage ? (
                        <Kbd className="ml-auto transition-opacity group-focus-within/menu-item:opacity-0 group-hover/menu-item:opacity-0 group-data-[collapsible=icon]:hidden">
                            {ASSISTANT_PANEL_SHORTCUT}
                        </Kbd>
                    ) : null}
                </Link>
            </SidebarMenuButton>
            {!isOnAssistantPage ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <SidebarMenuAction
                            showOnHover
                            onClick={toggleAssistantPanel}
                            aria-label={t('assistant.openPanel')}
                        >
                            <PanelRight />
                        </SidebarMenuAction>
                    </TooltipTrigger>
                    <TooltipContent side="right">
                        {t('assistant.openPanel')} ({ASSISTANT_PANEL_SHORTCUT})
                    </TooltipContent>
                </Tooltip>
            ) : null}
        </SidebarMenuItem>
    );
}
