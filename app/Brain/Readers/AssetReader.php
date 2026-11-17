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
 * Turns an image or a PDF in a source into a brain item that carries metadata only (#311): logos,
 * screenshots and documents people keep next to their notes are files of the brain too. The item's
 * stable key is its path (extension included, so `logo.svg` never clashes with `logo.md`), its
 * title the file name, its type `image` or `document`. The bytes never enter the index or reach an
 * agent; the sync keeps a content-addressed copy so the app can show them.
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
    ];

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
        $sidecar = $this->sidecar($absolutePath);
        [$width, $height] = $extension === 'svg'
            ? $this->svgSize($contents)
            : $this->rasterSize($contents);

        $alt = $this->stringField($sidecar, 'alt');
        $caption = $this->stringField($sidecar, 'caption');
        $title = $this->stringField($sidecar, 'title') ?? basename($relativePath);
        $description = $this->describe($extension, $bytes, $width, $height);
        $tags = array_values(array_filter(
            is_array($sidecar['tags'] ?? null) ? $sidecar['tags'] : [],
            static fn (mixed $tag): bool => is_string($tag) && $tag !== '',
        ));

        $item = new ItemData(
            path: $relativePath,
            stableKey: $relativePath,
            title: $title,
            summary: $caption ?? $alt ?? $description,
            type: $mime === 'application/pdf' ? 'document' : 'image',
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
     * "PNG image, 512 × 512, 24 KB" or "PDF document, 1.2 MB".
     */
    private function describe(string $extension, int $bytes, ?int $width, ?int $height): string
    {
        $kind = strtoupper($extension === 'jpeg' ? 'jpg' : $extension).($extension === 'pdf' ? ' document' : ' image');
        $size = $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';

        return implode(', ', array_filter([
            $kind,
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
