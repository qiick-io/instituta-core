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

type ClientSummary = {
    id: string;
    name: string;
    slug: string;
};

type ProductRow = {
    id: string;
    name: string;
    slug: string;
    inherits_from_parent: boolean;
};

type Props = {
    client: ClientSummary;
    products: ProductRow[];
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

/**
 * Products under one client company in the agency portal.
 */
export default function BrainClientProducts({ client, products }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.products.title', { client: client.name })} />
            <PageLayout
                description={t('brain.products.description', {
                    client: client.name,
                })}
            >
                <div className="flex flex-wrap items-start justify-between gap-4 pb-2">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('brain.products.title', {
                                client: client.name,
                            })}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('brain.products.hint')}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" asChild>
                            <Link href="/brain/clients">
                                {t('brain.products.backToClients')}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link
                                href={`/brain/clients/${client.id}/products/create`}
                            >
                                {t('brain.products.create')}
                            </Link>
                        </Button>
                    </div>
                </div>

                {products.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.products.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.products.colProduct')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.products.colSlug')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.products.colInherit')}
                                </TableHead>
                                <TableHead className="w-[1%] text-right">
                                    {t('brain.products.colActions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.map((product) => (
                                <TableRow key={product.id}>
                                    <TableCell className="font-medium">
                                        {product.name}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {product.slug}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                product.inherits_from_parent
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {product.inherits_from_parent
                                                ? t(
                                                      'brain.products.inheritsYes',
                                                  )
                                                : t(
                                                      'brain.products.inheritsNo',
                                                  )}
                                        </Badge>
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
                                                    href={`/brain/clients/${client.id}/products/${product.id}/edit`}
                                                >
                                                    {t('brain.products.edit')}
                                                </Link>
                                            </Button>
                                            <Button
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
                                                    'brain.products.switchProduct',
                                                )}
                                            </Button>
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

BrainClientProducts.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: '/brain/clients',
        },
        {
            title: 'Products',
            href: '/brain/clients',
        },
    ],
};
