<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaps and contradictions agents report in the brain (#229). A report changes nothing in the
 * brain; owners turn it into a proposal or dismiss it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gap_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('scope_type', 16);
            $table->ulid('scope_id');
            $table->string('kind', 16);
            $table->string('status', 16)->default('open')->index();
            $table->text('summary');
            $table->text('question')->nullable();
            $table->json('related')->nullable();
            $table->foreignUlid('reported_by_principal_id')->constrained('principals')->cascadeOnDelete();
            $table->foreignUlid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('proposal_id')->nullable()->constrained('proposals')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gap_reports');
    }
};
