import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    SearchX,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import KeysController from '@/actions/App/Http/Controllers/Brain/KeysController';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { TruncatedText } from '@/components/admin/truncated-text';
import { DataTable } from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ApiKeyCreateDialog } from '@/components/brain/api-key-create-dialog';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useClipboard } from '@/hooks/use-clipboard';
import keysRoutes from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';

type KeyRow = {
    id: string;
    name: string;
    principal_name: string | null;
    principal_type: string | null;
    can_write: boolean;
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
    /** The secret of the key just created, shown once (session flash). */
    plainTextKey: string | null;
};

/**
 * API keys for organisation owners (#201), laid out like externa-core's API keys page: create
 * from the header, the new secret once in a warning with copy, revoke with confirmation.
 */
export default function BrainKeys({ is_owner, keys, plainTextKey }: Props) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [pendingRevoke, setPendingRevoke] = useState<KeyRow | null>(null);
    const [isRevoking, setIsRevoking] = useState(false);
    const [copiedSecret, copySecret] = useClipboard();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.keys.title'),
                href: keysRoutes.keys.url(),
            },
        ],
        [t],
    );

    // The primary action sits in the header, or in the empty state when the list is empty,
    // as in externa-core.
    const primaryAction = useMemo(
        () =>
            is_owner ? (
                <Button type="button" onClick={() => setIsCreateOpen(true)}>
                    <KeyRound className="mr-1 size-4" />
                    {t('brain.keys.create')}
                </Button>
            ) : undefined,
        [is_owner, t],
    );

    const layoutProps = { breadcrumbs };

    const filteredKeys = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return keys;
        }

        return keys.filter(
            (key) =>
                key.name.toLowerCase().includes(needle) ||
                key.display_prefix.toLowerCase().includes(needle) ||
                key.scope_type.toLowerCase().includes(needle) ||
                key.role.toLowerCase().includes(needle),
        );
    }, [keys, search]);

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = filteredKeys.length === 0;
    const hideListChrome =
        !is_owner || (keys.length === 0 && !hasActiveFilters);

    const columns: ColumnDef<KeyRow>[] = [
        {
            id: 'name',
            header: t('brain.keys.colName'),
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            cell: ({ row }) => <TruncatedText text={row.original.name} />,
        },
        {
            id: 'principal',
            header: t('brain.keys.colPrincipal'),
            cell: ({ row }) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <span>{row.original.principal_name ?? '—'}</span>
                    {row.original.principal_type ? (
                        <Badge variant="outline">
                            {t(
                                `brain.proposals.principalType.${row.original.principal_type}`,
                                { defaultValue: row.original.principal_type },
                            )}
                        </Badge>
                    ) : null}
                    {row.original.can_write ? (
                        <Badge variant="secondary">
                            {t('brain.keys.write')}
                        </Badge>
                    ) : null}
                </span>
            ),
        },
        {
            id: 'prefix',
            header: t('brain.keys.colPrefix'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) => row.original.display_prefix,
        },
        {
            id: 'scope',
            header: t('brain.keys.colScope'),
            cell: ({ row }) => (
                <Badge variant="outline">{row.original.scope_type}</Badge>
            ),
        },
        {
            id: 'role',
            header: t('brain.keys.colRole'),
            cell: ({ row }) => row.original.role,
        },
        {
            id: 'last_used',
            header: t('brain.keys.colLastUsed'),
            meta: { cellClassName: 'text-sm text-muted-foreground' },
            cell: ({ row }) => row.original.last_used_at ?? '—',
        },
        {
            id: 'status',
            header: t('brain.keys.colStatus'),
            cell: ({ row }) =>
                row.original.revoked ? (
                    <Badge variant="destructive">
                        {t('brain.keys.revoked')}
                    </Badge>
                ) : (
                    <Badge variant="success" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-success"
                            aria-hidden
                        />
                        {t('brain.keys.active')}
                    </Badge>
                ),
        },
        {
            id: 'actions',
            header: t('brain.keys.colActions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
            },
            cell: ({ row }) =>
                row.original.revoked ? null : (
                    <HeaderIconButton
                        type="button"
                        variant="outline"
                        size="icon-sm"
                        className="text-destructive hover:text-destructive"
                        label={t('brain.keys.revoke')}
                        onClick={() => setPendingRevoke(row.original)}
                    >
                        <Trash2 className="size-3.5" />
                    </HeaderIconButton>
                ),
        },
    ];

    return (
        <AppLayout
            {...layoutProps}
            headerActions={hideListChrome ? undefined : primaryAction}
        >
            <Head title={t('brain.keys.title')} />
            <PageLayout
                filters={
                    hideListChrome ? undefined : (
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t(
                                'brain.keys.searchPlaceholder',
                            )}
                        />
                    )
                }
            >
                {plainTextKey ? (
                    <Alert variant="warning" className="mb-4">
                        <TriangleAlert />
                        <AlertTitle>{t('brain.keys.secretBanner')}</AlertTitle>
                        <AlertDescription>
                            <InputGroup className="mt-1 w-full bg-background">
                                <InputGroupInput
                                    readOnly
                                    aria-label={t('brain.keys.secretValue')}
                                    value={plainTextKey}
                                    className="font-mono text-foreground"
                                />
                                <InputGroupAddon align="inline-end">
                                    <InputGroupButton
                                        size="icon-xs"
                                        aria-label={
                                            copiedSecret === plainTextKey
                                                ? t('common.copied')
                                                : t('common.copy')
                                        }
                                        onClick={() =>
                                            void copySecret(plainTextKey)
                                        }
                                    >
                                        {copiedSecret === plainTextKey ? (
                                            <Check />
                                        ) : (
                                            <Copy />
                                        )}
                                    </InputGroupButton>
                                </InputGroupAddon>
                            </InputGroup>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {!is_owner ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.keys.ownerOnly')}
                    </p>
                ) : isListEmpty ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : KeyRound}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('emptyState.keys.filteredTitle')
                                    : t('emptyState.keys.title')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('emptyState.keys.filteredDescription')
                                : t('emptyState.keys.description')
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
                                primaryAction
                            )
                        }
                    />
                ) : (
                    <DataTable
                        columns={columns}
                        data={filteredKeys}
                        getRowId={(key) => key.id}
                    />
                )}
            </PageLayout>

            {is_owner ? (
                <ApiKeyCreateDialog
                    open={isCreateOpen}
                    onOpenChange={setIsCreateOpen}
                />
            ) : null}

            <ConfirmDestructiveDialog
                open={pendingRevoke !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setPendingRevoke(null);
                    }
                }}
                title={t('brain.keys.confirmRevokeTitle', {
                    name: pendingRevoke?.name ?? '',
                })}
                description={t('brain.keys.confirmRevokeDescription')}
                confirmLabel={t('brain.keys.revoke')}
                confirming={isRevoking}
                onConfirm={() => {
                    if (pendingRevoke === null) {
                        return;
                    }

                    setIsRevoking(true);
                    router.delete(
                        KeysController.destroy.url(pendingRevoke.id),
                        {
                            preserveScroll: true,
                            onFinish: () => setIsRevoking(false),
                            onSuccess: () => setPendingRevoke(null),
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
