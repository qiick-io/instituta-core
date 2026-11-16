<?php

declare(strict_types=1);

namespace App\Data;

use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;

/**
 * A source as the Sources page submits it (#201). `syncIntervalMinutes` 0 turns scheduled
 * syncs off; null keeps the provider default on create.
 */
readonly class SourceFormData
{
    public function __construct(
        public string $name,
        public SourceProviderEnum $provider,
        public ?string $remoteUrl,
        public ?string $localPath,
        public ?string $ref,
        public ?string $path,
        public SourceAdapterEnum $adapter,
        public VisibilityEnum $defaultVisibility,
        public bool $isShared,
        public ?int $syncIntervalMinutes,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $optionalString = static fn (string $key): ?string => isset($validated[$key]) && is_string($validated[$key]) && trim($validated[$key]) !== ''
            ? trim($validated[$key])
            : null;

        return new self(
            name: trim((string) $validated['name']),
            provider: SourceProviderEnum::from((string) $validated['provider']),
            remoteUrl: $optionalString('remote_url'),
            localPath: $optionalString('local_path'),
            ref: $optionalString('ref'),
            path: $optionalString('path'),
            adapter: SourceAdapterEnum::from((string) $validated['adapter']),
            defaultVisibility: VisibilityEnum::from((string) $validated['default_visibility']),
            isShared: (bool) ($validated['is_shared'] ?? false),
            syncIntervalMinutes: isset($validated['sync_interval_minutes']) && is_numeric($validated['sync_interval_minutes'])
                ? (int) $validated['sync_interval_minutes']
                : null,
        );
    }
}
