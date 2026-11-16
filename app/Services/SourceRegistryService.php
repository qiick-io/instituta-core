<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Enums\SyncStatusEnum;
use App\Models\Organisation;
use App\Models\Source;
use InvalidArgumentException;

/**
 * Registers ingest sources (local | git | github) with denylist enforcement.
 */
final class SourceRegistryService
{
    public const int DEFAULT_SYNC_INTERVAL_MINUTES = 15;

    public function __construct(
        private readonly DenylistGuardService $denylist = new DenylistGuardService,
    ) {}

    public function add(
        Organisation $organisation,
        string $name,
        SourceProviderEnum $provider,
        ?string $remoteUrl = null,
        ?string $localPath = null,
        ?string $ref = 'main',
        ?string $path = null,
        SourceAdapterEnum $adapter = SourceAdapterEnum::Instituta,
        VisibilityEnum $defaultVisibility = VisibilityEnum::Internal,
        bool $isShared = false,
        ScopeTypeEnum $scopeType = ScopeTypeEnum::Organisation,
        ?string $companyId = null,
        ?string $productId = null,
        ?int $syncIntervalMinutes = null,
    ): Source {
        // $syncIntervalMinutes: null = provider default, 0 = no scheduled sync.
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Source name is required.');
        }

        $remoteUrl = $this->nullableTrim($remoteUrl);
        $localPath = $this->nullableTrim($localPath);
        $path = $this->nullableTrim($path);
        $ref = $this->nullableTrim($ref);

        match ($provider) {
            SourceProviderEnum::Local => $this->assertLocal($localPath),
            SourceProviderEnum::Git, SourceProviderEnum::Github => $this->assertRemote($remoteUrl),
        };

        $this->denylist->assertAllowed($name, $remoteUrl, $localPath);

        $source = Source::query()->create([
            'organisation_id' => $organisation->id,
            'company_id' => $companyId,
            'product_id' => $productId,
            'scope_type' => $scopeType,
            'name' => $name,
            'provider' => $provider,
            'remote_url' => $remoteUrl,
            'local_path' => $localPath,
            'sync_interval_minutes' => match (true) {
                $syncIntervalMinutes === null => self::defaultSyncIntervalFor($provider),
                $syncIntervalMinutes <= 0 => null,
                default => $syncIntervalMinutes,
            },
            'ref' => $ref,
            'path' => $path,
            'adapter' => $adapter,
            'default_visibility' => $defaultVisibility,
            'is_shared' => $isShared,
            'sync_status' => SyncStatusEnum::Idle,
        ]);

        app(ActivityEventLoggerService::class)->sourceAdded($source);

        return $source;
    }

    private function assertLocal(?string $localPath): void
    {
        if ($localPath === null || $localPath === '') {
            throw new InvalidArgumentException('Local sources require --local-path.');
        }
    }

    private function assertRemote(?string $remoteUrl): void
    {
        if ($remoteUrl === null || $remoteUrl === '') {
            throw new InvalidArgumentException('Git and GitHub sources require --remote-url.');
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Remote sources are re-synced on a schedule by default (#199); a local folder is synced on
     * demand, since nothing tells us when it changes.
     */
    public static function defaultSyncIntervalFor(SourceProviderEnum $provider): ?int
    {
        return match ($provider) {
            SourceProviderEnum::Local => null,
            SourceProviderEnum::Git, SourceProviderEnum::Github => self::DEFAULT_SYNC_INTERVAL_MINUTES,
        };
    }
}
