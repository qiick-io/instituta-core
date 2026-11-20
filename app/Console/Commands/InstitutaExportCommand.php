<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ClientExportService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'instituta:export')]
class InstitutaExportCommand extends Command
{
    protected $signature = 'instituta:export
        {company : Company UUID or slug}
        {--path= : Destination directory (default under storage/instituta/exports)}';

    protected $description = 'Export a client company subtree (manifest + index JSON)';

    public function handle(ClientExportService $exporter): int
    {
        $ref = (string) $this->argument('company');
        $company = Company::query()
            ->where('id', $ref)
            ->orWhere('slug', $ref)
            ->first();

        if ($company === null) {
            $this->error('Company not found.');

            return self::FAILURE;
        }

        try {
            $result = $exporter->export(
                company: $company,
                destination: is_string($this->option('path')) && $this->option('path') !== ''
                    ? (string) $this->option('path')
                    : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Client export written.');
        $this->line('path: '.$result['path']);
        $this->line('offboarding: '.$result['request']->id);
        $this->line('items: '.$result['manifest']['counts']['items']);

        return self::SUCCESS;
    }
}
