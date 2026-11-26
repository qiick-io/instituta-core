<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brain write proposals (#54). Bodies stay here for later git PR materialisation (#53);
 * they must never appear in contract_calls or the activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->index()->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->index()->constrained('products')->nullOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->foreignUuid('source_id')->nullable()->index()->constrained('sources')->nullOnDelete();
            $table->string('status');
            $table->text('decision_note')->nullable();
            $table->string('action');
            $table->string('stable_key');
            $table->string('title');
            $table->string('path');
            $table->string('area')->nullable();
            $table->foreignUuid('proposed_by_principal_id')->index()->constrained('principals')->cascadeOnDelete();
            $table->foreignUuid('api_key_id')->nullable()->index()->constrained('api_keys')->nullOnDelete();
            $table->longText('body')->nullable();
            $table->json('frontmatter_changes')->nullable();
            $table->string('base_content_hash', 64)->nullable();
            $table->string('base_commit')->nullable();
            $table->string('section_anchor')->nullable();
            $table->string('base_section_hash', 64)->nullable();
            $table->string('materialisation_status')->nullable();
            $table->string('remote_branch')->nullable();
            $table->string('remote_pr_url')->nullable();
            $table->string('materialised_commit')->nullable();
            $table->timestamp('materialised_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->text('materialisation_error')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('status');
            $table->index('stable_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
