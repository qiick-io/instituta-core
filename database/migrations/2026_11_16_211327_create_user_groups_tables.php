<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User groups (#258): named sets of users that carry Spatie roles, so members inherit the
 * roles' permissions on top of their direct roles. Groups are soft-deleted; a trashed group
 * grants nothing. Brain access and grant linkage are folded in before the first stable release.
 *
 * Schema copied from externa-core; organisation_id / is_instance and the XOR check are
 * Instituta's team hardening (instance groups have no organisation; organisation groups are
 * never instance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            // Null + is_instance=true: instance-wide groups (super-admin). Org groups always have an organisation_id.
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->boolean('is_instance')->default(false);
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organisation_id', 'is_instance']);
        });

        $this->enforceInstanceOrganisationXor();

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_group_id', 'user_id']);
        });

        Schema::create('user_group_role', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id')->index();
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
            $table->foreignUuid('organisation_id')->index()->constrained('organisations')->cascadeOnDelete();
            $table->string('scope_type', 16);
            $table->uuid('scope_id');
            $table->string('role', 16);
            $table->string('max_visibility', 16)->nullable();
            $table->json('areas')->nullable();
            $table->json('path_prefixes')->nullable();
            $table->timestamps();

            $table->unique(['user_group_id', 'scope_type', 'scope_id'], 'user_group_brain_access_user_group_scope_unique');
        });

        Schema::table('grants', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->nullable()->after('principal_id')->index()->constrained('user_groups')->cascadeOnDelete();
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

    /**
     * Instance groups: is_instance=true and organisation_id NULL.
     * Organisation groups: is_instance=false and organisation_id NOT NULL.
     *
     * SQLite cannot ADD CONSTRAINT after CREATE, so the empty table is rebuilt with CHECK.
     * MySQL / MariaDB / PostgreSQL use ALTER TABLE … ADD CONSTRAINT.
     */
    private function enforceInstanceOrganisationXor(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $check = match ($driver) {
            'pgsql' => '(is_instance = TRUE AND organisation_id IS NULL) OR (is_instance = FALSE AND organisation_id IS NOT NULL)',
            default => '(is_instance = 1 AND organisation_id IS NULL) OR (is_instance = 0 AND organisation_id IS NOT NULL)',
        };

        if ($driver === 'sqlite') {
            // Empty table: drop and recreate with CHECK (SQLite cannot ADD CONSTRAINT).
            Schema::drop('user_groups');

            DB::statement(<<<SQL
                CREATE TABLE user_groups (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    organisation_id varchar null,
                    is_instance integer not null default 0,
                    name varchar not null,
                    description text null,
                    created_at datetime null,
                    updated_at datetime null,
                    deleted_at datetime null,
                    CHECK ({$check}),
                    FOREIGN KEY(organisation_id) REFERENCES organisations(id) ON DELETE CASCADE
                )
                SQL);

            DB::statement('CREATE UNIQUE INDEX user_groups_name_unique ON user_groups (name)');
            DB::statement('CREATE INDEX user_groups_organisation_id_is_instance_index ON user_groups (organisation_id, is_instance)');

            return;
        }

        DB::statement(
            "ALTER TABLE user_groups ADD CONSTRAINT user_groups_instance_organisation_xor_check CHECK ({$check})",
        );
    }
};
