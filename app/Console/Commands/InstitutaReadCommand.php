<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brain\Data\ReadRequestData;
use App\Brain\Exceptions\DenylistHitException;
use App\Brain\Readers\LocalContractReader;
use Illuminate\Console\Command;
use JsonException;

class InstitutaReadCommand extends Command
{
    protected $signature = 'instituta:read
        {action : list|search|get|outline|resolve|graph|validate}
        {id? : Item path or stable_key (get|outline|graph)}
        {--path= : Absolute path to a brain repository}
        {--q= : Search query}
        {--area= : Filter by area}
        {--type= : Filter by type}
        {--limit=20 : Search limit}
        {--source=local : Citation source name}
        {--commit= : Optional commit SHA for citations}';

    protected $description = 'Read a local brain repository without the database';

    public function handle(LocalContractReader $contract): int
    {
        $path = $this->option('path');
        if (! is_string($path) || $path === '') {
            $this->error('The --path option is required.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        $request = new ReadRequestData(
            path: $path,
            source: is_string($this->option('source')) ? $this->option('source') : 'local',
            commit: is_string($this->option('commit')) && $this->option('commit') !== '' ? $this->option('commit') : null,
            denylist: config('instituta.ingest.denylist', ['klover', 'cybergrant', 'zerotredici']),
        );

        try {
            $payload = match ($action) {
                'validate' => [
                    'issues' => array_map(
                        static fn ($issue) => $issue->toArray(),
                        $contract->validate($request),
                    ),
                ],
                'list', 'search', 'get', 'outline', 'resolve', 'graph' => $this->query($contract, $request, $action),
                default => null,
            };
        } catch (DenylistHitException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($payload === null) {
            $this->error('Unknown action. Use list|search|get|outline|resolve|graph|validate.');

            return self::FAILURE;
        }

        try {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(LocalContractReader $contract, ReadRequestData $request, string $action): array
    {
        $repo = $contract->open($request);
        $area = $this->stringOption('area');
        $type = $this->stringOption('type');
        $id = $this->argument('id');

        return match ($action) {
            'list' => ['items' => $contract->list($repo, $area, $type)],
            'search' => ['items' => $contract->search(
                $repo,
                $this->stringOption('q') ?? '',
                $area,
                (int) $this->option('limit'),
            )],
            'get' => ['item' => $contract->get($repo, is_string($id) ? $id : '')],
            'outline' => ['outline' => $contract->outline($repo, is_string($id) ? $id : '')],
            'resolve' => ['entries' => $contract->resolve($repo, $area)],
            'graph' => ['neighbours' => $contract->graph($repo, is_string($id) ? $id : '')],
            default => [],
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (is_bool($value) || $value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $string = (string) $value;

            return $string !== '' ? $string : null;
        }

        return null;
    }
}
