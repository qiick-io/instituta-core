<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store section embedding vectors as JSON float arrays (portable across sqlite/mysql/pgsql).
 *
 * ponytail: JSON scan is O(n) per query; upgrade path = pgvector column + ANN index when volume needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_sections', function (Blueprint $table): void {
            $table->json('embedding_vector')->nullable()->after('embedded_at');
            $table->string('embedding_content_hash')->nullable()->after('embedding_vector');
        });
    }

    public function down(): void
    {
        Schema::table('item_sections', function (Blueprint $table): void {
            $table->dropColumn(['embedding_vector', 'embedding_content_hash']);
        });
    }
};
