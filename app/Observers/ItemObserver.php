<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Item;
use App\Services\SearchIndexWriterService;

final class ItemObserver
{
    public function __construct(private readonly SearchIndexWriterService $writer) {}

    public function saved(Item $item): void
    {
        if ($item->wasRecentlyCreated || $item->wasChanged(['title', 'summary'])) {
            $this->writer->refreshItem($item);
        }
    }
}
