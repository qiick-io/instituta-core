<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Brain\Data\BrainRepositoryData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Readers\LocalContractReader;
use RuntimeException;

final class LocalBrainContext
{
    public function __construct(
        private readonly LocalContractReader $reader = new LocalContractReader,
    ) {}

    public function reader(): LocalContractReader
    {
        return $this->reader;
    }

    public function request(): ReadRequestData
    {
        $path = getenv('INSTITUTA_BRAIN_PATH') ?: ($_ENV['INSTITUTA_BRAIN_PATH'] ?? null);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('INSTITUTA_BRAIN_PATH is not set. Start via php artisan instituta:mcp --path=…');
        }

        /** @var list<string> $denylist */
        $denylist = config('instituta.ingest.denylist', ['klover', 'cybergrant', 'zerotredici']);

        return new ReadRequestData(
            path: $path,
            source: 'local-mcp',
            denylist: $denylist,
        );
    }

    public function repository(): BrainRepositoryData
    {
        return $this->reader->open($this->request());
    }
}
