<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\Item;
use App\Models\StructuredTable;

/**
 * Structured tables (tokens, registries) inside the session perimeter.
 */
final class BrainTablesService
{
    /**
     * @return list<array{id: string, table_key: string, item_id: string, item_title: string, item_path: string, columns: list<mixed>, row_count: int}>
     */
    public function list(PerimeterData $perimeter): array
    {
        /** @var list<StructuredTable> $tables */
        $tables = StructuredTable::query()
            ->with(['item:id,title,path'])
            ->whereHas('item', static function ($query) use ($perimeter): void {
                $query->withinPerimeter($perimeter);
            })
            ->withCount('rows')
            ->orderBy('table_key')
            ->get()
            ->all();

        $rows = [];
        foreach ($tables as $table) {
            $item = $table->item;
            if (! $item instanceof Item) {
                continue;
            }

            $rows[] = [
                'id' => $table->id,
                'table_key' => $table->table_key,
                'item_id' => $item->id,
                'item_title' => $item->title,
                'item_path' => $item->path,
                'columns' => $table->columns,
                'row_count' => (int) $table->rows_count,
            ];
        }

        return $rows;
    }

    /**
     * @return array{id: string, table_key: string, item_id: string, item_title: string, item_path: string, columns: list<string>, rows: list<array<string, mixed>>}|null
     */
    public function show(PerimeterData $perimeter, string $itemId, string $tableKey): ?array
    {
        /** @var Item|null $item */
        $item = Item::query()
            ->withinPerimeter($perimeter)
            ->matchingReference($itemId)
            ->first();

        if ($item === null) {
            return null;
        }

        /** @var StructuredTable|null $table */
        $table = $item->structuredTables()
            ->where('table_key', $tableKey)
            ->first();

        if ($table === null) {
            return null;
        }

        $columnKeys = [];
        foreach ($table->columns as $column) {
            $columnKeys[] = is_string($column) ? $column : (string) $column;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $table->rows()
            ->orderBy('position')
            ->get()
            ->map(static function ($row) use ($columnKeys): array {
                $values = $row->values;
                $out = [];
                foreach ($columnKeys as $column) {
                    $out[$column] = $values[$column] ?? null;
                }

                return $out;
            })
            ->values()
            ->all();

        return [
            'id' => $table->id,
            'table_key' => $table->table_key,
            'item_id' => $item->id,
            'item_title' => $item->title,
            'item_path' => $item->path,
            'columns' => $columnKeys,
            'rows' => $rows,
        ];
    }
}
