<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class ReadRequestData
{
    /**
     * Absolute filesystem path to the brain repository root.
     *
     * @param  list<string>  $denylist  Case-insensitive tokens matched on path / remote / name
     */
    public function __construct(
        public string $path,
        public string $source = 'local',
        public ?string $commit = null,
        public array $denylist = ['klover', 'cybergrant', 'zerotredici'],
    ) {}
}
