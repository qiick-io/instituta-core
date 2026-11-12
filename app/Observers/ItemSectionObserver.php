<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ItemSection;
use App\Services\SearchIndexWriterService;

final class ItemSectionObserver
{
    public function __construct(private readonly SearchIndexWriterService $writer) {}

    public function saved(ItemSection $section): void
    {
        $this->writer->upsertSection($section);
    }

    public function deleted(ItemSection $section): void
    {
        $this->writer->deleteSection($section->id);
    }
}
