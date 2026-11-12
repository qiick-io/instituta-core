<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Exceptions\DenylistHitException;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Organisation;
use App\Services\SourceRegistryService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use ValueError;

class InstitutaSourceAddCommand extends Command
{
    protected $signature = 'instituta:source:add
        {name : Source display name}
        {--organisation= : Organisation ULID}
        {--provider=local : local|git|github}
        {--remote-url= : Git / GitHub remote URL}
        {--local-path= : Absolute local path}
        {--ref=main : Branch or tag ref}
        {--path= : Optional subpath inside the repo}
        {--adapter=instituta : instituta|basic-memory|shadcn-registry|markdown}
        {--visibility=internal : public|internal|restricted}
        {--shared : Mark as org-level shared source}
        {--scope-type=organisation : organisation|company|product}
        {--company-id= : Company ULID when scoped}
        {--product-id= : Product ULID when scoped}';

    protected $description = 'Register a read-only ingest source (denylist enforced)';

    public function handle(SourceRegistryService $registry): int
    {
        $organisationId = $this->option('organisation');
        if (! is_string($organisationId) || $organisationId === '') {
            $this->error('--organisation is required.');

            return self::FAILURE;
        }

        $organisation = Organisation::query()->find($organisationId);
        if ($organisation === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }

        try {
            $source = $registry->add(
                organisation: $organisation,
                name: (string) $this->argument('name'),
                provider: SourceProviderEnum::from((string) $this->option('provider')),
                remoteUrl: $this->stringOption('remote-url'),
                localPath: $this->stringOption('local-path'),
                ref: $this->stringOption('ref') ?? 'main',
                path: $this->stringOption('path'),
                adapter: SourceAdapterEnum::from((string) $this->option('adapter')),
                defaultVisibility: VisibilityEnum::from((string) $this->option('visibility')),
                isShared: (bool) $this->option('shared'),
                scopeType: ScopeTypeEnum::from((string) $this->option('scope-type')),
                companyId: $this->stringOption('company-id'),
                productId: $this->stringOption('product-id'),
            );
        } catch (DenylistHitException|InvalidArgumentException|ValueError $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Source registered.');
        $this->line('id: '.$source->id);
        $this->line('provider: '.$source->provider->value);
        $this->line('name: '.$source->name);

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
