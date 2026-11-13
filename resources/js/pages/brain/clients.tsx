import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Building2,
    Download,
    FolderOutput,
    Package,
    Plus,
    SearchX,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import ClientEraseController from '@/actions/App/Http/Controllers/Brain/ClientEraseController';
import ClientExportController from '@/actions/App/Http/Controllers/Brain/ClientExportController';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    ClientFormDrawer,
    type ClientFormRow,
} from '@/components/brain/client-form-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Drawer } from '@/components/ui/drawer';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import { scope } from '@/routes/brain';
import clientsRoutes from '@/routes/brain/clients';
import type { BreadcrumbItem } from '@/types';

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
    latest_export_status: string | null;
    products: ClientProduct[];
};

type Props = {
    is_owner: boolean;
    clients: ClientRow[];
};

function switchScope(scopeType: string, scopeId: string): void {
    router.post(
        scope.url(),
        {
            scope_type: scopeType,
            scope_id: scopeId,
        },
        { preserveScroll: true },
    );
}

/**
 * Agency portal clients list: DataTable, create/edit drawer, erase confirm.
 */
export default function BrainClients({ is_owner, clients }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<ClientFormRow | null>(null);
    const [pendingErase, setPendingErase] = useState<ClientRow | null>(null);
    const [erasing, setErasing] = useState(false);

    const deepLink = useDrawerDeepLink({
        onEdit: (id) => {
            if (!is_owner) {
                return;
            }

            const row = clients.find((client) => client.id === id);

            if (!row) {
                return;
            }

            setEditing({ id: row.id, name: row.name, slug: row.slug });
            setDrawerOpen(true);
        },
        onNew: () => {
            if (!is_owner) {
                return;
            }

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
        ],
        [t],
    );

    const openCreate = (): void => {
        setEditing(null);
        setDrawerOpen(true);
        deepLink.syncNew();
    };

    const openEdit = (client: ClientRow): void => {
        if (!is_owner) {
            return;
        }

        setEditing({ id: client.id, name: client.name, slug: client.slug });
        setDrawerOpen(true);
        deepLink.syncEdit(client.id);
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

    const filteredClients = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return clients;
        }

        return clients.filter(
            (client) =>
                client.name.toLowerCase().includes(needle) ||
                client.slug.toLowerCase().includes(needle),
        );
    }, [clients, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredClients.length === 0;
    const hideListChrome =
        !is_owner || (clients.length === 0 && !hasActiveFilters);

    setLayoutProps({
        breadcrumbs,
        headerActions:
            is_owner && !hideListChrome ? (
                <Button type="button" onClick={openCreate}>
                    <Plus className="mr-1 size-4" />
                    {t('brain.clients.create')}
                </Button>
            ) : undefined,
    });

    const columns: ColumnDef<ClientRow>[] = [
        {
            id: 'client',
            header: t('brain.clients.colClient'),
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
            id: 'products',
            header: t('brain.clients.colProducts'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.products.length === 0 ? (
                        <span className="text-sm text-muted-foreground">
                            {t('brain.clients.noProducts')}
                        </span>
                    ) : (
                        row.original.products.map((product) => (
                            <Badge key={product.id} variant="secondary">
                                {product.name}
                            </Badge>
                        ))
                    )}
                </div>
            ),
        },
        {
            id: 'actions',
            header: t('brain.clients.colActions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => {
                const client = row.original;
                const canErase =
                    client.latest_export_id !== null &&
                    client.latest_export_status === 'exported';

                return (
                    <ButtonGroup
                        aria-label={t('brain.clients.colActions')}
                        className="ml-auto"
                    >
                        <HeaderIconButton
                            variant="outline"
                            size="icon-sm"
                            label={t('brain.clients.manageProducts')}
                            asChild
                        >
                            <Link
                                href={clientsRoutes.products.index.url(
                                    client.id,
                                )}
                            >
                                <Package className="size-3.5" />
                            </Link>
                        </HeaderIconButton>
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            label={t('brain.clients.export')}
                            onClick={() =>
                                router.post(
                                    ClientExportController.store.url(client.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <FolderOutput className="size-3.5" />
                        </HeaderIconButton>
                        {client.latest_export_id ? (
                            <HeaderIconButton
                                variant="outline"
                                size="icon-sm"
                                label={t('brain.clients.downloadExport')}
                                asChild
                            >
                                <a
                                    href={ClientExportController.download.url({
                                        company: client.id,
                                        offboarding: client.latest_export_id,
                                    })}
                                >
                                    <Download className="size-3.5" />
                                </a>
                            </HeaderIconButton>
                        ) : null}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <HeaderIconButton
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    label={t('brain.clients.switchClient')}
                                >
                                    <ArrowRightLeft className="size-3.5" />
                                </HeaderIconButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() =>
                                        switchScope('company', client.id)
                                    }
                                >
                                    {t('brain.clients.switchClient')}
                                </DropdownMenuItem>
                                {client.products.map((product) => (
                                    <DropdownMenuItem
                                        key={product.id}
                                        onSelect={() =>
                                            switchScope('product', product.id)
                                        }
                                    >
                                        {t('brain.clients.switchProduct', {
                                            name: product.name,
                                        })}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {canErase ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                className="text-destructive hover:text-destructive"
                                label={t('brain.clients.erase')}
                                onClick={() => setPendingErase(client)}
                            >
                                <Trash2 className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                    </ButtonGroup>
                );
            },
        },
    ];

    return (
        <>
            <Head title={t('brain.clients.title')} />

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
                                    'brain.clients.searchPlaceholder',
                                )}
                            />
                        )
                    }
                >
                    {!is_owner ? (
                        <p className="text-sm text-muted-foreground">
                            {t('brain.clients.ownerOnly')}
                        </p>
                    ) : isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : Building2}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.clients.filteredTitle')
                                        : t('emptyState.clients.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'emptyState.clients.filteredDescription',
                                      )
                                    : t('emptyState.clients.description')
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
                                        {t('emptyState.clients.cta')}
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={filteredClients}
                            getRowId={(client) => client.id}
                            onRowClick={openEdit}
                            getRowProps={() => ({
                                className: 'cursor-pointer',
                            })}
                        />
                    )}
                </PageLayout>

                {is_owner ? (
                    <ClientFormDrawer
                        editing={editing}
                        open={drawerOpen}
                        onCancel={() => handleDrawerOpenChange(false)}
                        onSuccess={() => {
                            setDrawerOpen(false);
                            setEditing(null);
                            deepLink.syncClosed();
                        }}
                    />
                ) : null}
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingErase !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingErase(null);
                    }
                }}
                title={t('brain.clients.confirm.eraseTitle', {
                    name: pendingErase?.name ?? '',
                })}
                description={t('brain.clients.confirm.eraseDescription')}
                confirmLabel={t('brain.clients.erase')}
                confirming={erasing}
                typedConfirmation={pendingErase?.name}
                onConfirm={() => {
                    if (
                        pendingErase === null ||
                        pendingErase.latest_export_id === null
                    ) {
                        return;
                    }

                    setErasing(true);
                    router.post(
                        ClientEraseController.url({
                            company: pendingErase.id,
                            offboarding: pendingErase.latest_export_id,
                        }),
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setErasing(false),
                            onSuccess: () => setPendingErase(null),
                        },
                    );
                }}
            />
        </>
    );
}
