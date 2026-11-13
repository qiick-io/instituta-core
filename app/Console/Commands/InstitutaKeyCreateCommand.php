<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateApiKeyAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Principal;
use Illuminate\Console\Command;
use InvalidArgumentException;

class InstitutaKeyCreateCommand extends Command
{
    protected $signature = 'instituta:key:create
        {principal : Principal ULID}
        {--name= : Key display name}
        {--scope-type=organisation : organisation|company|product}
        {--scope-id= : Scope ULID}
        {--role=member : owner|member|guest}
        {--max-visibility=internal : public|internal|restricted}
        {--expires-at= : Optional ISO-8601 expiry}';

    protected $description = 'Create an API key (plain secret shown once)';

    public function handle(CreateApiKeyAction $createApiKey): int
    {
        $principal = Principal::query()->find($this->argument('principal'));
        if ($principal === null) {
            $this->error('Principal not found.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $scopeId = $this->option('scope-id');
        if (! is_string($name) || $name === '' || ! is_string($scopeId) || $scopeId === '') {
            $this->error('--name and --scope-id are required.');

            return self::FAILURE;
        }

        try {
            $scopeType = ScopeTypeEnum::from((string) $this->option('scope-type'));
            $role = RoleEnum::from((string) $this->option('role'));
            $visibility = VisibilityEnum::from((string) $this->option('max-visibility'));
            $expires = $this->option('expires-at');
            $result = $createApiKey->execute(
                principal: $principal,
                name: $name,
                scopeType: $scopeType,
                scopeId: $scopeId,
                role: $role,
                maxVisibility: $visibility,
                expiresAt: is_string($expires) && $expires !== '' ? new \DateTimeImmutable($expires) : null,
            );
        } catch (InvalidArgumentException|\ValueError $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('API key created. Store the plain secret; it will not be shown again.');
        $this->line('id: '.$result['key']->id);
        $this->line('display_prefix: '.$result['key']->display_prefix);
        $this->line('plain: '.$result['plain']);

        return self::SUCCESS;
    }
}
