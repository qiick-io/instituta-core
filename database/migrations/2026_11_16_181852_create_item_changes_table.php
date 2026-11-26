<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each sync changed, per item (#228), so agents that keep a memory of the brain read only
 * what changed since they last looked. Removed items keep a row (a tombstone) after the item
 * itself is gone, so `item_id` has no foreign key. Company and product rows cascade, never null
 * out, so an erased client's history cannot surface at organisation level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->index()->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('source_id')->index()->constrained('sources')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->string('kind', 16);
            $table->string('stable_key');
            $table->string('path');
            $table->string('title');
            $table->string('area');
            $table->string('visibility');
            $table->string('status', 16);
            $table->string('superseded_by')->nullable();
            $table->string('commit')->nullable();
            $table->json('changed_sections')->nullable();
            $table->timestamp('changed_at')->index();

            $table->index(['organisation_id', 'company_id', 'product_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_changes');
    }
};
