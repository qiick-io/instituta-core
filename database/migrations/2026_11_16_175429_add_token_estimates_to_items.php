<?php

declare(strict_types=1);

use App\Models\ItemSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token estimates on items and sections (#230), so agents plan reads within their context
 * budget. Computed at sync; existing rows are backfilled here from the stored markdown so an
 * unchanged source (whose syncs are skipped) does not keep zeros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_sections', function (Blueprint $table): void {
            $table->unsignedInteger('token_estimate')->default(0)->after('markdown');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedInteger('token_estimate')->default(0)->after('content_hash');
        });

        DB::table('item_sections')->select(['id', 'markdown', 'body'])->orderBy('id')->chunk(500, function ($sections): void {
            foreach ($sections as $section) {
                DB::table('item_sections')->where('id', $section->id)->update([
                    'token_estimate' => ItemSection::estimateTokens($section->markdown ?? $section->body),
                ]);
            }
        });

        DB::table('items')->select(['id'])->orderBy('id')->chunk(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('items')->where('id', $item->id)->update([
                    'token_estimate' => (int) DB::table('item_sections')->where('item_id', $item->id)->sum('token_estimate'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('token_estimate');
        });

        Schema::table('item_sections', function (Blueprint $table): void {
            $table->dropColumn('token_estimate');
        });
    }
};
