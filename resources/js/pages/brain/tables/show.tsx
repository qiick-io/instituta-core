import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as reading } from '@/routes/explorer';
import { index as tablesIndex, show as tablesShow } from '@/routes/tables';
import AppLayout from '@/layouts/app-layout';
import { Sheet } from 'lucide-react';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';

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
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.tables.title'),
                href: tablesIndex.url(),
            },
            {
                title: table.table_key,
                href: tablesShow.url({
                    item: table.item_id,
                    table: table.table_key,
                }),
            },
        ],
    };

    return (
        <AppLayout {...layoutProps}>
            <Head title={table.table_key} />
            <PageLayout
                description={t('brain.tables.showDescription', {
                    item: table.item_title,
                })}
                filters={
                    <div className="flex flex-wrap items-center gap-2">
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
                {table.rows.length === 0 ? (
                    <PageEmptyState
                        icon={Sheet}
                        title={
                            <EmptyStatus>
                                {t('brain.tables.emptyRows')}
                            </EmptyStatus>
                        }
                        description={t('brain.tables.emptyDescription')}
                    />
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
        </AppLayout>
    );
}
