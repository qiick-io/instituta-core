import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    Package,
    Plus,
    SearchX,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    ProductFormDrawer,
    type ProductFormRow,
} from '@/components/brain/product-form-drawer';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Drawer } from '@/components/ui/drawer';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import clientsRoutes from '@/routes/brain/clients';
import type { BreadcrumbItem } from '@/types';

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
 * Products under one client company: DataTable and create/edit drawer.
 */
export default function BrainClientProducts({ client, products }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<ProductFormRow | null>(null);

    const deepLink = useDrawerDeepLink({
        onEdit: (id) => {
            const row = products.find((product) => product.id === id);

            if (!row) {
                return;
            }

            setEditing(row);
            setDrawerOpen(true);
        },
        onNew: () => {
            setEditing(null);
            setDrawerOpen(true);
        },
    });

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.clients.title'),
                href: clientsRoutes.index.url(),
            },
            {
                title: t('brain.products.title', { client: client.name }),
                href: clientsRoutes.products.index.url(client.id),
            },
        ],
        [client.id, client.name, t],
    );

    const openCreate = (): void => {
        setEditing(null);
        setDrawerOpen(true);
        deepLink.syncNew();
    };

    const openEdit = (product: ProductRow): void => {
        setEditing(product);
        setDrawerOpen(true);
        deepLink.syncEdit(product.id);
    };

    const handleDrawerOpenChange = (open: boolean): void => {
        if (open) {
            setDrawerOpen(true);

            return;
        }

        setDrawerOpen(false);
        setEditing(null);
        deepLink.syncClosed();
    };

    const filteredProducts = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return products;
        }

        return products.filter(
            (product) =>
                product.name.toLowerCase().includes(needle) ||
                product.slug.toLowerCase().includes(needle),
        );
    }, [products, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredProducts.length === 0;
    const hideListChrome = products.length === 0 && !hasActiveFilters;

    setLayoutProps({
        breadcrumbs,
        headerActions: (
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={clientsRoutes.index.url()}>
                        <ArrowLeft className="mr-1 size-4" />
                        {t('brain.products.backToClients')}
                    </Link>
                </Button>
                {!hideListChrome ? (
                    <Button type="button" onClick={openCreate}>
                        <Plus className="mr-1 size-4" />
                        {t('brain.products.create')}
                    </Button>
                ) : null}
            </div>
        ),
    });

    const columns: ColumnDef<ProductRow>[] = [
        {
            id: 'product',
            header: t('brain.products.colProduct'),
            meta: { cellClassName: 'max-w-[16rem] font-medium' },
            cell: ({ row }) => (
                <span className="flex min-w-0 flex-col gap-0.5">
                    <TruncatedText text={row.original.name} />
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.slug}
                    </span>
                </span>
            ),
        },
        {
            id: 'inherit',
            header: t('brain.products.colInherit'),
            cell: ({ row }) => (
                <Badge
                    variant={
                        row.original.inherits_from_parent
                            ? 'secondary'
                            : 'outline'
                    }
                >
                    {row.original.inherits_from_parent
                        ? t('brain.products.inheritsYes')
                        : t('brain.products.inheritsNo')}
                </Badge>
            ),
        },
        {
            id: 'actions',
            header: t('brain.products.colActions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => (
                <ButtonGroup
                    aria-label={t('brain.products.colActions')}
                    className="ml-auto"
                >
                    <HeaderIconButton
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        label={t('brain.products.switchProduct')}
                        onClick={() => switchScope('product', row.original.id)}
                    >
                        <ArrowRightLeft className="size-3.5" />
                    </HeaderIconButton>
                </ButtonGroup>
            ),
        },
    ];

    return (
        <>
            <Head title={t('brain.products.title', { client: client.name })} />

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={handleDrawerOpenChange}
            >
                <PageLayout
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={t(
                                    'brain.products.searchPlaceholder',
                                )}
                            />
                        )
                    }
                >
                    {isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : Package}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.products.filteredTitle')
                                        : t('emptyState.products.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'emptyState.products.filteredDescription',
                                      )
                                    : t('emptyState.products.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => setSearch('')}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : (
                                    <Button type="button" onClick={openCreate}>
                                        <Plus className="size-4" />
                                        {t('emptyState.products.cta')}
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={filteredProducts}
                            getRowId={(product) => product.id}
                            onRowClick={openEdit}
                            getRowProps={() => ({
                                className: 'cursor-pointer',
                            })}
                        />
                    )}
                </PageLayout>

                <ProductFormDrawer
                    clientId={client.id}
                    clientName={client.name}
                    editing={editing}
                    open={drawerOpen}
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                        deepLink.syncClosed();
                    }}
                />
            </Drawer>
        </>
    );
}
