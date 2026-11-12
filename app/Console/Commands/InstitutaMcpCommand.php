<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InstitutaMcpCommand extends Command
{
    protected $signature = 'instituta:mcp
        {--path= : Absolute path to a brain repository}';

    protected $description = 'Start the local stdio MCP server for a brain repository folder';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! is_string($path) || $path === '') {
            $this->error('The --path option is required.');

            return self::FAILURE;
        }

        if (! is_dir($path)) {
            $this->error("Brain path [{$path}] is not a directory.");

            return self::FAILURE;
        }

        putenv('INSTITUTA_BRAIN_PATH='.$path);
        $_ENV['INSTITUTA_BRAIN_PATH'] = $path;
        $_SERVER['INSTITUTA_BRAIN_PATH'] = $path;

        return $this->call('mcp:start', ['handle' => 'instituta-local']);
    }
}
