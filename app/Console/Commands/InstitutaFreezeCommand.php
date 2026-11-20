<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Principal;
use App\Models\VaultVersion;
use App\Services\ContextFreezeService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'instituta:freeze')]
class InstitutaFreezeCommand extends Command
{
    protected $signature = 'instituta:freeze
        {vault : Vault version UUID}
        {--principal= : Principal UUID that freezes}
        {--reason= : Why the freeze was raised}
        {--company= : Optional company UUID scope}
        {--product= : Optional product UUID scope}';

    protected $description = 'Freeze a scope to a vault version (emergency pin)';

    public function handle(ContextFreezeService $freezes): int
    {
        $vault = VaultVersion::query()->find($this->argument('vault'));
        if ($vault === null) {
            $this->error('Vault version not found.');

            return self::FAILURE;
        }

        $principalId = $this->option('principal');
        $reason = $this->option('reason');
        if (! is_string($principalId) || $principalId === '' || ! is_string($reason) || trim($reason) === '') {
            $this->error('--principal and --reason are required.');

            return self::FAILURE;
        }

        $principal = Principal::query()->find($principalId);
        if ($principal === null) {
            $this->error('Principal not found.');

            return self::FAILURE;
        }

        $company = $this->option('company');
        $product = $this->option('product');

        try {
            $freeze = $freezes->freeze(
                vault: $vault,
                principal: $principal,
                reason: $reason,
                companyId: is_string($company) && $company !== '' ? $company : null,
                productId: is_string($product) && $product !== '' ? $product : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Context frozen: '.$freeze->id);
        $this->line('vault: '.$freeze->vault_version_id);

        return self::SUCCESS;
    }
}
