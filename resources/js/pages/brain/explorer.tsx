import { Head } from '@inertiajs/react';
import { FileText, FolderTree, Pencil, Plus, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { BrainFileTree } from '@/components/brain/brain-file-tree';
import type {
    BrainFileNode,
    BrainTreeNode,
} from '@/components/brain/brain-file-tree';
import { ItemReader } from '@/components/brain/item-reader';
import type { Item, Section } from '@/components/brain/item-reader';
import type { Neighbourhood } from '@/components/brain/item-neighbourhood';
import {
    ProposeChangeDrawer,
    type ProposeChangeTarget,
} from '@/components/brain/propose-change-drawer';
import { EmptyStatus } from '@/components/empty-status';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { ScrollArea } from '@/components/ui/scroll-area';
import AppLayout from '@/layouts/app-layout';
import { markdownToPlainText } from '@/lib/markdown-plain-text';
import { cn } from '@/lib/utils';
import {
    index as explorerIndex,
    show as explorerShow,
} from '@/routes/explorer';

type Props = {
    nodes: BrainTreeNode[];
    total: number;
    item: Item | null;
    /** Linked neighbours for the open item; null on the index. */
    neighbourhood: Neighbourhood | null;
    hasPerimeter: boolean;
    /** False for guests: they read but never propose. */
    canPropose: boolean;
};

/**
 * Every file under the tree, for the search box: matching files list flat with their path.
 */
function flattenFiles(nodes: BrainTreeNode[]): BrainFileNode[] {
    return nodes.flatMap((node) =>
        node.kind === 'file' ? [node] : flattenFiles(node.children),
    );
}

/**
 * The brain as files (#253): the repositories' folders and files on the left (the shadcn
 * sidebar-11 file tree), the open file's reading view on the right. On a phone the tree shows
 * until a file is open, then the file, with the breadcrumb leading back.
 */
/** Whitespace-insensitive plain text, for comparing a summary with the body. */
function flatten(text: string): string {
    return markdownToPlainText(text).replace(/\s+/g, ' ').trim().toLowerCase();
}

/**
 * Whether the header should show the summary (#293): only when the body does not already say
 * it, as when the summary is the first paragraph.
 */
function summaryIsNew(item: Item): boolean {
    const summary = flatten(item.summary ?? '');

    if (summary === '') {
        return false;
    }

    return !flatten(
        item.sections.map((section) => section.body).join(' '),
    ).includes(summary);
}

export default function BrainExplorer({
    nodes,
    total,
    item,
    neighbourhood,
    hasPerimeter,
    canPropose,
}: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [proposeTarget, setProposeTarget] =
        useState<ProposeChangeTarget | null>(null);

    const breadcrumbs = [
        { title: t('brain.nav.explorer'), href: explorerIndex.url() },
        ...(item
            ? [{ title: item.title, href: explorerShow.url(item.stable_key) }]
            : []),
    ];

    const matchingFiles = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle === ''
            ? []
            : flattenFiles(nodes).filter(
                  (file) =>
                      file.name.toLowerCase().includes(needle) ||
                      file.path.toLowerCase().includes(needle),
              );
    }, [nodes, search]);

    // Sections synced before #198 have no stored markdown: they cannot be edited from here.
    const isEditable =
        item !== null &&
        canPropose &&
        item.sections.length > 0 &&
        item.sections.every((section) => section.markdown !== null);

    const proposeItemChange = (): void => {
        if (item === null) {
            return;
        }

        setProposeTarget({
            mode: 'item',
            stableKey: item.stable_key,
            path: item.path,
            area: item.area,
            title: item.title,
            baseContentHash: item.content_hash,
            markdown: item.sections
                .map((section) => section.markdown ?? '')
                .join('\n\n'),
        });
    };

    const proposeSectionChange = (section: Section): void => {
        if (
            item === null ||
            section.markdown === null ||
            section.section_hash === null
        ) {
            return;
        }

        setProposeTarget({
            mode: 'section',
            stableKey: item.stable_key,
            path: item.path,
            area: item.area,
            title: item.title,
            heading: section.heading,
            sectionAnchor: section.anchor,
            baseSectionHash: section.section_hash,
            markdown: section.markdown,
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canPropose ? (
                    <>
                        {isEditable ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={proposeItemChange}
                            >
                                <Pencil />
                                {t('brain.propose.proposeChange')}
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            onClick={() =>
                                setProposeTarget({
                                    mode: 'create',
                                    area: item?.area ?? null,
                                })
                            }
                        >
                            <Plus />
                            {t('brain.propose.newItem')}
                        </Button>
                    </>
                ) : undefined
            }
        >
            <Head title={item?.title ?? t('brain.nav.explorer')} />
            <PageLayout className="min-h-0">
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : (
                    <div className="grid min-h-0 flex-1 gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <aside
                            className={cn(
                                'flex min-h-0 flex-col gap-3 lg:border-r lg:pr-4',
                                item !== null && 'hidden lg:flex',
                            )}
                        >
                            <div className="flex items-center gap-2">
                                <FilterSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder={t(
                                        'brain.explorer.searchPlaceholder',
                                    )}
                                    className="sm:max-w-none"
                                />
                                <Badge variant="secondary" className="shrink-0">
                                    {t('brain.tree.count', {
                                        count: total,
                                        defaultValue: `${total}`,
                                    })}
                                </Badge>
                            </div>
                            <ScrollArea className="min-h-0 flex-1">
                                {search.trim() !== '' ? (
                                    matchingFiles.length === 0 ? (
                                        <PageEmptyState
                                            icon={SearchX}
                                            title={
                                                <EmptyStatus>
                                                    {t(
                                                        'brain.explorer.noMatches',
                                                    )}
                                                </EmptyStatus>
                                            }
                                            description={t(
                                                'brain.explorer.noMatchesDescription',
                                            )}
                                            compact
                                            className="border-0"
                                        />
                                    ) : (
                                        <BrainFileTree
                                            nodes={matchingFiles}
                                            activeFileId={item?.id ?? null}
                                            label={t(
                                                'brain.explorer.treeLabel',
                                            )}
                                        />
                                    )
                                ) : nodes.length === 0 ? (
                                    <PageEmptyState
                                        icon={FolderTree}
                                        title={t('brain.tree.empty')}
                                        description={t(
                                            'brain.tree.emptyDescription',
                                        )}
                                        compact
                                        className="border-0"
                                    />
                                ) : (
                                    <BrainFileTree
                                        nodes={nodes}
                                        activeFileId={item?.id ?? null}
                                        label={t('brain.explorer.treeLabel')}
                                    />
                                )}
                            </ScrollArea>
                        </aside>

                        <section
                            className={cn(
                                'min-h-0 overflow-y-auto',
                                item === null && 'hidden lg:block',
                            )}
                        >
                            {item === null ? (
                                <PageEmptyState
                                    icon={FileText}
                                    title={t('brain.explorer.pickTitle')}
                                    description={t(
                                        'brain.explorer.pickDescription',
                                    )}
                                />
                            ) : (
                                <div className="flex flex-col gap-6">
                                    <header className="flex flex-col gap-2">
                                        {summaryIsNew(item) ? (
                                            <p className="text-sm text-muted-foreground">
                                                {markdownToPlainText(
                                                    item.summary ?? '',
                                                )}
                                            </p>
                                        ) : null}
                                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                            <Badge variant="secondary">
                                                {item.type}
                                            </Badge>
                                            <Badge variant="outline">
                                                {t(`brain.areas.${item.area}`, {
                                                    defaultValue: item.area,
                                                })}
                                            </Badge>
                                            <span>
                                                {t(
                                                    'brain.reading.citationSource',
                                                    {
                                                        source: item.citation
                                                            .source,
                                                    },
                                                )}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span className="font-mono text-xs">
                                                {item.citation.path}
                                            </span>
                                            {item.citation.commit ? (
                                                <>
                                                    <span aria-hidden="true">
                                                        ·
                                                    </span>
                                                    <span className="font-mono text-xs">
                                                        {item.citation.commit.slice(
                                                            0,
                                                            7,
                                                        )}
                                                    </span>
                                                </>
                                            ) : null}
                                        </div>
                                    </header>
                                    <ItemReader
                                        item={item}
                                        neighbourhood={neighbourhood}
                                        isEditable={isEditable}
                                        onProposeSection={proposeSectionChange}
                                    />
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </PageLayout>

            <Drawer
                direction="right"
                open={proposeTarget !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setProposeTarget(null);
                    }
                }}
            >
                <ProposeChangeDrawer
                    target={proposeTarget}
                    open={proposeTarget !== null}
                    onCancel={() => setProposeTarget(null)}
                />
            </Drawer>
        </AppLayout>
    );
}
