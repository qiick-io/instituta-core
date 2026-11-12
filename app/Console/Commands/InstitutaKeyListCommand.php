<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Principal;
use App\Services\ApiKeyIssuerService;
use Illuminate\Console\Command;

class InstitutaKeyListCommand extends Command
{
    protected $signature = 'instituta:key:list {principal : Principal ULID}';

    protected $description = 'List API keys for a principal (never shows the hash or plain secret)';

    public function handle(ApiKeyIssuerService $issuer): int
    {
        $principal = Principal::query()->find($this->argument('principal'));
        if ($principal === null) {
            $this->error('Principal not found.');

            return self::FAILURE;
        }

        $rows = $issuer->listForPrincipal($principal)->map(static fn ($key): array => [
            $key->id,
            $key->name,
            $key->display_prefix,
            $key->scope_type->value,
            $key->scope_id,
            $key->role->value,
            $key->max_visibility->value,
            $key->revoked_at?->toIso8601String() ?? '-',
            $key->expires_at?->toIso8601String() ?? '-',
        ])->all();

        $this->table(
            ['id', 'name', 'display_prefix', 'scope_type', 'scope_id', 'role', 'max_visibility', 'revoked_at', 'expires_at'],
            $rows,
        );

        return self::SUCCESS;
    }
}
