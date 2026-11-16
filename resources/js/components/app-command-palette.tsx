import { router } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CircleHelp,
    FilePenLine,
    FolderGit2,
    GitFork,
    History,
    KeyRound,
    LayoutGrid,
    FolderTree,
    MoonStar,
    ScrollText,
    Sun,
    User,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CommandPalette,
    type CommandPaletteGroup,
} from '@/components/command-palette';
import { PermissionEnum } from '@/enums/permission-enum';
import { useAppearance } from '@/hooks/use-appearance';
import { useCan } from '@/hooks/use-can';
import { toUrl } from '@/lib/utils';
import { dashboard, search as searchRoute } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { graph } from '@/routes';
import { agentCalls as sessionReplay } from '@/routes/activity';
import { index as keys } from '@/routes/api-keys';
import { index as explorerIndex } from '@/routes/explorer';
import { index as sources } from '@/routes/repositories';
import { index as clientsIndex } from '@/routes/clients';
import { index as gapsIndex } from '@/routes/review/gaps';
import { index as proposalsIndex } from '@/routes/review/proposals';
import users from '@/routes/users';

/** Server result groups (`GlobalSearchService`) and how the palette shows them. */
const SEARCH_GROUP_ICONS: Record<string, LucideIcon> = {
    items: BookOpen,
    users: User,
};

type SearchAnswer = {
    groups: {
        type: string;
        items: {
            id: string;
            label: string;
            description: string | null;
            url: string;
        }[];
    }[];
};

type NavModule = {
    id: string;
    titleKey: string;
    href: string;
    icon: LucideIcon;
    permission?: PermissionEnum;
};

/**
 * Instituta command palette (issue #130, app-ui optional `command-palette`):
 * sidebar navigation and appearance actions filtered locally, plus perimeter-scoped
 * brain item titles (and users when allowed) from `GET /search`.
 */
export function AppCommandPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const navModules = useMemo<NavModule[]>(() => {
        const modules: NavModule[] = [
            {
                id: 'dashboard',
                titleKey: 'brain.nav.dashboard',
                href: toUrl(dashboard()),
                icon: LayoutGrid,
            },
            {
                id: 'explorer',
                titleKey: 'brain.nav.explorer',
                href: toUrl(explorerIndex.url()),
                icon: FolderTree,
            },
            {
                id: 'graph',
                titleKey: 'brain.nav.graph',
                href: toUrl(graph.url()),
                icon: GitFork,
            },
            {
                id: 'sources',
                titleKey: 'settings.layout.repositories',
                href: toUrl(sources.url()),
                icon: FolderGit2,
            },
            {
                id: 'proposals',
                titleKey: 'brain.nav.proposals',
                href: toUrl(proposalsIndex.url()),
                icon: FilePenLine,
            },
            {
                id: 'gaps',
                titleKey: 'brain.nav.gaps',
                href: toUrl(gapsIndex.url()),
                icon: CircleHelp,
            },
            {
                id: 'keys',
                titleKey: 'settings.layout.apiKeys',
                href: toUrl(keys.url()),
                icon: KeyRound,
            },
            {
                id: 'clients',
                titleKey: 'brain.nav.clients',
                href: toUrl(clientsIndex.url()),
                icon: Building2,
            },
            {
                id: 'session-replay',
                titleKey: 'brain.nav.sessionReplay',
                href: toUrl(sessionReplay.url()),
                icon: History,
            },
            {
                id: 'activity',
                titleKey: 'brain.nav.activity',
                href: toUrl(activityIndex.url()),
                icon: ScrollText,
            },
            {
                id: 'users',
                titleKey: 'nav.users',
                href: toUrl(users.index.url()),
                icon: Users,
                permission: PermissionEnum.CanShowUsers,
            },
        ];

        return modules.filter(
            (module) =>
                module.permission === undefined || can(module.permission),
        );
    }, [can]);

    const groups = useMemo<CommandPaletteGroup[]>(() => {
        const isDark = resolvedAppearance === 'dark';

        return [
            {
                id: 'navigation',
                heading: t('commandPalette.groups.navigation'),
                items: navModules.map((module) => ({
                    id: `nav:${module.id}`,
                    label: t(module.titleKey),
                    icon: module.icon,
                    href: module.href,
                })),
            },
            {
                id: 'actions',
                heading: t('commandPalette.groups.actions'),
                items: [
                    {
                        id: 'action:appearance',
                        label: isDark
                            ? t('commandPalette.actions.lightMode')
                            : t('commandPalette.actions.darkMode'),
                        icon: isDark ? Sun : MoonStar,
                        onSelect: () =>
                            updateAppearance(isDark ? 'light' : 'dark'),
                    },
                ],
            },
        ];
    }, [t, navModules, resolvedAppearance, updateAppearance]);

    const search = useCallback(
        async (
            query: string,
            signal: AbortSignal,
        ): Promise<CommandPaletteGroup[]> => {
            const response = await fetch(
                searchRoute.url({ query: { query } }),
                {
                    signal,
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error(`Search failed: ${response.status}`);
            }

            const answer = (await response.json()) as SearchAnswer;

            return answer.groups.map((group) => ({
                id: group.type,
                heading: t(`commandPalette.groups.${group.type}`),
                items: group.items.map((item) => ({
                    id: item.id,
                    label: item.label,
                    description: item.description ?? undefined,
                    icon: SEARCH_GROUP_ICONS[group.type],
                    href: item.url,
                })),
            }));
        },
        [t],
    );

    return (
        <CommandPalette
            open={open}
            onOpenChange={onOpenChange}
            groups={groups}
            search={search}
            onNavigate={(href) => router.visit(href)}
            recentStorageKey="instituta.palette.recent"
        />
    );
}
