<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OffboardingStatusEnum;
use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Services\ClientErasureService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'instituta:erase')]
class InstitutaEraseCommand extends Command
{
    protected $signature = 'instituta:erase
        {offboarding : Offboarding request UUID (must be exported)}';

    protected $description = 'Erase a client company subtree after export (index, replay, keys)';

    public function handle(ClientErasureService $erasure): int
    {
        $request = OffboardingRequest::query()->find((string) $this->argument('offboarding'));

        if (! $request instanceof OffboardingRequest) {
            $this->error('Offboarding request not found.');

            return self::FAILURE;
        }

        if ($request->status !== OffboardingStatusEnum::Exported) {
            $this->error('Offboarding request must be in exported status.');

            return self::FAILURE;
        }

        $company = Company::query()->find($request->company_id);

        if ($company === null) {
            $this->error('Company not found.');

            return self::FAILURE;
        }

        try {
            $result = $erasure->erase($request);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Client subtree erased.');
        $this->line('company: '.$company->slug);
        $this->line('deleted_items: '.$result['deleted_items']);
        $this->line('revoked_keys: '.$result['revoked_keys']);

        return self::SUCCESS;
    }
}
