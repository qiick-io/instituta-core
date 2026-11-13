import { Link } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useScrollRegion } from '@/hooks/use-scroll-region';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const DEFAULT_REPORT_BUG_URL =
    'https://github.com/qiick-io/instituta-core/issues/new';

type SettingsLayoutProps = PropsWithChildren<{
    /** Wider content pane for tables / matrices when Access pages land. */
    wide?: boolean;
}>;

/**
 * Settings section layout with sidebar nav (client-only to avoid SSR mismatch).
 * Copied from externa-core: Account group only until Project / Access exist.
 */
export default function SettingsLayout({
    children,
    wide = false,
}: SettingsLayoutProps) {
    const { t } = useTranslation();
    const { isCurrentUrl } = useCurrentUrl();
    const scrollRegionRef = useScrollRegion<HTMLDivElement>();

    const accountNavItems: NavItem[] = [
        {
            title: t('settings.layout.profile'),
            href: edit(),
            icon: null,
        },
        {
            title: t('settings.layout.security'),
            href: editSecurity(),
            icon: null,
        },
        {
            title: t('settings.layout.appearance'),
            href: editAppearance(),
            icon: null,
        },
    ];

    if (typeof window === 'undefined') {
        return null;
    }

    const renderNavGroup = (label: string, items: NavItem[]) => (
        <SidebarGroup key={label} className="p-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item, index) => (
                    <SidebarMenuItem key={`${toUrl(item.href)}-${index}`}>
                        <SidebarMenuButton
                            asChild
                            size="sm"
                            isActive={isCurrentUrl(item.href)}
                        >
                            <Link href={item.href}>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );

    return (
        // App shell is h-svh + overflow-hidden; only the content pane scrolls.
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden px-4 py-6">
            <Heading
                title={t('settings.layout.title')}
                description={t('settings.layout.description')}
            />

            <div className="flex min-h-0 flex-1 flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl shrink-0 lg:w-48">
                    <nav
                        className="flex flex-col gap-2"
                        aria-label={t('settings.layout.navAria')}
                    >
                        {renderNavGroup(
                            t('settings.layout.sectionAccount'),
                            accountNavItems,
                        )}

                        <SidebarGroup className="p-0">
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton asChild size="sm">
                                        <a
                                            href={DEFAULT_REPORT_BUG_URL}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Bug />
                                            <span>
                                                {t('settings.layout.reportBug')}
                                            </span>
                                        </a>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroup>
                    </nav>
                </aside>

                <Separator className="my-6 shrink-0 lg:hidden" />

                {/* Scroll pane: sticky Save bars pin to this box, not the viewport. */}
                <div
                    ref={scrollRegionRef}
                    className={cn(
                        'min-h-0 flex-1 overflow-y-auto outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        wide ? 'md:max-w-4xl' : 'md:max-w-2xl',
                    )}
                >
                    <section
                        className={cn(
                            'space-y-12 pb-6',
                            wide ? 'max-w-4xl' : 'max-w-xl',
                        )}
                    >
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
