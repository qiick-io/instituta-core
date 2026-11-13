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
import {
    index as tablesIndex,
    show as tablesShow,
} from '@/routes/brain/tables';

type TablesListRow = {
    id: string;
    table_key: string;
    item_id: string;
    item_title: string;
    item_path: string;
    columns: string[];
    row_count: number;
};

type Props = {
    tables: TablesListRow[];
    hasPerimeter: boolean;
};

export default function BrainTablesIndex({ tables, hasPerimeter }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.tables.title')} />
            <PageLayout description={t('brain.tables.description')}>
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.tables.title')}
                    </h1>
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : tables.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.tables.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.tables.colKey')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.tables.colItem')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.tables.colRows')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.tables.colColumns')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tables.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={tablesShow.url({
                                                item: row.item_id,
                                                table: row.table_key,
                                            })}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {row.table_key}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col gap-0.5">
                                            <span>{row.item_title}</span>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {row.item_path}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {row.row_count}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {row.columns.join(', ')}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </PageLayout>
        </>
    );
}

BrainTablesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Tables',
            href: tablesIndex.url(),
        },
    ],
};
