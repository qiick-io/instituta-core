<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Where the bytes of brain assets live (#311): a content-addressed copy on the private `local`
 * disk (`brain-assets/ab/abcdef…`), written by the sync and read by the asset route. Copying at sync
 * time decouples the app from the source's checkout: Git mirrors move between refs and local
 * folders change under the app, while a hash keeps pointing at the bytes that were indexed.
 * Identical files across sources share one copy.
 *
 * ponytail: blobs no item points at any more are not deleted yet; a prune pass belongs here once
 * brains grow large enough for it to matter.
 */
final class BrainAssetStorageService
{
    private const string DISK = 'local';

    /**
     * Copies the file at `$absolutePath` in when no copy with that hash exists yet.
     *
     * @side-effect Writes to the private local disk.
     */
    public function store(string $absolutePath, string $sha256): void
    {
        if (! $this->isValidHash($sha256) || $this->has($sha256) || ! is_file($absolutePath)) {
            return;
        }

        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            return;
        }

        Storage::disk(self::DISK)->writeStream($this->relativePath($sha256), $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function has(string $sha256): bool
    {
        return $this->isValidHash($sha256) && Storage::disk(self::DISK)->exists($this->relativePath($sha256));
    }

    /**
     * The absolute path of the stored copy, for streaming it in a response.
     */
    public function absolutePath(string $sha256): string
    {
        return Storage::disk(self::DISK)->path($this->relativePath($sha256));
    }

    private function relativePath(string $sha256): string
    {
        return 'brain-assets/'.substr($sha256, 0, 2).'/'.$sha256;
    }

    /**
     * Only a full lowercase SHA-256 names a blob, so a hash can never walk out of the folder.
     */
    private function isValidHash(string $sha256): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $sha256) === 1;
    }
}
