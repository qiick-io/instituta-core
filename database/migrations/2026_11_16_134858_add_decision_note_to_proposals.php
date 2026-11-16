<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional note a person leaves when accepting or rejecting a proposal (#200), so the
 * author learns why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->text('decision_note')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn('decision_note');
        });
    }
};
