import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { resolved } from '@/routes';
import AppLayout from '@/layouts/app-layout';
import { Bot, Boxes, KeyRound, Table as TableIcon } from 'lucide-react';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { SegmentedIconItem } from '@/components/segmented-icon-item';
import { ToggleGroup } from '@/components/ui/toggle-group';

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

export default function BrainResolved({
    hasPerimeter,
    products,
    product_id,
    entries,
    token_estimate,
    agent_view,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('brain.resolved.title'),
                href: resolved.url(),
            },
        ],
    };
    const [agentMode, setAgentMode] = useState(false);

    return (
        <AppLayout {...layoutProps}>
            <Head title={t('brain.resolved.title')} />
            <PageLayout
                description={t('brain.resolved.description')}
                filters={
                    hasPerimeter && products.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-2">
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
                                    className="min-w-[12rem]"
                                    aria-label={t('brain.resolved.product')}
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
                            <Badge variant="secondary">
                                {t('brain.resolved.tokens', {
                                    count: token_estimate,
                                })}
                            </Badge>
                        </div>
                    ) : null
                }
                filtersRight={
                    hasPerimeter && products.length > 0 ? (
                        <ToggleGroup
                            type="single"
                            value={agentMode ? 'agent' : 'table'}
                            onValueChange={(value) => {
                                if (value !== '') {
                                    setAgentMode(value === 'agent');
                                }
                            }}
                            variant="outline"
                            aria-label={t('brain.resolved.viewLabel')}
                        >
                            <SegmentedIconItem
                                value="table"
                                isActive={!agentMode}
                                label={t('brain.resolved.viewTable')}
                            >
                                <TableIcon />
                            </SegmentedIconItem>
                            <SegmentedIconItem
                                value="agent"
                                isActive={agentMode}
                                label={t('brain.resolved.agentToggle')}
                            >
                                <Bot />
                            </SegmentedIconItem>
                        </ToggleGroup>
                    ) : null
                }
            >
                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : products.length === 0 ? (
                    <PageEmptyState
                        icon={Boxes}
                        title={
                            <EmptyStatus>
                                {t('brain.resolved.noProducts')}
                            </EmptyStatus>
                        }
                        description={t('brain.resolved.description')}
                    />
                ) : agentMode ? (
                    <pre className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-muted/30 p-4 font-mono text-xs dark:border-sidebar-border">
                        {JSON.stringify(agent_view, null, 2)}
                    </pre>
                ) : entries.length === 0 ? (
                    <PageEmptyState
                        icon={KeyRound}
                        title={
                            <EmptyStatus>
                                {t('brain.resolved.empty')}
                            </EmptyStatus>
                        }
                        description={t('brain.resolved.emptyDescription')}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('brain.resolved.colKey')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.resolved.colTitle')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.resolved.colOrigin')}
                                </TableHead>
                                <TableHead>
                                    {t('brain.resolved.colPath')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.map((entry) => (
                                <TableRow key={entry.resolution_key}>
                                    <TableCell className="font-mono text-xs">
                                        {entry.resolution_key}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col gap-0.5">
                                            <span>{entry.title}</span>
                                            {entry.overridden ? (
                                                <Badge variant="outline">
                                                    {t(
                                                        'brain.resolved.overridden',
                                                    )}
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    {t(
                                                        'brain.resolved.inherited',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {t(
                                            `brain.resolved.scopes.${entry.origin.scope_type}`,
                                            {
                                                defaultValue:
                                                    entry.origin.scope_type,
                                            },
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {entry.origin.path}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </PageLayout>
        </AppLayout>
    );
}
