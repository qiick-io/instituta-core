/**
 * Reading view composition (no app-ui long-form reading pattern yet).
 * Built from existing @qiick items: page-layout, page-header, scroll-area,
 * table, collapsible, marker, sources, code-block, aspect-ratio, carousel.
 * Proposed upstream for app-ui (kickoff §5 / Decisions already taken).
 * Drift check only allows overrides for installed registry files, so the
 * reason lives here instead of app-ui.overrides.json.
 */
import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CodeBlock,
    CodeBlockCopyButton,
} from '@/components/ai-elements/code-block';
import { Sources } from '@/components/ai-elements/sources';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from '@/components/ui/carousel';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Marker, MarkerContent, MarkerIcon } from '@/components/ui/marker';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Trace } from '@/components/ui/trace';
import { AspectRatio } from '@/components/ui/aspect-ratio';
import { cn } from '@/lib/utils';
import { tree } from '@/routes/brain';
import { show as tablesShow } from '@/routes/brain/tables';
import brainRoutes from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';
import type { BundledLanguage } from 'shiki';

function cellText(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return String(value);
    }

    return JSON.stringify(value);
}

type Block = {
    type: string;
    payload: Record<string, unknown>;
};

type Section = {
    anchor: string;
    heading: string;
    level: number;
    position: number;
    body: string;
    blocks: Block[];
};

type StructuredTable = {
    table_key: string;
    columns: string[];
    rows: Record<string, unknown>[];
};

type Citation = {
    source: string;
    source_id: string;
    path: string;
    commit: string | null;
};

type Item = {
    id: string;
    stable_key: string;
    title: string;
    summary: string | null;
    area: string;
    type: string;
    visibility: string;
    path: string;
    commit: string | null;
    citation: Citation;
    sections: Section[];
    tables: StructuredTable[];
};

type Props = {
    item: Item;
};

function codeLanguage(payload: Record<string, unknown>): BundledLanguage {
    const language = cellText(payload.language || payload.lang || 'text');

    return language as BundledLanguage;
}

function ImageBlocks({ blocks }: { blocks: Block[] }) {
    const imageBlocks = blocks.filter((block) => block.type === 'image');

    if (imageBlocks.length === 0) {
        return null;
    }

    const [canonical, ...gallery] = imageBlocks;
    const canonicalPath = cellText(canonical.payload.path);
    const canonicalAlt = cellText(canonical.payload.alt);

    return (
        <div className="space-y-3">
            <figure className="space-y-2">
                <AspectRatio
                    ratio={16 / 9}
                    className="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <img
                        src={canonicalPath}
                        alt={canonicalAlt}
                        className="size-full object-contain"
                    />
                </AspectRatio>
                {canonical.payload.caption ? (
                    <figcaption className="text-xs text-muted-foreground">
                        {cellText(canonical.payload.caption)}
                    </figcaption>
                ) : null}
            </figure>
            {gallery.length > 0 ? (
                <Carousel className="w-full">
                    <CarouselContent>
                        {gallery.map((block, index) => (
                            <CarouselItem
                                key={`${cellText(block.payload.path)}-${index}`}
                            >
                                <AspectRatio
                                    ratio={16 / 9}
                                    className="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border"
                                >
                                    <img
                                        src={cellText(block.payload.path)}
                                        alt={cellText(block.payload.alt)}
                                        className="size-full object-contain"
                                    />
                                </AspectRatio>
                            </CarouselItem>
                        ))}
                    </CarouselContent>
                    <CarouselPrevious />
                    <CarouselNext />
                </Carousel>
            ) : null}
        </div>
    );
}

function BlockView({ block }: { block: Block }) {
    const { t } = useTranslation();
    const payload = block.payload ?? {};

    if (block.type === 'text') {
        return (
            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                {cellText(payload.text)}
            </p>
        );
    }

    if (block.type === 'code') {
        return (
            <CodeBlock
                code={cellText(payload.source)}
                language={codeLanguage(payload)}
            >
                <CodeBlockCopyButton />
            </CodeBlock>
        );
    }

    if (block.type === 'image') {
        return null;
    }

    if (block.type === 'table' || block.type === 'data-table') {
        const columns = Array.isArray(payload.columns)
            ? (payload.columns as string[])
            : [];
        const rows = Array.isArray(payload.rows)
            ? (payload.rows as Record<string, unknown>[])
            : [];

        return (
            <Table>
                <TableHeader>
                    <TableRow>
                        {columns.map((column) => (
                            <TableHead key={column}>{column}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row, index) => (
                        <TableRow key={index}>
                            {columns.map((column) => (
                                <TableCell key={column}>
                                    {cellText(row[column])}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        );
    }

    return (
        <p className="text-sm text-muted-foreground">
            {t('brain.reading.unknownBlock', { type: block.type })}
        </p>
    );
}

/**
 * Long-form brain concept: sticky outline, citation markers, typed blocks.
 */
export default function BrainReading({ item }: Props) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.tree.title'),
                href: tree.url(),
            },
            {
                title: item.title,
                href: '#',
            },
        ],
        [item.title, t],
    );

    setLayoutProps({ breadcrumbs });

    const citationSources = [
        {
            url: brainRoutes.sources.url(),
            title: item.citation.source,
        },
        {
            url: `${brainRoutes.sources.url()}#${item.citation.path}`,
            title: item.citation.path,
        },
    ];

    return (
        <>
            <Head title={item.title} />
            <PageLayout
                description={item.summary ?? t('brain.reading.description')}
                subheader={
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {item.title}
                        </h1>
                        <Badge variant="secondary">{item.type}</Badge>
                        <Badge variant="outline">
                            {t(`brain.areas.${item.area}`, {
                                defaultValue: item.area,
                            })}
                        </Badge>
                    </div>
                }
                filters={
                    <div className="flex w-full flex-col gap-2">
                        <Marker variant="border" role="status">
                            <MarkerIcon>
                                <FileText />
                            </MarkerIcon>
                            <MarkerContent>
                                {t('brain.reading.citationSource', {
                                    source: item.citation.source,
                                })}
                                {' · '}
                                {t('brain.reading.citationPath', {
                                    path: item.citation.path,
                                })}
                                {item.citation.commit
                                    ? ` · ${t('brain.reading.citationCommit', {
                                          commit: item.citation.commit.slice(
                                              0,
                                              7,
                                          ),
                                      })}`
                                    : null}
                            </MarkerContent>
                        </Marker>
                        <Trace>
                            <Sources sources={citationSources} />
                        </Trace>
                    </div>
                }
                scrollContent
            >
                <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                    <aside className="hidden lg:block">
                        <div className="sticky top-0 space-y-2">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {t('brain.reading.outline')}
                            </p>
                            <ScrollArea className="h-[min(70vh,32rem)] pr-2">
                                <nav
                                    aria-label={t('brain.reading.outline')}
                                    className="space-y-1"
                                >
                                    {item.sections.map((section) => (
                                        <a
                                            key={section.anchor}
                                            href={`#${section.anchor}`}
                                            className={cn(
                                                'block rounded-md px-2 py-1 text-sm text-muted-foreground hover:bg-muted/40 hover:text-foreground',
                                                section.level > 2 && 'pl-4',
                                            )}
                                        >
                                            {section.heading}
                                        </a>
                                    ))}
                                </nav>
                            </ScrollArea>
                        </div>
                    </aside>

                    <article className="space-y-8">
                        {item.sections.length === 0 ? (
                            <PageEmptyState
                                icon={FileText}
                                title={item.title}
                                description={t('brain.reading.empty')}
                                compact
                            />
                        ) : (
                            item.sections.map((section) => {
                                const nonImageBlocks = section.blocks.filter(
                                    (block) => block.type !== 'image',
                                );
                                const imageBlocks = section.blocks.filter(
                                    (block) => block.type === 'image',
                                );

                                return (
                                    <section
                                        key={section.anchor}
                                        id={section.anchor}
                                        className="scroll-mt-4 space-y-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2
                                                className={cn(
                                                    'font-semibold tracking-tight',
                                                    section.level <= 2
                                                        ? 'text-lg'
                                                        : 'text-base',
                                                )}
                                            >
                                                {section.heading}
                                            </h2>
                                            <Badge variant="outline">
                                                {t(
                                                    'brain.reading.citationSection',
                                                    {
                                                        section: section.anchor,
                                                    },
                                                )}
                                            </Badge>
                                        </div>

                                        <ImageBlocks blocks={imageBlocks} />

                                        {nonImageBlocks.length > 0 ? (
                                            <div className="space-y-3">
                                                {nonImageBlocks.map(
                                                    (block, index) => (
                                                        <BlockView
                                                            key={`${section.anchor}-${index}`}
                                                            block={block}
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        ) : section.body ? (
                                            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                                {section.body}
                                            </p>
                                        ) : null}
                                    </section>
                                );
                            })
                        )}

                        {item.tables.length > 0 ? (
                            <Collapsible defaultOpen>
                                <CollapsibleTrigger className="text-sm font-medium underline-offset-4 hover:underline">
                                    {t('brain.reading.structuredTables')}
                                </CollapsibleTrigger>
                                <CollapsibleContent className="mt-3 space-y-4">
                                    {item.tables.map((table) => (
                                        <div
                                            key={table.table_key}
                                            className="space-y-2"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <h3 className="font-medium">
                                                    {table.table_key}
                                                </h3>
                                                <Link
                                                    href={tablesShow.url({
                                                        item: item.id,
                                                        table: table.table_key,
                                                    })}
                                                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                                >
                                                    {t(
                                                        'brain.reading.openTable',
                                                    )}
                                                </Link>
                                            </div>
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        {table.columns.map(
                                                            (column) => (
                                                                <TableHead
                                                                    key={column}
                                                                >
                                                                    {column}
                                                                </TableHead>
                                                            ),
                                                        )}
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {table.rows.map(
                                                        (row, index) => (
                                                            <TableRow
                                                                key={index}
                                                            >
                                                                {table.columns.map(
                                                                    (
                                                                        column,
                                                                    ) => (
                                                                        <TableCell
                                                                            key={
                                                                                column
                                                                            }
                                                                        >
                                                                            {cellText(
                                                                                row[
                                                                                    column
                                                                                ],
                                                                            )}
                                                                        </TableCell>
                                                                    ),
                                                                )}
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    ))}
                                </CollapsibleContent>
                            </Collapsible>
                        ) : null}
                    </article>
                </div>
            </PageLayout>
        </>
    );
}
