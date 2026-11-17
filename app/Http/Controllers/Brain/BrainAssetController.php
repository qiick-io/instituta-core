<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Brain\Readers\AssetReader;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\BrainAssetStorageService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves the bytes of a brain asset (#311) to a person who can read it: the item must be inside
 * the session perimeter and pass its policy, exactly like opening it in the Explorer; 404
 * otherwise. The content type comes from the file's extension, never from the bytes; `nosniff`
 * stops browsers guessing. An SVG is served under a sandboxing CSP, so a script inside it never
 * runs. `?download=1` saves the file under its own name.
 */
final class BrainAssetController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainAssetStorageService $assets,
    ) {}

    public function __invoke(Request $request, string $item): BinaryFileResponse
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        Gate::authorize('viewAny', Item::class);

        /** @var Item|null $asset */
        $asset = Item::query()->withinPerimeter($perimeter)->whereKey($item)->first();
        $metadata = $asset?->frontmatter['asset'] ?? null;
        $mime = $asset !== null ? AssetReader::mimeType($asset->path) : null;
        $sha256 = is_array($metadata) && is_string($metadata['sha256'] ?? null) ? $metadata['sha256'] : '';

        abort_if($asset === null || $mime === null || ! $this->assets->has($sha256), 404);

        Gate::authorize('view', $asset);

        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'ETag' => '"'.$sha256.'"',
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox";
        }

        $response = new BinaryFileResponse($this->assets->absolutePath($sha256), 200, $headers);
        $response->setContentDisposition(
            $request->boolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            basename($asset->path),
            preg_replace('/[^\x20-\x7e]/', '_', basename($asset->path)) ?? 'asset',
        );

        return $response;
    }
}
