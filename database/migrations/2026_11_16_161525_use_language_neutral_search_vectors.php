<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres search vectors use the language-neutral `simple` configuration instead of `english`
 * (#217): brains are often Italian or German, and English stemming mangled them. Matching relies
 * on prefixes (`term:*`) instead of stems. SQLite FTS5 is untouched (it never stemmed).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->useConfiguration('simple');
    }

    public function down(): void
    {
        $this->useConfiguration('english');
    }

    private function useConfiguration(string $configuration): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION instituta_item_sections_search_vector()
            RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector := to_tsvector(
                    '{$configuration}',
                    coalesce(NEW.heading, '') || ' ' || coalesce(NEW.body, '')
                );
                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<SQL
            UPDATE item_sections
            SET search_vector = to_tsvector('{$configuration}', coalesce(heading, '') || ' ' || coalesce(body, ''))
            SQL);
    }
};
