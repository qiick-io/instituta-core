<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\SourceFormData;
use App\Enums\SourceProviderEnum;
use App\Models\Source;
use App\Services\DenylistGuardService;
use InvalidArgumentException;

/**
 * Changes a source's settings from the Sources page (#201). The never-ingest denylist is checked
 * again, since the name, path or remote may change. Content is re-read on the next sync.
 *
 * @throws InvalidArgumentException When the provider's location is missing.
 */
final class UpdateSourceAction
{
    public function __construct(
        private readonly DenylistGuardService $denylist = new DenylistGuardService,
    ) {}

    public function execute(Source $source, SourceFormData $data): Source
    {
        $isLocal = $data->provider === SourceProviderEnum::Local;

        if ($isLocal ? $data->localPath === null : $data->remoteUrl === null) {
            throw new InvalidArgumentException($isLocal ? 'Local sources need a local path.' : 'Git and GitHub sources need a remote URL.');
        }

        $this->denylist->assertAllowed($data->name, $data->remoteUrl, $data->localPath);

        $source->forceFill([
            'name' => $data->name,
            'provider' => $data->provider,
            'remote_url' => $isLocal ? null : $data->remoteUrl,
            'local_path' => $isLocal ? $data->localPath : null,
            'ref' => $data->ref,
            'path' => $data->path,
            'adapter' => $data->adapter,
            'default_visibility' => $data->defaultVisibility,
            'is_shared' => $data->isShared,
            'sync_interval_minutes' => ($data->syncIntervalMinutes ?? 0) > 0 ? $data->syncIntervalMinutes : null,
        ])->save();

        return $source;
    }
}
