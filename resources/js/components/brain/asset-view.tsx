/**
 * The Explorer's view of a brain asset (#311): an image, a PDF or a download-only file (#315)
 * people keep in the brain next to their notes. Download-only files show as a card with Download. Images show on a checkerboard (so transparent logos read in light and dark), PDFs in
 * the browser's viewer. Below: format, size, dimensions, alt text and caption, Download and Open,
 * and the documents that embed or link the file.
 */
import { Link } from '@inertiajs/react';
import {
    Download,
    ExternalLink,
    File,
    FileArchive,
    FileSpreadsheet,
    FileText,
    Presentation,
    Type,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { ItemAsset } from '@/components/brain/item-reader';
import { Button } from '@/components/ui/button';
import { show as explorerShow } from '@/routes/explorer';

/** The icon of a download-only file, by what it is (#315). */
const KIND_ICONS: Record<string, LucideIcon> = {
    archive: FileArchive,
    font: Type,
    text: FileText,
    spreadsheet: FileSpreadsheet,
    presentation: Presentation,
};

/** "24 KB", "1.2 MB", in the UI language's number format. */
function formatBytes(bytes: number, locale: string): string {
    const format = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });

    return bytes >= 1024 * 1024
        ? `${format.format(bytes / (1024 * 1024))} MB`
        : `${format.format(Math.max(1, Math.round(bytes / 1024)))} KB`;
}

export function AssetView({
    asset,
    title,
}: {
    asset: ItemAsset;
    title: string;
}) {
    const { t, i18n } = useTranslation();
    const isPdf = asset.mime === 'application/pdf';
    const format = asset.extension.toUpperCase();
    const KindIcon = KIND_ICONS[asset.kind] ?? File;

    return (
        <div className="flex flex-col gap-6">
            <figure className="flex flex-col gap-2">
                {asset.is_download_only ? (
                    // Archives, fonts and Office files are offered, never opened here (#315).
                    <div className="flex items-center gap-4 rounded-lg border bg-card p-5">
                        <span className="flex size-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <KindIcon className="size-6" aria-hidden="true" />
                        </span>
                        <div className="flex min-w-0 flex-col">
                            <span className="truncate font-medium">
                                {title}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {t(`brain.asset.kinds.${asset.kind}`, {
                                    format,
                                })}{' '}
                                · {formatBytes(asset.bytes, i18n.language)}
                            </span>
                        </div>
                    </div>
                ) : isPdf ? (
                    <iframe
                        src={asset.url}
                        title={title}
                        className="h-[70vh] w-full rounded-lg border bg-muted"
                    />
                ) : (
                    <div
                        className="flex max-h-[70vh] min-h-48 items-center justify-center overflow-auto rounded-lg border p-6"
                        style={{
                            // A checkerboard, so transparent images read on any theme.
                            backgroundColor: 'var(--card)',
                            backgroundImage:
                                'linear-gradient(45deg, var(--muted) 25%, transparent 25%), linear-gradient(-45deg, var(--muted) 25%, transparent 25%), linear-gradient(45deg, transparent 75%, var(--muted) 75%), linear-gradient(-45deg, transparent 75%, var(--muted) 75%)',
                            backgroundSize: '16px 16px',
                            backgroundPosition:
                                '0 0, 0 8px, 8px -8px, -8px 0px',
                        }}
                    >
                        <img
                            src={asset.url}
                            alt={asset.alt ?? title}
                            className="max-h-[60vh] max-w-full object-contain"
                        />
                    </div>
                )}
                {asset.caption ? (
                    <figcaption className="text-sm text-muted-foreground">
                        {asset.caption}
                    </figcaption>
                ) : null}
            </figure>

            <div className="flex flex-wrap items-center gap-2">
                <Button asChild size="sm">
                    <a href={asset.download_url}>
                        <Download />
                        {t('brain.asset.download')}
                    </a>
                </Button>
                {asset.is_download_only ? null : (
                    <Button asChild size="sm" variant="outline">
                        <a href={asset.url} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            {t('brain.asset.openOriginal')}
                        </a>
                    </Button>
                )}
            </div>

            <dl className="grid max-w-xl grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <dt className="text-muted-foreground">
                    {t('brain.asset.format')}
                </dt>
                <dd>{format}</dd>
                <dt className="text-muted-foreground">
                    {t('brain.asset.size')}
                </dt>
                <dd className="tabular-nums">
                    {formatBytes(asset.bytes, i18n.language)}
                </dd>
                {asset.width !== null && asset.height !== null ? (
                    <>
                        <dt className="text-muted-foreground">
                            {t('brain.asset.dimensions')}
                        </dt>
                        <dd className="tabular-nums">
                            {asset.width} × {asset.height}
                        </dd>
                    </>
                ) : null}
                {asset.alt ? (
                    <>
                        <dt className="text-muted-foreground">
                            {t('brain.asset.alt')}
                        </dt>
                        <dd>{asset.alt}</dd>
                    </>
                ) : null}
            </dl>

            <section className="flex flex-col gap-2">
                <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('brain.asset.usedIn')}
                </h2>
                {asset.used_in.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('brain.asset.notUsed')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {asset.used_in.map((user) => (
                            <li key={user.stable_key}>
                                <Link
                                    href={explorerShow.url(user.stable_key)}
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    {user.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
