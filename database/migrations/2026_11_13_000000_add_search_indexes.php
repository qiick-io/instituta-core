<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE item_sections ADD COLUMN IF NOT EXISTS search_vector tsvector');
            DB::statement(<<<'SQL'
                UPDATE item_sections
                SET search_vector = to_tsvector(
                    'english',
                    coalesce(heading, '') || ' ' || coalesce(body, '')
                )
                WHERE search_vector IS NULL
                SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION instituta_item_sections_search_vector()
                RETURNS trigger AS $$
                BEGIN
                    NEW.search_vector := to_tsvector(
                        'english',
                        coalesce(NEW.heading, '') || ' ' || coalesce(NEW.body, '')
                    );
                    RETURN NEW;
                END
                $$ LANGUAGE plpgsql
                SQL);
            DB::statement('DROP TRIGGER IF EXISTS item_sections_search_vector_trigger ON item_sections');
            DB::statement(<<<'SQL'
                CREATE TRIGGER item_sections_search_vector_trigger
                BEFORE INSERT OR UPDATE OF heading, body ON item_sections
                FOR EACH ROW
                EXECUTE FUNCTION instituta_item_sections_search_vector()
                SQL);
            DB::statement('CREATE INDEX IF NOT EXISTS item_sections_search_vector_gin ON item_sections USING GIN (search_vector)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS item_sections_fts');
            DB::statement(<<<'SQL'
                CREATE VIRTUAL TABLE item_sections_fts USING fts5(
                    section_id UNINDEXED,
                    item_id UNINDEXED,
                    organisation_id UNINDEXED,
                    company_id UNINDEXED,
                    product_id UNINDEXED,
                    title,
                    summary,
                    heading,
                    body
                )
                SQL);

            return;
        }

        // MySQL / others: no search index in v0.1.0.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS item_sections_search_vector_trigger ON item_sections');
            DB::statement('DROP FUNCTION IF EXISTS instituta_item_sections_search_vector()');
            DB::statement('DROP INDEX IF EXISTS item_sections_search_vector_gin');
            DB::statement('ALTER TABLE item_sections DROP COLUMN IF EXISTS search_vector');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS item_sections_fts');
        }
    }
};
