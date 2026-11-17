<?php

declare(strict_types=1);

namespace App\Brain\Readers;

use App\Brain\Data\CitationData;
use App\Brain\Data\ItemData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\PathHelper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns an image, a PDF or a download-only file (zip, font, Office document, #315) in a source into
 * a brain item that carries metadata only (#311): logos, screenshots, specs and templates people
 * keep next to their notes are files of the brain too. It is not a file manager: a fixed list of
 * types, a size limit, read-only, and the bytes are offered, never interpreted.
 *
 * The item's stable key is its path (extension included, so `logo.svg` never clashes with
 * `logo.md`), its title the file name, its type `image`, `document` (PDF) or `file` (download only).
 * The bytes never enter the index or reach an agent; the sync keeps a content-addressed copy so the
 * app can show or offer them.
 *
 * An optional `<file>.meta.yml` (or `.meta.yaml`) sidecar adds a title, alt text, caption, tags and
 * a visibility. Without one the file still enters, described by its format and size.
 */
final class AssetReader
{
    /** Larger files are skipped (`asset_too_large`): a brain holds references, not media archives. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    /** @var array<string, string> Extension to the content type the app serves it with. */
    private const array MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        // Download-only files (#315): kept and listed, never previewed.
        'zip' => 'application/zip',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    /** @var array<string, string> What a download-only file is, for its label and icon. */
    private const array DOWNLOAD_KINDS = [
        'zip' => 'archive',
        'ttf' => 'font',
        'otf' => 'font',
        'woff' => 'font',
        'woff2' => 'font',
        'docx' => 'text',
        'doc' => 'text',
        'odt' => 'text',
        'xlsx' => 'spreadsheet',
        'xls' => 'spreadsheet',
        'ods' => 'spreadsheet',
        'pptx' => 'presentation',
        'ppt' => 'presentation',
        'odp' => 'presentation',
    ];

    /**
     * Whether the file is only offered for download (an archive, a font, an Office file): the app
     * never shows it inline, so nothing in it runs or renders in the browser.
     */
    public static function isDownloadOnly(string $relativePath): bool
    {
        return isset(self::DOWNLOAD_KINDS[PathHelper::extension($relativePath)]);
    }

    /**
     * Whether the path is a file this reader turns into an asset item.
     */
    public static function supports(string $relativePath): bool
    {
        return isset(self::MIME_TYPES[PathHelper::extension($relativePath)]);
    }

    /**
     * Whether the path is an asset's sidecar, read with its asset rather than as an item of its own.
     */
    public static function isSidecar(string $relativePath): bool
    {
        return preg_match('/\.meta\.ya?ml$/i', $relativePath) === 1;
    }

    /**
     * The content type an asset is served with, from its extension (never sniffed from the bytes).
     */
    public static function mimeType(string $relativePath): ?string
    {
        return self::MIME_TYPES[PathHelper::extension($relativePath)] ?? null;
    }

    /**
     * @param  callable(string): string  $areaFromPath  The area rule of the markdown adapter.
     * @return array{item: ItemData|null, skip_reason: string|null}
     */
    public function read(
        string $absolutePath,
        string $relativePath,
        ReadRequestData $request,
        ?ManifestData $manifest,
        callable $areaFromPath,
    ): array {
        $bytes = @filesize($absolutePath);

        if ($bytes === false) {
            return ['item' => null, 'skip_reason' => 'unreadable'];
        }

        if ($bytes > self::MAX_BYTES) {
            return ['item' => null, 'skip_reason' => 'asset_too_large'];
        }

        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return ['item' => null, 'skip_reason' => 'unreadable'];
        }

        $extension = PathHelper::extension($relativePath);
        $mime = self::MIME_TYPES[$extension];
        $kind = self::DOWNLOAD_KINDS[$extension] ?? null;
        $sidecar = $this->sidecar($absolutePath);
        [$width, $height] = match (true) {
            $kind !== null, $mime === 'application/pdf' => [null, null],
            $extension === 'svg' => $this->svgSize($contents),
            default => $this->rasterSize($contents),
        };

        $alt = $this->stringField($sidecar, 'alt');
        $caption = $this->stringField($sidecar, 'caption');
        $title = $this->stringField($sidecar, 'title') ?? basename($relativePath);
        $description = $this->describe($extension, $kind, $bytes, $width, $height);
        $tags = array_values(array_filter(
            is_array($sidecar['tags'] ?? null) ? $sidecar['tags'] : [],
            static fn (mixed $tag): bool => is_string($tag) && $tag !== '',
        ));

        $item = new ItemData(
            path: $relativePath,
            stableKey: $relativePath,
            title: $title,
            summary: $caption ?? $alt ?? $description,
            type: match (true) {
                $kind !== null => 'file',
                $mime === 'application/pdf' => 'document',
                default => 'image',
            },
            area: $this->stringField($sidecar, 'area') ?? $areaFromPath($relativePath),
            visibility: VisibilityEnum::tryFromLoose($sidecar['visibility'] ?? null)
                ?? ($manifest !== null ? $manifest->defaultVisibility : VisibilityEnum::Internal),
            adapter: 'asset',
            citation: new CitationData(
                source: $request->source,
                path: $relativePath,
                section: null,
                commit: $request->commit,
            ),
            tags: $tags,
            frontmatter: [
                'asset' => [
                    'mime' => $mime,
                    'kind' => $kind ?? ($mime === 'application/pdf' ? 'pdf' : 'image'),
                    'bytes' => $bytes,
                    'sha256' => hash('sha256', $contents),
                    'width' => $width,
                    'height' => $height,
                    'alt' => $alt,
                    'caption' => $caption,
                ],
            ],
            // What search and agents read: the description, never the bytes.
            body: implode("\n", array_filter([$title, $alt, $caption, $description, $relativePath])),
        );

        return ['item' => $item, 'skip_reason' => null];
    }

    /**
     * "PNG image, 512 × 512, 24 KB", "PDF document, 1.2 MB", "XLSX spreadsheet, 80 KB".
     */
    private function describe(string $extension, ?string $kind, int $bytes, ?int $width, ?int $height): string
    {
        $noun = match ($kind) {
            'archive' => 'archive',
            'font' => 'font',
            'spreadsheet' => 'spreadsheet',
            'presentation' => 'presentation',
            'text' => 'document',
            default => $extension === 'pdf' ? 'document' : 'image',
        };
        $label = strtoupper($extension === 'jpeg' ? 'jpg' : $extension).' '.$noun;
        $size = $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';

        return implode(', ', array_filter([
            $label,
            $width !== null && $height !== null ? $width.' × '.$height : null,
            $size,
        ]));
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function rasterSize(string $contents): array
    {
        $size = @getimagesizefromstring($contents);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [$size[0], $size[1]] : [null, null];
    }

    /**
     * An SVG's size from its `width` / `height` attributes, else from its `viewBox`.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function svgSize(string $contents): array
    {
        if (preg_match('/<svg\b[^>]*>/is', $contents, $tag) !== 1) {
            return [null, null];
        }

        $attribute = static fn (string $name): ?float => preg_match('/\s'.$name.'\s*=\s*["\']\s*([\d.]+)(?:px)?\s*["\']/i', $tag[0], $match) === 1
            ? (float) $match[1]
            : null;
        $width = $attribute('width');
        $height = $attribute('height');

        if (($width === null || $height === null)
            && preg_match('/\sviewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["\']/i', $tag[0], $viewBox) === 1) {
            $width ??= (float) $viewBox[1];
            $height ??= (float) $viewBox[2];
        }

        return $width !== null && $height !== null && $width > 0 && $height > 0
            ? [(int) round($width), (int) round($height)]
            : [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function sidecar(string $absolutePath): array
    {
        foreach (['.meta.yml', '.meta.yaml'] as $suffix) {
            $path = $absolutePath.$suffix;

            if (! is_file($path)) {
                continue;
            }

            try {
                $parsed = Yaml::parse((string) file_get_contents($path));
            } catch (ParseException) {
                return [];
            }

            return is_array($parsed) ? $parsed : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function stringField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
