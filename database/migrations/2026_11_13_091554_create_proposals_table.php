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
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('scope_type');
            $table->ulid('scope_id');
            $table->foreignUlid('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('status');
            $table->text('decision_note')->nullable();
            $table->string('action');
            $table->string('stable_key');
            $table->string('title');
            $table->string('path');
            $table->string('area')->nullable();
            $table->foreignUlid('proposed_by_principal_id')->constrained('principals')->cascadeOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
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
