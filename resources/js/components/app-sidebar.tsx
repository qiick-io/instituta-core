import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    FolderGit2,
    FilePenLine,
    History,
    KeyRound,
    LayoutGrid,
    ListTree,
    Map as MapIcon,
    ScrollText,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { UserMenuContent } from '@/components/user-menu-content';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { dashboard, logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/qiick-io/instituta-core',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const cleanup = useMobileNavigation();

    const mainNavItems: NavItem[] = [
        {
            title: t('brain.nav.dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: t('brain.nav.map'),
            href: '/brain/map',
            icon: MapIcon,
        },
        {
            title: t('brain.nav.tree'),
            href: '/brain/tree',
            icon: ListTree,
        },
        {
            title: t('brain.nav.sources'),
            href: '/brain/sources',
            icon: FolderGit2,
        },
        {
            title: t('brain.nav.proposals'),
            href: '/brain/proposals',
            icon: FilePenLine,
        },
        {
            title: t('brain.nav.keys'),
            href: '/brain/keys',
            icon: KeyRound,
        },
        {
            title: t('brain.nav.clients'),
            href: '/brain/clients',
            icon: Building2,
        },
        {
            title: t('brain.nav.sessionReplay'),
            href: '/brain/session-replay',
            icon: History,
        },
        {
            title: t('brain.nav.activity'),
            href: '/activity',
            icon: ScrollText,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser user={auth.user}>
                    {auth.user ? (
                        <UserMenuContent
                            user={auth.user}
                            settingsHref={editProfile()}
                            logoutHref={logout()}
                            onNavigate={cleanup}
                        />
                    ) : null}
                </NavUser>
            </SidebarFooter>
        </Sidebar>
    );
}
