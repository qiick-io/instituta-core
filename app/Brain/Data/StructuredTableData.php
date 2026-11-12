<?php

declare(strict_types=1);

namespace App\Brain\Data;

final readonly class StructuredTableData
{
    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $values
     */
    public function __construct(
        public string $tableKey,
        public array $columns,
        public array $values,
        public int $position = 0,
    ) {}

    /**
     * @return array{
     *     table_key: string,
     *     columns: list<array<string, mixed>>,
     *     values: list<array<string, mixed>>,
     *     position: int
     * }
     */
    public function toArray(): array
    {
        return [
            'table_key' => $this->tableKey,
            'columns' => $this->columns,
            'values' => $this->values,
            'position' => $this->position,
        ];
    }
}
