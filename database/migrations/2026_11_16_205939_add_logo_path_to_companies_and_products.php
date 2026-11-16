<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional logo for clients and products (#257), shown in their avatar; initials otherwise.
 * The path is relative to the public disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('kind');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
