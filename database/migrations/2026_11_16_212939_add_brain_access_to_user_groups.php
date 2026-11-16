<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brain access for user groups (#258): a group holds access to an organisation or a client with
 * a role, and every member gets the matching grant, marked with the group so it can be withdrawn
 * without touching grants a person holds on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_group_brain_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('scope_type', 16);
            $table->ulid('scope_id');
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['user_group_id', 'scope_type', 'scope_id']);
        });

        Schema::table('grants', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->nullable()->after('principal_id')->constrained('user_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_group_id');
        });

        Schema::dropIfExists('user_group_brain_access');
    }
};
