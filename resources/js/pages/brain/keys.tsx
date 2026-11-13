import { Head } from '@inertiajs/react';
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

type KeyRow = {
    id: string;
    name: string;
    display_prefix: string;
    scope_type: string;
    scope_id: string;
    role: string;
    max_visibility: string;
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    revoked: boolean;
};

type Props = {
    is_owner: boolean;
    keys: KeyRow[];
};

export default function BrainKeys({ is_owner, keys }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.keys.title')} />
            <PageLayout description={t('brain.keys.description')}>
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.keys.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.keys.artisanHint')}
                    </p>
                </div>

                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.keys.ownerOnly')}
                    </p>
                ) : keys.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.keys.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('brain.keys.colName')}</TableHead>
                                <TableHead>
                                    {t('brain.keys.colPrefix')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.keys.colScope')}
                                </TableHead>
                                <TableHead>{t('brain.keys.colRole')}</TableHead>
                                <TableHead>
                                    {t('brain.keys.colLastUsed')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.keys.colStatus')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {keys.map((key) => (
                                <TableRow key={key.id}>
                                    <TableCell className="font-medium">
                                        {key.name}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {key.display_prefix}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {key.scope_type}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>{key.role}</TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {key.last_used_at ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {key.revoked ? (
                                            <Badge variant="destructive">
                                                {t('brain.keys.revoked')}
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                {t('brain.keys.active')}
                                            </Badge>
                                        )}
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

BrainKeys.layout = {
    breadcrumbs: [
        {
            title: 'Keys',
            href: '/brain/keys',
        },
    ],
};
