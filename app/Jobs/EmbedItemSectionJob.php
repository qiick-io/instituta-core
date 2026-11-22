<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Middleware\SetPermissionsTeamJobMiddleware;
use App\Models\ItemSection;
use App\Services\BrainEmbeddingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue embed / re-embed for one item section when AI embeddings are enabled (#65).
 */
final class EmbedItemSectionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public string $itemSectionId) {}

    public function uniqueId(): string
    {
        return $this->itemSectionId;
    }

    /**
     * @return list<class-string>
     */
    public function middleware(): array
    {
        return [SetPermissionsTeamJobMiddleware::class];
    }

    public function organisationIdForPermissions(): ?string
    {
        $organisationId = ItemSection::query()->whereKey($this->itemSectionId)->value('organisation_id');

        return is_string($organisationId) && $organisationId !== '' ? $organisationId : null;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(BrainEmbeddingService $embeddings): void
    {
        if (! config('ai.embeddings.enabled')) {
            return;
        }

        $section = ItemSection::query()->find($this->itemSectionId);

        if ($section === null) {
            return;
        }

        try {
            $embeddings->embedSection($section);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        Log::warning('Failed to embed item section after retries', [
            'item_section_id' => $this->itemSectionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
