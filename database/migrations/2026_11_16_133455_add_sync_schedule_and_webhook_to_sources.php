<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the index fresh without a person running `instituta:sync` (#199): a per-source sync
 * interval for the scheduler, a per-source webhook secret for push events, and when a
 * materialised proposal was seen merged in the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sync_interval_minutes')->nullable()->after('ref');
            $table->text('webhook_secret')->nullable()->after('sync_interval_minutes');
        });

        // Remote sources were only synced by hand until now: give them the default interval.
        DB::table('sources')
            ->whereIn('provider', ['git', 'github'])
            ->update(['sync_interval_minutes' => 15]);

        Schema::table('proposals', function (Blueprint $table): void {
            $table->timestamp('merged_at')->nullable()->after('materialised_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn('merged_at');
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn(['sync_interval_minutes', 'webhook_secret']);
        });
    }
};
