import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { reading } from '@/routes/brain';
import { index as tablesIndex } from '@/routes/brain/tables';

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

type TablePayload = {
    id: string;
    table_key: string;
    item_id: string;
    item_title: string;
    item_path: string;
    columns: string[];
    rows: Record<string, unknown>[];
};

type Props = {
    table: TablePayload;
};

export default function BrainTableShow({ table }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={table.table_key} />
            <PageLayout
                description={t('brain.tables.showDescription', {
                    item: table.item_title,
                })}
                filters={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">{table.table_key}</Badge>
                        <Link
                            href={reading.url(table.item_id)}
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            {table.item_title}
                        </Link>
                        <span className="font-mono text-xs text-muted-foreground">
                            {table.item_path}
                        </span>
                    </div>
                }
            >
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {table.table_key}
                    </h1>
                </div>

                {table.rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.tables.emptyRows')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {table.columns.map((column) => (
                                    <TableHead key={column}>{column}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {table.rows.map((row, index) => (
                                <TableRow key={index}>
                                    {table.columns.map((column) => (
                                        <TableCell key={column}>
                                            {cellText(row[column])}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </PageLayout>
        </>
    );
}

BrainTableShow.layout = {
    breadcrumbs: [
        {
            title: 'Tables',
            href: tablesIndex.url(),
        },
        {
            title: 'Table',
            href: '#',
        },
    ],
};
