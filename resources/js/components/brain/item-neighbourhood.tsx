/**
 * Mini-graph of an item's linked neighbours in the Explorer reading aside (#289).
 * Uses the same area colours as the big graph (`legendColour` / `AREA_TOKENS`).
 * Every node names its item in a tooltip on hover and keyboard focus (#365).
 */
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { AREA_TOKENS, areaIndex } from '@/lib/brain-graph-render';
import { graph } from '@/routes';
import { show as explorerShow } from '@/routes/explorer';

export type NeighbourhoodNeighbour = {
    id: string;
    title: string;
    stable_key: string;
    area: string;
};

export type Neighbourhood = {
    areas: string[];
    neighbours: NeighbourhoodNeighbour[];
};

type Props = {
    /** The open item at the centre of the mini-graph. */
    centre: {
        id: string;
        title: string;
        stable_key: string;
        area: string;
    };
    neighbourhood: Neighbourhood;
};

const VIEW_WIDTH = 192;
const VIEW_HEIGHT = 176;
const CENTRE_X = VIEW_WIDTH / 2;
const CENTRE_Y = VIEW_HEIGHT / 2;
const RADIUS = 62;
const CENTRE_RADIUS = 10;
const NEIGHBOUR_RADIUS = 7;

/** Softened area fill, same mix as the big graph legend. */
function areaColour(area: string, areas: string[]): string {
    return `color-mix(in oklab, var(${AREA_TOKENS[areaIndex(area, areas)]}) 78%, var(--card))`;
}

/** A node's tooltip: the item's title over a muted second line. */
function NodeLabel({ title, detail }: { title: string; detail: string }) {
    return (
        <span className="flex max-w-56 flex-col gap-0.5">
            <span className="font-medium">{title}</span>
            <span className="opacity-70">{detail}</span>
        </span>
    );
}

/**
 * SVG star of the open item and up to 24 linked neighbours, plus a link into the full graph.
 */
export function ItemNeighbourhood({ centre, neighbourhood }: Props) {
    const { t } = useTranslation();
    const { areas, neighbours } = neighbourhood;
    const graphHref = graph.url({ query: { focus: centre.stable_key } });

    return (
        <div className="space-y-2">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t('brain.reading.neighbourhood')}
            </p>

            {neighbours.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('brain.reading.noNeighbours')}
                </p>
            ) : (
                <svg
                    viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
                    className="h-auto w-full"
                    role="img"
                    aria-label={t('brain.reading.neighbourhood')}
                >
                    {neighbours.map((neighbour, index) => {
                        const angle =
                            (Math.PI * 2 * index) / neighbours.length -
                            Math.PI / 2;
                        const neighbourX = CENTRE_X + Math.cos(angle) * RADIUS;
                        const neighbourY = CENTRE_Y + Math.sin(angle) * RADIUS;

                        return (
                            <g key={neighbour.id}>
                                <line
                                    x1={CENTRE_X}
                                    y1={CENTRE_Y}
                                    x2={neighbourX}
                                    y2={neighbourY}
                                    className="stroke-border"
                                    strokeWidth={1}
                                />
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <a
                                            href={explorerShow.url(
                                                neighbour.stable_key,
                                            )}
                                            aria-label={neighbour.title}
                                            className="group outline-none"
                                            onClick={(event) => {
                                                // An Inertia visit, not a full page load.
                                                event.preventDefault();
                                                router.visit(
                                                    explorerShow.url(
                                                        neighbour.stable_key,
                                                    ),
                                                );
                                            }}
                                        >
                                            <circle
                                                cx={neighbourX}
                                                cy={neighbourY}
                                                r={NEIGHBOUR_RADIUS}
                                                fill={areaColour(
                                                    neighbour.area,
                                                    areas,
                                                )}
                                                className="stroke-border transition-[stroke-width] group-hover:stroke-foreground group-focus-visible:stroke-ring"
                                                strokeWidth={1}
                                            />
                                        </a>
                                    </TooltipTrigger>
                                    <TooltipContent side="top">
                                        <NodeLabel
                                            title={neighbour.title}
                                            detail={neighbour.stable_key}
                                        />
                                    </TooltipContent>
                                </Tooltip>
                            </g>
                        );
                    })}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <circle
                                cx={CENTRE_X}
                                cy={CENTRE_Y}
                                r={CENTRE_RADIUS}
                                fill={areaColour(centre.area, areas)}
                                className="stroke-foreground/40"
                                strokeWidth={1.5}
                                aria-label={centre.title}
                            />
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <NodeLabel
                                title={centre.title}
                                detail={t('brain.reading.currentItem')}
                            />
                        </TooltipContent>
                    </Tooltip>
                </svg>
            )}

            <Link
                href={graphHref}
                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                {t('brain.reading.openInGraph')}
            </Link>
        </div>
    );
}
