<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmbedItemSectionJob;
use App\Models\ItemSection;
use App\Services\BrainEmbeddingService;
use Illuminate\Console\Command;

/**
 * Embed or re-embed item sections for the active embedding model version (#65).
 */
final class InstitutaEmbedCommand extends Command
{
    protected $signature = 'instituta:embed
        {--section= : Embed a single item_sections.id}
        {--queue : Dispatch EmbedItemSectionJob instead of running inline}
        {--force : Re-embed even when the content hash and model match}';

    protected $description = 'Compute embeddings for item sections (off unless AI_EMBEDDINGS_ENABLED=true)';

    public function handle(BrainEmbeddingService $embeddings): int
    {
        if (! config('ai.embeddings.enabled')) {
            $this->warn('Embeddings are disabled. Set AI_EMBEDDINGS_ENABLED=true to run.');

            return self::SUCCESS;
        }

        $sectionId = $this->option('section');
        $sectionId = is_string($sectionId) && $sectionId !== '' ? $sectionId : null;

        $sections = $this->option('force') && $sectionId === null
            ? ItemSection::query()->orderBy('id')->get()
            : $embeddings->sectionsNeedingEmbed($sectionId);

        if ($sections->isEmpty()) {
            $this->info('No sections need embedding.');

            return self::SUCCESS;
        }

        $queued = (bool) $this->option('queue');
        $done = 0;

        foreach ($sections as $section) {
            if ($queued) {
                EmbedItemSectionJob::dispatch($section->id);
            } else {
                $embeddings->embedSection($section);
            }

            $done++;
        }

        $this->info(($queued ? 'Queued' : 'Embedded')." {$done} section(s).");

        return self::SUCCESS;
    }
}
