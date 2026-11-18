<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-area access (#334). A grant's single `area` and `path_prefix` become the lists `areas` and
 * `path_prefixes` (JSON); NULL keeps meaning "no limit", so an empty list is never stored. User
 * group access and API keys get the same limits, and group access an optional `max_visibility`
 * (NULL: it follows the role, as before).
 *
 * The backfill runs in PHP, row by row, so it reads the same on SQLite, PostgreSQL and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table): void {
            $table->json('areas')->nullable()->after('max_visibility');
            $table->json('path_prefixes')->nullable()->after('areas');
        });

        DB::table('grants')
            ->where(fn ($query) => $query->whereNotNull('area')->orWhereNotNull('path_prefix'))
            ->orderBy('id')
            ->each(function (object $grant): void {
                DB::table('grants')->where('id', $grant->id)->update([
                    'areas' => self::singleValueList($grant->area),
                    'path_prefixes' => self::singleValueList($grant->path_prefix),
                ]);
            });

        Schema::table('grants', function (Blueprint $table): void {
            $table->dropColumn(['area', 'path_prefix']);
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->json('areas')->nullable()->after('max_visibility');
            $table->json('path_prefixes')->nullable()->after('areas');
        });

        Schema::table('user_group_brain_access', function (Blueprint $table): void {
            $table->string('max_visibility', 16)->nullable()->after('role');
            $table->json('areas')->nullable()->after('max_visibility');
            $table->json('path_prefixes')->nullable()->after('areas');
        });
    }

    public function down(): void
    {
        Schema::table('user_group_brain_access', function (Blueprint $table): void {
            $table->dropColumn(['max_visibility', 'areas', 'path_prefixes']);
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn(['areas', 'path_prefixes']);
        });

        Schema::table('grants', function (Blueprint $table): void {
            $table->string('path_prefix')->nullable();
            $table->string('area')->nullable();
        });

        // A grant with several areas or prefixes keeps only its first one: the old schema holds one.
        DB::table('grants')
            ->where(fn ($query) => $query->whereNotNull('areas')->orWhereNotNull('path_prefixes'))
            ->orderBy('id')
            ->each(function (object $grant): void {
                DB::table('grants')->where('id', $grant->id)->update([
                    'area' => self::firstValue($grant->areas),
                    'path_prefix' => self::firstValue($grant->path_prefixes),
                ]);
            });

        Schema::table('grants', function (Blueprint $table): void {
            $table->dropColumn(['areas', 'path_prefixes']);
        });
    }

    /**
     * The JSON list holding one old value, or NULL (no limit) for an empty one.
     */
    private static function singleValueList(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? (string) json_encode([$value]) : null;
    }

    private static function firstValue(mixed $json): ?string
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        return is_array($values) && is_string($values[0] ?? null) ? $values[0] : null;
    }
};
