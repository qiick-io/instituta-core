import { Head, Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type ClientProduct = {
    id: string;
    name: string;
    slug: string;
};

type ClientRow = {
    id: string;
    name: string;
    slug: string;
    latest_export_id: string | null;
    products: ClientProduct[];
};

type Props = {
    is_owner: boolean;
    clients: ClientRow[];
};

function switchScope(scopeType: string, scopeId: string): void {
    router.post(
        '/brain/scope',
        {
            scope_type: scopeType,
            scope_id: scopeId,
        },
        { preserveScroll: true },
    );
}

function exportClient(clientId: string): void {
    router.post(
        `/brain/clients/${clientId}/export`,
        {},
        { preserveScroll: true },
    );
}

/**
 * Agency portal: client companies for the current organisation.
 */
export default function BrainClients({ is_owner, clients }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.clients.title')} />
            <PageLayout description={t('brain.clients.description')}>
                <div className="flex flex-wrap items-start justify-between gap-4 pb-2">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('brain.clients.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('brain.clients.hint')}
                        </p>
                    </div>
                    {is_owner ? (
                        <Button asChild>
                            <Link href="/brain/clients/create">
                                {t('brain.clients.create')}
                            </Link>
                        </Button>
                    ) : null}
                </div>

                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.clients.ownerOnly')}
                    </p>
                ) : clients.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.clients.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.clients.colClient')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.clients.colSlug')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.clients.colProducts')}
                                </TableHead>
                                <TableHead className="w-[1%] text-right">
                                    {t('brain.clients.colActions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {clients.map((client) => (
                                <TableRow key={client.id}>
                                    <TableCell className="font-medium">
                                        {client.name}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {client.slug}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {client.products.length === 0 ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {t(
                                                        'brain.clients.noProducts',
                                                    )}
                                                </span>
                                            ) : (
                                                client.products.map(
                                                    (product) => (
                                                        <Badge
                                                            key={product.id}
                                                            variant="secondary"
                                                        >
                                                            {product.name}
                                                        </Badge>
                                                    ),
                                                )
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/brain/clients/${client.id}/products`}
                                                >
                                                    {t(
                                                        'brain.clients.manageProducts',
                                                    )}
                                                </Link>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/brain/clients/${client.id}/edit`}
                                                >
                                                    {t('brain.clients.edit')}
                                                </Link>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    exportClient(client.id)
                                                }
                                            >
                                                {t('brain.clients.export')}
                                            </Button>
                                            {client.latest_export_id ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={`/brain/clients/${client.id}/exports/${client.latest_export_id}/download`}
                                                    >
                                                        {t(
                                                            'brain.clients.downloadExport',
                                                        )}
                                                    </a>
                                                </Button>
                                            ) : null}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    switchScope(
                                                        'company',
                                                        client.id,
                                                    )
                                                }
                                            >
                                                {t(
                                                    'brain.clients.switchClient',
                                                )}
                                            </Button>
                                            {client.products.map((product) => (
                                                <Button
                                                    key={product.id}
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        switchScope(
                                                            'product',
                                                            product.id,
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        'brain.clients.switchProduct',
                                                        {
                                                            name: product.name,
                                                        },
                                                    )}
                                                </Button>
                                            ))}
                                        </div>
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

BrainClients.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: '/brain/clients',
        },
    ],
};
