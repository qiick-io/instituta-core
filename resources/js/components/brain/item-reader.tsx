/**
 * Reading view composition (no app-ui long-form reading pattern yet).
 * Built from existing @qiick items: page-layout, page-header, scroll-area,
 * table, collapsible, badge. Proposed upstream for app-ui.
 * Drift check only allows overrides for installed registry files, so the
 * reason lives here instead of app-ui.overrides.json.
 */
import { Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { BrainMarkdown } from '@/components/brain/brain-markdown';
import {
    ItemNeighbourhood,
    type Neighbourhood,
} from '@/components/brain/item-neighbourhood';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { show as tablesShow } from '@/routes/tables';

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

export type Section = {
    anchor: string;
    heading: string;
    level: number;
    position: number;
    body: string;
    markdown: string | null;
    section_hash: string | null;
    /** The stored markdown rendered by the server (#241); '' before it was stored. */
    html: string;
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

export type ItemAsset = {
    mime: string;
    /** `image`, `pdf`, or for download-only files `archive`, `font`, `text`, `spreadsheet`, `presentation` (#315). */
    kind: string;
    extension: string;
    is_download_only: boolean;
    bytes: number;
    width: number | null;
    height: number | null;
    alt: string | null;
    caption: string | null;
    url: string;
    download_url: string;
    used_in: Array<{ title: string; stable_key: string }>;
};

export type Item = {
    id: string;
    stable_key: string;
    title: string;
    summary: string | null;
    /** Generated-block markers in the file, e.g. `qiick-standards` with its note (#295). */
    generated_blocks: Array<{ source: string; note: string }>;
    /** Set for an image or a PDF (#311): metadata and the URL the app serves it from. */
    asset: ItemAsset | null;
    area: string;
    type: string;
    visibility: string;
    path: string;
    commit: string | null;
    content_hash: string;
    citation: Citation;
    sections: Section[];
    tables: StructuredTable[];
};

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
            <pre className="overflow-x-auto rounded-lg border border-sidebar-border/70 bg-muted/40 p-3 font-mono text-xs dark:border-sidebar-border">
                <code>{cellText(payload.source)}</code>
            </pre>
        );
    }

    if (block.type === 'image') {
        return (
            <figure className="space-y-2">
                <div className="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                    <img
                        src={cellText(payload.path)}
                        alt={cellText(payload.alt)}
                        className="max-h-96 w-full object-contain"
                    />
                </div>
                {payload.caption ? (
                    <figcaption className="text-xs text-muted-foreground">
                        {cellText(payload.caption)}
                    </figcaption>
                ) : null}
            </figure>
        );
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
 * One item read in full (#241, #253): its outline, its sections rendered from markdown with a
 * propose action per section, and its structured tables. The Explorer shows it next to the file
 * tree; proposing goes back to the page, which owns the drawer.
 */
export function ItemReader({
    item,
    neighbourhood,
    isEditable,
    onProposeSection,
}: {
    item: Item;
    neighbourhood: Neighbourhood | null;
    isEditable: boolean;
    onProposeSection: (section: Section) => void;
}) {
    const { t } = useTranslation();
    const proposeSectionChange = onProposeSection;

    return (
        <div className="grid min-h-0 flex-1 gap-4 xl:grid-cols-[12rem_minmax(0,1fr)]">
            <aside className="hidden xl:block">
                <div className="sticky top-0 space-y-6">
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {t('brain.reading.outline')}
                        </p>
                        <ScrollArea className="max-h-[min(40vh,20rem)] pr-2 [&>[data-slot=scroll-area-viewport]]:max-h-[min(40vh,20rem)]">
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
                    {neighbourhood !== null ? (
                        <ItemNeighbourhood
                            centre={{
                                id: item.id,
                                title: item.title,
                                stable_key: item.stable_key,
                                area: item.area,
                            }}
                            neighbourhood={neighbourhood}
                        />
                    ) : null}
                </div>
            </aside>

            <article className="space-y-8">
                {item.sections.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.reading.empty')}
                    </p>
                ) : (
                    item.sections.map((section) => (
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
                                {isEditable ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={t(
                                            'brain.propose.editSection',
                                            {
                                                heading: section.heading,
                                            },
                                        )}
                                        onClick={() =>
                                            proposeSectionChange(section)
                                        }
                                    >
                                        <Pencil />
                                    </Button>
                                ) : null}
                            </div>

                            {section.html !== '' ? (
                                <BrainMarkdown html={section.html} />
                            ) : section.blocks.length > 0 ? (
                                <div className="space-y-3">
                                    {section.blocks.map((block, index) => (
                                        <BlockView
                                            key={`${section.anchor}-${index}`}
                                            block={block}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                    {section.body}
                                </p>
                            )}
                        </section>
                    ))
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
                                            {t('brain.reading.openTable')}
                                        </Link>
                                    </div>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                {table.columns.map((column) => (
                                                    <TableHead key={column}>
                                                        {column}
                                                    </TableHead>
                                                ))}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {table.rows.map((row, index) => (
                                                <TableRow key={index}>
                                                    {table.columns.map(
                                                        (column) => (
                                                            <TableCell
                                                                key={column}
                                                            >
                                                                {cellText(
                                                                    row[column],
                                                                )}
                                                            </TableCell>
                                                        ),
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            ))}
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </article>
        </div>
    );
}
