<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Git / local review materialisation metadata for accepted proposals (#53).
 * Never stores proposal body copies beyond the existing `body` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->string('materialisation_status')->nullable()->after('body');
            $table->string('remote_branch')->nullable()->after('materialisation_status');
            $table->string('remote_pr_url')->nullable()->after('remote_branch');
            $table->string('materialised_commit')->nullable()->after('remote_pr_url');
            $table->timestamp('materialised_at')->nullable()->after('materialised_commit');
            $table->text('materialisation_error')->nullable()->after('materialised_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn([
                'materialisation_status',
                'remote_branch',
                'remote_pr_url',
                'materialised_commit',
                'materialised_at',
                'materialisation_error',
            ]);
        });
    }
};
