/**
 * The graph's path card (#275): after Shift-click, how two items connect, as the chain of linked
 * items from one to the other. Each step centres the graph on that item; the ends open it.
 */
import { Route, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { PlacedBrainNode } from '@/lib/brain-force-layout';

export function GraphPathCard({
    from,
    to,
    steps,
    areaColour,
    onFocus,
    onClose,
}: {
    from: PlacedBrainNode;
    to: PlacedBrainNode;
    /** The items from `from` to `to`, or null when they are not connected. */
    steps: PlacedBrainNode[] | null;
    areaColour: (area: string) => string;
    onFocus: (id: string) => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Card className="absolute top-3 left-3 flex max-h-[calc(100%-1.5rem)] w-80 max-w-[calc(100%-1.5rem)] animate-in gap-3 overflow-hidden bg-card/90 py-4 shadow-lg backdrop-blur duration-200 fade-in-0 slide-in-from-left-2">
            <CardHeader className="px-4">
                <CardTitle className="flex items-center gap-2 pr-6 text-base">
                    <Route className="size-4 text-primary" aria-hidden="true" />
                    {t('brain.graph.pathTitle')}
                </CardTitle>
                <CardDescription>
                    {steps === null
                        ? t('brain.graph.pathNone', {
                              from: from.title,
                              to: to.title,
                          })
                        : t('brain.graph.pathSteps', {
                              count: steps.length - 1,
                          })}
                </CardDescription>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="absolute top-2 right-2 size-7"
                    onClick={onClose}
                    aria-label={t('brain.graph.clearPath')}
                    title={t('brain.graph.clearPath')}
                >
                    <X />
                </Button>
            </CardHeader>
            {steps !== null ? (
                <CardContent className="min-h-0 overflow-y-auto px-4">
                    <ol className="relative flex flex-col">
                        {steps.map((step, index) => (
                            <li key={step.id} className="relative flex gap-3">
                                <div
                                    aria-hidden="true"
                                    className="flex w-3 flex-col items-center"
                                >
                                    <span
                                        className="mt-2.5 size-3 shrink-0 rounded-full ring-2 ring-card"
                                        style={{
                                            backgroundColor: areaColour(
                                                step.area,
                                            ),
                                        }}
                                    />
                                    {index < steps.length - 1 ? (
                                        <span className="w-px flex-1 bg-primary/40" />
                                    ) : null}
                                </div>
                                <button
                                    type="button"
                                    className="mb-1 min-w-0 flex-1 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    onClick={() => onFocus(step.id)}
                                >
                                    <span className="block truncate font-medium">
                                        {step.title}
                                    </span>
                                    <span className="block truncate font-mono text-xs text-muted-foreground">
                                        {step.stable_key}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </CardContent>
            ) : null}
        </Card>
    );
}
