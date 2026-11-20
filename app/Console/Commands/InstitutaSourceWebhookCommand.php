<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\SourceWebhookService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sets up (or rotates, or removes) the push webhook of a source and prints what to paste in
 * the repository's webhook settings. The secret is shown once.
 */
#[AsCommand(name: 'instituta:source:webhook')]
class InstitutaSourceWebhookCommand extends Command
{
    protected $signature = 'instituta:source:webhook
        {source : Source UUID}
        {--disable : Remove the secret; the webhook URL then answers 404}';

    protected $description = 'Create or rotate the push webhook secret of a source and print the URL';

    public function handle(SourceWebhookService $webhooks): int
    {
        $source = Source::query()->find((string) $this->argument('source'));

        if (! $source instanceof Source) {
            $this->error('Source not found.');

            return self::FAILURE;
        }

        if ((bool) $this->option('disable')) {
            $source->forceFill(['webhook_secret' => null])->save();
            $this->info("Webhook disabled for {$source->name}.");

            return self::SUCCESS;
        }

        $secret = $webhooks->rotateSecret($source);

        $this->info("Webhook ready for {$source->name}. Store the secret now; it will not be shown again.");
        $this->line('Payload URL:  '.route('webhooks.sources', $source->id));
        $this->line('Content type: application/json');
        $this->line('Secret:       '.$secret);
        $this->line('Events:       push');

        return self::SUCCESS;
    }
}
