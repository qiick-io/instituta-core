<?php

declare(strict_types=1);

namespace App\Brain\Exceptions;

use RuntimeException;

final class UnsupportedManifestVersionException extends RuntimeException
{
    public function __construct(public readonly int $version)
    {
        parent::__construct(sprintf('Unsupported instituta.json format_version: %d.', $version));
    }
}
