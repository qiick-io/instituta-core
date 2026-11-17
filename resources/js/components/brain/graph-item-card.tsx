/**
 * The graph's side card (#270, #273): the selected item with its area and links, a quick preview
 * of its content fetched on selection (summary and first sections, rendered by the server), its
 * linked items (each selectable) and Open. Reading happens here, without leaving the graph.
 */
import { Link } from '@inertiajs/react';
import { ArrowUpRight, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BrainMarkdown } from '@/components/brain/brain-markdown';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { PlacedBrainNode } from '@/lib/brain-force-layout';
import { formatRelativeTime } from '@/lib/relative-time';
import { show as explorerShow } from '@/routes/explorer';
import { preview as previewRoute } from '@/routes/graph';
import { index as reviewGaps } from '@/routes/review/gaps';
import { index as reviewProposals } from '@/routes/review/proposals';

/** How many neighbours the card lists before it says "and N more". */
const LISTED_NEIGHBOURS = 8;

type ItemPreview = {
    summary: string | null;
    sections: Array<{
        anchor: string;
        heading: string | null;
        level: number;
        html: string;
        body: string;
    }>;
    hasMore: boolean;
};

type PreviewState =
    | { status: 'loading' }
    | { status: 'failed' }
    | { status: 'ready'; preview: ItemPreview };

/** Fetches the preview of `stableKey`, once per item for the life of the page. */
function useItemPreview(stableKey: string): PreviewState {
    const cache = useRef(new Map<string, ItemPreview>());
    const [state, setState] = useState<{ key: string; value: PreviewState }>({
        key: '',
        value: { status: 'loading' },
    });

    useEffect(() => {
        const cached = cache.current.get(stableKey);

        if (cached !== undefined) {
            setState({
                key: stableKey,
                value: { status: 'ready', preview: cached },
            });

            return;
        }

        const controller = new AbortController();

        fetch(previewRoute.url(stableKey), {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Preview failed: ${response.status}`);
                }

                const preview = (await response.json()) as ItemPreview;
                cache.current.set(stableKey, preview);
                setState({
                    key: stableKey,
                    value: { status: 'ready', preview },
                });
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    console.error(error);
                    setState({ key: stableKey, value: { status: 'failed' } });
                }
            });

        return () => controller.abort();
    }, [stableKey]);

    return state.key === stableKey ? state.value : { status: 'loading' };
}

function PreviewBody({ state }: { state: PreviewState }) {
    const { t } = useTranslation();

    if (state.status === 'loading') {
        return (
            <div
                className="flex flex-col gap-2"
                aria-label={t('brain.graph.previewLoading')}
            >
                <Skeleton className="h-3 w-3/4" />
                <Skeleton className="h-3 w-full" />
                <Skeleton className="h-3 w-5/6" />
            </div>
        );
    }

    if (state.status === 'failed') {
        return (
            <p className="text-sm text-muted-foreground">
                {t('brain.graph.previewFailed')}
            </p>
        );
    }

    const { summary, sections, hasMore } = state.preview;
    // The summary is often the first paragraph: do not say it twice.
    const showsSummary =
        (summary ?? '').trim() !== '' &&
        !(sections[0]?.body ?? '').trim().startsWith((summary ?? '').trim());

    if ((summary ?? '') === '' && sections.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('brain.graph.previewEmpty')}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {showsSummary ? (
                <p className="text-sm text-muted-foreground">{summary}</p>
            ) : null}
            {sections.map((section) => (
                <section key={section.anchor} className="flex flex-col gap-1">
                    {section.heading ? (
                        <h3 className="text-sm font-semibold">
                            {section.heading}
                        </h3>
                    ) : null}
                    {section.html !== '' ? (
                        <BrainMarkdown html={section.html} />
                    ) : (
                        <p className="text-sm whitespace-pre-line">
                            {section.body}
                        </p>
                    )}
                </section>
            ))}
            {hasMore ? (
                <p className="text-xs text-muted-foreground">
                    {t('brain.graph.previewMore')}
                </p>
            ) : null}
        </div>
    );
}

export function GraphItemCard({
    item,
    neighbours,
    areaLabel,
    areaColour,
    onSelectNeighbour,
    onClose,
}: {
    item: PlacedBrainNode;
    neighbours: PlacedBrainNode[];
    areaLabel: (area: string) => string;
    areaColour: (area: string) => string;
    onSelectNeighbour: (id: string) => void;
    onClose: () => void;
}) {
    const { t, i18n } = useTranslation();
    const preview = useItemPreview(item.stable_key);

    return (
        <Card className="absolute top-3 left-3 flex max-h-[calc(100%-1.5rem)] w-80 max-w-[calc(100%-1.5rem)] animate-in gap-3 overflow-hidden bg-card/90 py-4 shadow-lg backdrop-blur duration-200 fade-in-0 slide-in-from-left-2">
            <CardHeader className="px-4">
                <CardTitle className="pr-6 text-base leading-snug break-words">
                    {item.title}
                </CardTitle>
                <CardDescription className="font-mono text-xs break-all">
                    {item.stable_key}
                </CardDescription>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="absolute top-2 right-2 size-7"
                    onClick={onClose}
                    aria-label={t('brain.graph.clearSelection')}
                    title={t('brain.graph.clearSelection')}
                >
                    <X />
                </Button>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-col gap-3 overflow-y-auto px-4">
                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <Badge variant="outline">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full"
                            style={{ backgroundColor: areaColour(item.area) }}
                        />
                        {areaLabel(item.area)}
                    </Badge>
                    <span>
                        {t('brain.graph.links', { count: item.degree })}
                    </span>
                    {item.changed_at !== null ? (
                        <span>
                            {t('brain.graph.changed', {
                                when: formatRelativeTime(
                                    Date.parse(item.changed_at) / 1000,
                                    i18n.language,
                                ),
                            })}
                        </span>
                    ) : null}
                </div>
                {item.open_proposals + item.open_gaps > 0 ? (
                    <div className="flex flex-wrap gap-2">
                        {item.open_proposals > 0 ? (
                            <Badge variant="warning" asChild>
                                <Link href={reviewProposals.url()}>
                                    {t('brain.graph.openProposals', {
                                        count: item.open_proposals,
                                    })}
                                </Link>
                            </Badge>
                        ) : null}
                        {item.open_gaps > 0 ? (
                            <Badge variant="warning" asChild>
                                <Link href={reviewGaps.url()}>
                                    {t('brain.graph.openGaps', {
                                        count: item.open_gaps,
                                    })}
                                </Link>
                            </Badge>
                        ) : null}
                    </div>
                ) : null}

                <div className="flex flex-col gap-1.5">
                    <p className="text-xs font-medium text-muted-foreground">
                        {t('brain.graph.preview')}
                    </p>
                    <div className="max-h-64 overflow-y-auto rounded-lg border bg-background/60 p-3">
                        <PreviewBody state={preview} />
                    </div>
                </div>

                {neighbours.length > 0 ? (
                    <div className="flex flex-col gap-1">
                        <p className="text-xs font-medium text-muted-foreground">
                            {t('brain.graph.neighbours')}
                        </p>
                        <ul className="-mx-2 flex flex-col">
                            {neighbours
                                .slice(0, LISTED_NEIGHBOURS)
                                .map((neighbour) => (
                                    <li key={neighbour.id}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            onClick={() =>
                                                onSelectNeighbour(neighbour.id)
                                            }
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor: areaColour(
                                                        neighbour.area,
                                                    ),
                                                }}
                                            />
                                            <span className="truncate">
                                                {neighbour.title}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                        </ul>
                        {neighbours.length > LISTED_NEIGHBOURS ? (
                            <p className="text-xs text-muted-foreground">
                                {t('brain.graph.moreNeighbours', {
                                    count:
                                        neighbours.length - LISTED_NEIGHBOURS,
                                })}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                <div className="flex gap-2">
                    <Button asChild size="sm">
                        <Link href={explorerShow.url(item.stable_key)}>
                            <ArrowUpRight />
                            {t('brain.graph.open')}
                        </Link>
                    </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('brain.graph.pathHint')}
                </p>
            </CardContent>
        </Card>
    );
}
