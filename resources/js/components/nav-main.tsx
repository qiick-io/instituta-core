import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { SidebarUnreadBadge } from '@/components/sidebar-unread-badge';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

/**
 * Platform nav group in the sidebar. Active items use the full-height left bar
 * from the registry sidebar menu button (`before:border-l-[3px]`).
 */
export function NavMain({ items }: { items: NavItem[] }) {
    const { t } = useTranslation();
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{t('nav.platform')}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const hrefPath = toUrl(item.href);
                    // Child pages (an item, a proposal) keep their section active.
                    const shouldMatchPrefix =
                        hrefPath !== '/' && hrefPath !== '/dashboard';

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={isCurrentUrl(
                                    item.href,
                                    undefined,
                                    shouldMatchPrefix,
                                )}
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                    {item.badgeCount ? (
                                        <span className="sr-only">
                                            {t('nav.openCount', {
                                                count: item.badgeCount,
                                            })}
                                        </span>
                                    ) : null}
                                </Link>
                            </SidebarMenuButton>
                            {item.badgeCount !== undefined ? (
                                <SidebarUnreadBadge
                                    count={item.badgeCount}
                                    decorative
                                />
                            ) : null}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
