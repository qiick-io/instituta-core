import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
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
    const [agentMode, setAgentMode] = useState(false);

    return (
        <>
            <Head title={t('brain.resolved.title')} />
            <PageLayout
                description={t('brain.resolved.description')}
                filters={
                    hasPerimeter && products.length > 0 ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="resolved-product">
                                    {t('brain.resolved.product')}
                                </Label>
                                <Select
                                    value={product_id ?? undefined}
                                    onValueChange={(value) =>
                                        router.get(
                                            '/brain/resolved',
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
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={agentMode}
                                    onChange={(event) =>
                                        setAgentMode(event.target.checked)
                                    }
                                    className="size-4 rounded border"
                                />
                                {t('brain.resolved.agentToggle')}
                            </label>
                        </div>
                    ) : null
                }
            >
                <div className="flex flex-wrap items-center gap-2 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.resolved.title')}
                    </h1>
                    {hasPerimeter ? (
                        <Badge variant="secondary">
                            {t('brain.resolved.tokens', {
                                count: token_estimate,
                            })}
                        </Badge>
                    ) : null}
                </div>

                {!hasPerimeter ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.scope.none')}
                    </p>
                ) : products.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.resolved.noProducts')}
                    </p>
                ) : agentMode ? (
                    <pre className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-muted/30 p-4 font-mono text-xs dark:border-sidebar-border">
                        {JSON.stringify(agent_view, null, 2)}
                    </pre>
                ) : entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.resolved.empty')}
                    </p>
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
        </>
    );
}

BrainResolved.layout = {
    breadcrumbs: [
        {
            title: 'Resolved',
            href: '/brain/resolved',
        },
    ],
};
