<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an entry is still valid (#218): `status` and `superseded_by` from the frontmatter,
 * filled at the next sync (existing rows default to `active`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('visibility')->index();
            $table->string('superseded_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'superseded_by']);
        });
    }
};
