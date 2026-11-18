<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\Quota;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * InnoDB rejects an index wider than 3072 bytes, and under utf8mb4 every `string(255)` column
 * costs 1020 of them, so a multi-column index on default strings stops the migration on MySQL
 * (issue #356). SQLite and PostgreSQL accept the same schema, which is how it went unnoticed.
 */
it('keeps every index within the InnoDB key length limit', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('The 3072-byte key limit is specific to InnoDB.');
    }

    $overLongIndexes = DB::select(<<<'SQL'
        select statistics.TABLE_NAME as table_name,
               statistics.INDEX_NAME as index_name,
               sum(case
                   when statistics.SUB_PART is not null
                       then statistics.SUB_PART * columns.CHARACTER_OCTET_LENGTH / columns.CHARACTER_MAXIMUM_LENGTH
                   else coalesce(columns.CHARACTER_OCTET_LENGTH, 8)
               end) as key_bytes
        from information_schema.STATISTICS as statistics
        join information_schema.COLUMNS as columns
            on columns.TABLE_SCHEMA = statistics.TABLE_SCHEMA
            and columns.TABLE_NAME = statistics.TABLE_NAME
            and columns.COLUMN_NAME = statistics.COLUMN_NAME
        where statistics.TABLE_SCHEMA = database()
            and statistics.INDEX_TYPE <> 'FULLTEXT'
        group by statistics.TABLE_NAME, statistics.INDEX_NAME
        having key_bytes > 3072
        SQL);

    expect($overLongIndexes)->toBe([]);
});

it('rejects a second quota for the same scope, metric and period', function (): void {
    $organisation = Organisation::factory()->create();
    $quotaAttributes = ['scope_id' => $organisation->id, 'metric' => 'contract_calls', 'period' => 'month'];

    Quota::factory()->create($quotaAttributes);

    expect(fn () => Quota::factory()->create($quotaAttributes))
        ->toThrow(UniqueConstraintViolationException::class);
});
