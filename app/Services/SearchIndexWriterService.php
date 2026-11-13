<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\ItemSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps SQLite FTS5 rows in sync with item_sections / items.
 * Postgres uses a generated tsvector column — no writer work.
 */
final class SearchIndexWriterService
{
    public function upsertSection(ItemSection $section): void
    {
        if (! $this->sqliteFtsReady()) {
            return;
        }

        $item = $section->relationLoaded('item')
            ? $section->item
            : Item::query()->find($section->item_id);

        if ($item === null) {
            return;
        }

        DB::table('item_sections_fts')->where('section_id', $section->id)->delete();
        DB::table('item_sections_fts')->insert([
            'section_id' => $section->id,
            'item_id' => $section->item_id,
            'organisation_id' => $section->organisation_id,
            'company_id' => $section->company_id,
            'product_id' => $section->product_id,
            'title' => $item->title,
            'summary' => $item->summary ?? '',
            'heading' => $section->heading,
            'body' => $section->body,
        ]);
    }

    public function deleteSection(string $sectionId): void
    {
        if (! $this->sqliteFtsReady()) {
            return;
        }

        DB::table('item_sections_fts')->where('section_id', $sectionId)->delete();
    }

    /**
     * Drop FTS rows for items removed by a source re-sync (query deletes skip observers).
     *
     * @param  list<string>  $itemIds
     */
    public function deleteByItemIds(array $itemIds): void
    {
        if ($itemIds === [] || ! $this->sqliteFtsReady()) {
            return;
        }

        DB::table('item_sections_fts')->whereIn('item_id', $itemIds)->delete();
    }

    public function refreshItem(Item $item): void
    {
        if (! $this->sqliteFtsReady()) {
            return;
        }

        $sections = ItemSection::query()->where('item_id', $item->id)->get();
        foreach ($sections as $section) {
            $section->setRelation('item', $item);
            $this->upsertSection($section);
        }
    }

    private function sqliteFtsReady(): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }

        return Schema::hasTable('item_sections_fts');
    }
}
