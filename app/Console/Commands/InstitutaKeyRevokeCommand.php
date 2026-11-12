<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeyIssuerService;
use Illuminate\Console\Command;

class InstitutaKeyRevokeCommand extends Command
{
    protected $signature = 'instituta:key:revoke {key : API key ULID}';

    protected $description = 'Revoke an API key';

    public function handle(ApiKeyIssuerService $issuer): int
    {
        $key = ApiKey::query()->find($this->argument('key'));
        if ($key === null) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $issuer->revoke($key);
        $this->info('API key revoked: '.$key->id);

        return self::SUCCESS;
    }
}
