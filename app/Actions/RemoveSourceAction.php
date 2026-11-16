<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Item;
use App\Models\Source;
use App\Models\User;
use App\Services\SearchIndexWriterService;
use Illuminate\Support\Facades\DB;

/**
 * Removes a source and everything indexed from it (#201). The brain repository itself is never
 * touched: Instituta only reads it. Items, sections, links and tables go by cascade; the search
 * index rows are removed first, because the SQLite FTS table has no foreign key.
 *
 * @side-effect Deletes index rows and search rows; logs `source.removed`.
 */
final class RemoveSourceAction
{
    public function __construct(
        private readonly SearchIndexWriterService $searchIndex = new SearchIndexWriterService,
    ) {}

    public function execute(Source $source, ?User $actor = null): void
    {
        DB::transaction(function () use ($source, $actor): void {
            /** @var list<string> $itemIds */
            $itemIds = Item::query()->where('source_id', $source->id)->pluck('id')->all();
            $this->searchIndex->deleteByItemIds($itemIds);

            activity('ingest')
                ->causedBy($actor)
                ->event('source.removed')
                ->withProperties([
                    'organisation_id' => $source->organisation_id,
                    'source_id' => $source->id,
                    'name' => $source->name,
                    'items_removed' => count($itemIds),
                ])
                ->log('source.removed');

            $source->delete();
        });
    }
}
