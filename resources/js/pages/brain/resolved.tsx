import type { ColumnDef } from '@tanstack/react-table';
import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Bot, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { resolved } from '@/routes/brain';
import type { BreadcrumbItem } from '@/types';

type ProductOption = {
    id: string;
    name: string;
    slug: string;
};

type Entry = {
    id: string;
    resolution_key: string;
    title: string;
    summary: string | null;
    visibility: string;
    origin: {
        scope_type: string;
        scope_id: string;
        path: string;
        commit: string | null;
    };
    overridden: boolean;
};

type AgentEntry = {
    resolution_key: string;
    title: string;
    summary: string | null;
    origin: string;
    visibility: string;
};

type Props = {
    hasPerimeter: boolean;
    products: ProductOption[];
    product_id: string | null;
    entries: Entry[];
    token_estimate: number;
    agent_view: AgentEntry[];
};

/**
 * Resolved product view with origin and “what the agent would see” (kickoff §5).
 * List chrome from `@qiick/data-table` / page-layout; agent panel uses `ai` tokens.
 */
export default function BrainResolved({
    hasPerimeter,
    products,
    product_id,
    entries,
    token_estimate,
    agent_view,
}: Props) {
    const { t } = useTranslation();
    const [isAgentMode, setIsAgentMode] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('brain.resolved.title'),
                href: resolved.url(),
            },
        ],
        [t],
    );

    setLayoutProps({ breadcrumbs });

    const columns: ColumnDef<Entry>[] = [
        {
            id: 'key',
            header: t('brain.resolved.colKey'),
            meta: { cellClassName: 'font-mono text-xs max-w-[12rem]' },
            cell: ({ row }) => (
                <TruncatedText text={row.original.resolution_key} />
            ),
        },
        {
            id: 'title',
            header: t('brain.resolved.colTitle'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row }) => (
                <div className="flex flex-col gap-1">
                    <TruncatedText text={row.original.title} />
                    {row.original.overridden ? (
                        <Badge variant="outline" className="w-fit">
                            {t('brain.resolved.overridden')}
                        </Badge>
                    ) : (
                        <Badge variant="secondary" className="w-fit">
                            {t('brain.resolved.inherited')}
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            id: 'origin',
            header: t('brain.resolved.colOrigin'),
            cell: ({ row }) =>
                t(`brain.resolved.scopes.${row.original.origin.scope_type}`, {
                    defaultValue: row.original.origin.scope_type,
                }),
        },
        {
            id: 'path',
            header: t('brain.resolved.colPath'),
            meta: {
                cellClassName:
                    'font-mono text-xs text-muted-foreground max-w-[14rem]',
            },
            cell: ({ row }) => (
                <TruncatedText text={row.original.origin.path} />
            ),
        },
    ];

    return (
        <>
            <Head title={t('brain.resolved.title')} />
            <PageLayout
                description={t('brain.resolved.description')}
                filters={
                    hasPerimeter && products.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="resolved-product">
                                    {t('brain.resolved.product')}
                                </Label>
                                <Select
                                    value={product_id ?? undefined}
                                    onValueChange={(value) =>
                                        router.get(
                                            resolved.url(),
                                            { product: value },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="resolved-product"
                                        className="min-w-[12rem]"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'brain.resolved.product',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {products.map((product) => (
                                            <SelectItem
                                                key={product.id}
                                                value={product.id}
                                            >
                                                {product.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-center gap-2 pt-5">
                                <Switch
                                    id="resolved-agent"
                                    checked={isAgentMode}
                                    onCheckedChange={setIsAgentMode}
                                />
                                <Label htmlFor="resolved-agent">
                                    {t('brain.resolved.agentToggle')}
                                </Label>
                            </div>
                        </div>
                    ) : null
                }
                subheader={
                    hasPerimeter && products.length > 0 ? (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <StatCard
                                label={t('brain.resolved.tokens', {
                                    count: token_estimate,
                                })}
                                value={String(token_estimate)}
                                context={t('brain.resolved.description')}
                            />
                        </div>
                    ) : null
                }
            >
                {!hasPerimeter ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>{t('brain.scope.none')}</EmptyStatus>
                        }
                        description={t('brain.resolved.description')}
                        compact
                    />
                ) : products.length === 0 ? (
                    <PageEmptyState
                        icon={Bot}
                        title={t('brain.resolved.title')}
                        description={t('brain.resolved.noProducts')}
                        compact
                    />
                ) : isAgentMode ? (
                    <div className="rounded-xl border border-ai-border bg-ai-bg p-4 text-ai-text">
                        <p className="mb-3 text-sm font-medium">
                            {t('brain.resolved.agentToggle')}
                        </p>
                        <pre className="overflow-x-auto rounded-lg border border-ai-border/60 bg-background/80 p-4 font-mono text-xs text-foreground">
                            {JSON.stringify(agent_view, null, 2)}
                        </pre>
                    </div>
                ) : entries.length === 0 ? (
                    <PageEmptyState
                        icon={Bot}
                        title={t('brain.resolved.title')}
                        description={t('brain.resolved.empty')}
                        compact
                    />
                ) : (
                    <DataTable
                        columns={columns}
                        data={entries}
                        getRowId={(entry) => entry.resolution_key}
                    />
                )}
            </PageLayout>
        </>
    );
}
