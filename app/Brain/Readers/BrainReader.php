<?php

declare(strict_types=1);

namespace App\Brain\Readers;

use App\Brain\Adapters\BasicMemoryAdapter;
use App\Brain\Adapters\InstitutaAdapter;
use App\Brain\Adapters\MarkdownAdapter;
use App\Brain\Adapters\ShadcnRegistryAdapter;
use App\Brain\Contracts\BrainAdapterContract;
use App\Brain\Data\BrainRepositoryData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\SkippedFileData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Exceptions\DenylistHitException;
use App\Brain\Exceptions\UnsupportedManifestVersionException;
use App\Brain\Scanners\DenylistScanner;
use App\Brain\Scanners\SecretPiiScanner;
use App\Brain\Support\PathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads a brain repository from a local folder without the database.
 */
final class BrainReader
{
    private const SUPPORTED_MANIFEST_VERSION = 1;

    /** @var list<BrainAdapterContract> */
    private array $adapters;

    /**
     * @param  list<BrainAdapterContract>|null  $adapters  Highest priority first
     */
    public function __construct(
        private readonly DenylistScanner $denylistScanner = new DenylistScanner,
        private readonly SecretPiiScanner $secretPiiScanner = new SecretPiiScanner,
        private readonly AssetReader $assetReader = new AssetReader,
        ?array $adapters = null,
    ) {
        $this->adapters = $adapters ?? [
            new ShadcnRegistryAdapter,
            new BasicMemoryAdapter,
            new InstitutaAdapter,
            new MarkdownAdapter,
        ];
    }

    public static function withDefaults(): self
    {
        return new self;
    }

    public function read(ReadRequestData $request): BrainRepositoryData
    {
        $root = PathHelper::normalize($request->path);
        $this->denylistScanner->assertAllowed($request->denylist, [
            $root,
            $request->source,
            basename($root),
        ]);

        if (! is_dir($root)) {
            return new BrainRepositoryData(
                path: $root,
                source: $request->source,
                commit: $request->commit,
                manifest: null,
                items: [],
                edges: [],
                skipped: [],
                issues: [
                    new ValidationIssueData(
                        path: $root,
                        code: 'path_not_directory',
                        message: 'Brain path is not a directory.',
                    ),
                ],
            );
        }

        $manifest = $this->readManifest($root);
        $items = [];
        $edges = [];
        $skipped = [];
        $issues = [];

        foreach ($this->files($root) as $file) {
            $absolute = PathHelper::normalize($file->getPathname());
            $relative = PathHelper::posixRelative($root, $absolute);

            $pathSkip = $this->secretPiiScanner->skipReasonForPath($relative);
            if ($pathSkip !== null) {
                $skipped[] = new SkippedFileData($relative, $pathSkip);

                continue;
            }

            // Images and PDFs enter as assets with metadata only (#311); their sidecars go with them.
            if (AssetReader::supports($relative)) {
                $asset = $this->assetReader->read($absolute, $relative, $request, $manifest, PathHelper::areaFromPath(...));

                if ($asset['item'] !== null) {
                    $items[] = $asset['item'];
                } else {
                    $skipped[] = new SkippedFileData($relative, $asset['skip_reason'] ?? 'unreadable');
                }

                continue;
            }

            if (AssetReader::isSidecar($relative)) {
                continue;
            }

            if (! $this->isReadableTextCandidate($relative)) {
                continue;
            }

            $contents = @file_get_contents($absolute);
            if ($contents === false) {
                $skipped[] = new SkippedFileData($relative, 'unreadable');

                continue;
            }

            if ($this->looksBinary($contents)) {
                $skipped[] = new SkippedFileData($relative, 'binary_content');

                continue;
            }

            $contentSkip = $this->secretPiiScanner->skipReasonForContent($contents);
            if ($contentSkip !== null) {
                $skipped[] = new SkippedFileData($relative, $contentSkip);

                continue;
            }

            if (strtolower(basename($relative)) === 'instituta.json') {
                continue;
            }

            $adapter = $this->adapterFor($relative, $contents, $manifest);
            if ($adapter === null) {
                continue;
            }

            $result = $adapter->readFile($absolute, $relative, $contents, $request, $manifest);
            foreach ($result['items'] as $item) {
                $items[] = $item;
                foreach ($item->edges as $edge) {
                    $edges[] = $edge;
                }
            }
            foreach ($result['skipped'] as $skip) {
                $skipped[] = $skip;
            }
            foreach ($result['issues'] as $issue) {
                $issues[] = $issue;
            }
        }

        return new BrainRepositoryData(
            path: $root,
            source: $request->source,
            commit: $request->commit,
            manifest: $manifest,
            items: $items,
            edges: $this->uniqueEdges($edges),
            skipped: $skipped,
            issues: $issues,
        );
    }

    /**
     * Validate without requiring a database. Denylist hits fail closed.
     *
     * @return list<ValidationIssueData>
     */
    public function validate(ReadRequestData $request): array
    {
        try {
            $result = $this->read($request);
        } catch (DenylistHitException $exception) {
            return [
                new ValidationIssueData(
                    path: $request->path,
                    code: 'denylist_hit',
                    message: $exception->getMessage(),
                    severity: 'error',
                ),
            ];
        } catch (UnsupportedManifestVersionException $exception) {
            return [
                new ValidationIssueData(
                    path: 'instituta.json',
                    code: 'manifest_version_unsupported',
                    message: $exception->getMessage(),
                    severity: 'error',
                ),
            ];
        }

        return $result->issues;
    }

    private function readManifest(string $root): ?ManifestData
    {
        $path = $root.'/instituta.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $version = is_int($decoded['format_version'] ?? null)
            ? $decoded['format_version']
            : (is_numeric($decoded['format_version'] ?? null) ? (int) $decoded['format_version'] : 1);

        if ($version !== self::SUPPORTED_MANIFEST_VERSION) {
            throw new UnsupportedManifestVersionException($version);
        }

        $areas = [];
        if (isset($decoded['areas']) && is_array($decoded['areas'])) {
            foreach ($decoded['areas'] as $area) {
                if (is_string($area)) {
                    $areas[] = $area;
                }
            }
        }

        return new ManifestData(
            format: is_string($decoded['format'] ?? null) ? $decoded['format'] : 'instituta',
            formatVersion: $version,
            name: is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
            defaultVisibility: VisibilityEnum::tryFromLoose($decoded['default_visibility'] ?? null) ?? VisibilityEnum::Internal,
            areas: $areas,
        );
    }

    /**
     * @return list<SplFileInfo>
     */
    private function files(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = PathHelper::posixRelative($root, $file->getPathname());
            if (str_starts_with($relative, '.git/')) {
                continue;
            }
            $files[] = $file;
        }

        usort(
            $files,
            static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()),
        );

        return $files;
    }

    private function adapterFor(string $relativePath, string $contents, ?ManifestData $manifest): ?BrainAdapterContract
    {
        foreach ($this->adapters as $adapter) {
            if (! $adapter->supports($relativePath, $contents)) {
                continue;
            }

            // With a native manifest, prefer InstitutaAdapter over plain MarkdownAdapter.
            if ($manifest !== null
                && $adapter instanceof MarkdownAdapter
                && ! $adapter instanceof InstitutaAdapter
                && ! $adapter instanceof BasicMemoryAdapter
                && PathHelper::extension($relativePath) === 'md'
            ) {
                continue;
            }

            return $adapter;
        }

        if ($manifest !== null && PathHelper::extension($relativePath) === 'md') {
            return new InstitutaAdapter;
        }

        return null;
    }

    private function isReadableTextCandidate(string $relativePath): bool
    {
        $ext = PathHelper::extension($relativePath);

        return in_array($ext, ['md', 'json', 'yml', 'yaml', 'txt'], true);
    }

    private function looksBinary(string $contents): bool
    {
        if ($contents === '') {
            return false;
        }

        return str_contains(substr($contents, 0, 1024), "\0");
    }

    /**
     * @param  list<LinkEdgeData>  $edges
     * @return list<LinkEdgeData>
     */
    private function uniqueEdges(array $edges): array
    {
        $seen = [];
        $unique = [];
        foreach ($edges as $edge) {
            $key = $edge->fromPath.'|'.$edge->relation.'|'.$edge->target;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $edge;
        }

        return $unique;
    }
}
