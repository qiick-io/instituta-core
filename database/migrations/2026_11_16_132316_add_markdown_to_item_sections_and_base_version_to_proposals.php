<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section source markdown, so writers can edit a section without losing formatting, and the
 * version a proposal was written against, so materialising never overwrites newer content (#198).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_sections', function (Blueprint $table): void {
            $table->text('markdown')->nullable()->after('body');
        });

        Schema::table('proposals', function (Blueprint $table): void {
            $table->string('base_content_hash', 64)->nullable()->after('body');
            $table->string('base_commit')->nullable()->after('base_content_hash');
            $table->string('section_anchor')->nullable()->after('base_commit');
            $table->string('base_section_hash', 64)->nullable()->after('section_anchor');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn(['base_content_hash', 'base_commit', 'section_anchor', 'base_section_hash']);
        });

        Schema::table('item_sections', function (Blueprint $table): void {
            $table->dropColumn('markdown');
        });
    }
};
