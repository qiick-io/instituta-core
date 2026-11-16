<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores the logos of clients and products (#257) on the public disk: a new upload replaces the
 * old file, a removal deletes it. Requests accept raster images only (no SVG, which can carry
 * script), so the files are safe to serve as they are.
 */
final class LogoStorageService
{
    private const string DISK = 'public';

    /**
     * The logo path to keep after a form save.
     *
     * @param  'companies'|'products'  $folder
     */
    public function update(?string $currentPath, ?UploadedFile $upload, bool $shouldRemove, string $folder): ?string
    {
        if ($upload === null && ! $shouldRemove) {
            return $currentPath;
        }

        $this->delete($currentPath);

        if ($upload === null) {
            return null;
        }

        $storedPath = $upload->store('logos/'.$folder, self::DISK);

        return $storedPath === false ? null : $storedPath;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk(self::DISK)->url($path);
    }
}
