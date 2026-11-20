<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User groups (#258): named sets of users that carry Spatie roles, so members inherit the
 * roles' permissions on top of their direct roles. Groups are soft-deleted; a trashed group
 * grants nothing. Brain access and grant linkage are folded in before the first stable release.
 *
 * Schema copied from externa-core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
        });

        Schema::create('user_group_role', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(['user_group_id', 'role_id']);

            $table->foreign('role_id')
                ->references('id')
                ->on(config('permission.table_names.roles', 'roles'))
                ->cascadeOnDelete();
        });

        Schema::create('user_group_brain_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignUlid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('scope_type', 16);
            $table->ulid('scope_id');
            $table->string('role', 16);
            $table->string('max_visibility', 16)->nullable();
            $table->json('areas')->nullable();
            $table->json('path_prefixes')->nullable();
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
        Schema::dropIfExists('user_group_role');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};
