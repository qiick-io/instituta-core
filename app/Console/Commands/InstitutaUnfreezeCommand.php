<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContextFreeze;
use App\Services\ContextFreezeService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'instituta:unfreeze')]
class InstitutaUnfreezeCommand extends Command
{
    protected $signature = 'instituta:unfreeze {freeze : Context freeze UUID}';

    protected $description = 'Lift an active context freeze';

    public function handle(ContextFreezeService $freezes): int
    {
        $freeze = ContextFreeze::query()->find($this->argument('freeze'));
        if ($freeze === null) {
            $this->error('Context freeze not found.');

            return self::FAILURE;
        }

        $lifted = $freezes->unfreeze($freeze);
        $this->info('Context unfrozen: '.$lifted->id);
        $this->line('lifted_at: '.$lifted->lifted_at?->toIso8601String());

        return self::SUCCESS;
    }
}
