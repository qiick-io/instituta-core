<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only schema for Instituta tenancy, ACL, index graph and ops hooks (kickoff §3.1–3.5).
 *
 * Additive beta migrations are folded here until the first stable release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('kind');
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'slug']);
            $table->index('kind');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->string('name');
            $table->boolean('inherits_from_parent')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('product_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->timestamps();

            $table->unique(['organisation_id', 'slug']);
        });

        Schema::create('product_group_product', function (Blueprint $table): void {
            $table->foreignUuid('product_group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_group_id', 'product_id']);
        });

        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Self-FK added after create: PostgreSQL rejects inline self-references on UUID PKs.
            $table->uuid('owner_principal_id')->nullable();
            $table->string('name');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'type']);
            $table->index('user_id');
            $table->index('deactivated_at');
        });

        Schema::table('principals', function (Blueprint $table): void {
            $table->foreign('owner_principal_id')
                ->references('id')
                ->on('principals')
                ->nullOnDelete();
        });

        Schema::create('grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->constrained('principals')->cascadeOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->string('role');
            $table->string('max_visibility');
            $table->json('areas')->nullable();
            $table->json('path_prefixes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('principal_id');
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('key_prefix', 32)->unique();
            $table->string('key_hash', 64);
            // Fleet: restrict (or null + revoke) — never cascade-erase keys with the principal.
            $table->foreignUuid('owner_principal_id')->constrained('principals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->string('role');
            $table->string('max_visibility');
            $table->json('areas')->nullable();
            $table->json('path_prefixes')->nullable();
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('previous_key_hash', 64)->nullable();
            $table->dateTime('previous_key_valid_until')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('revoked_at');
        });

        Schema::create('embedding_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('version');
            $table->unsignedInteger('dimensions');
            $table->timestamps();

            $table->unique(['name', 'version']);
        });

        Schema::create('sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('scope_type');
            $table->string('name');
            $table->string('provider');
            $table->string('remote_url')->nullable();
            $table->string('local_path')->nullable();
            $table->string('ref')->nullable();
            $table->unsignedSmallInteger('sync_interval_minutes')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('path')->nullable();
            $table->string('adapter');
            $table->string('default_visibility');
            $table->boolean('is_shared')->default(false);
            $table->string('last_synced_commit')->nullable();
            $table->string('sync_status')->default('idle');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('last_sync_report')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'company_id', 'product_id']);
            $table->index('sync_status');
        });

        Schema::create('source_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('label');
            $table->string('commit');
            $table->timestamps();

            $table->unique(['source_id', 'label']);
        });

        Schema::create('source_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignUuid('source_version_id')->nullable()->constrained('source_versions')->nullOnDelete();
            $table->string('pinned_commit')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'source_id']);
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('path');
            $table->string('stable_key');
            $table->string('type');
            $table->string('area');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('visibility');
            $table->string('status', 16)->default('active')->index();
            $table->string('superseded_by')->nullable();
            $table->string('commit')->nullable();
            $table->string('content_hash');
            $table->unsignedInteger('token_estimate')->default(0);
            $table->json('frontmatter')->nullable();
            $table->date('decision_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->string('resolution_key')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'path']);
            $table->index(['organisation_id', 'company_id', 'product_id']);
            $table->index(['stable_key', 'resolution_key']);
            $table->index('visibility');
        });

        Schema::create('item_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('anchor');
            $table->string('heading');
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('position');
            $table->longText('body');
            $table->text('markdown')->nullable();
            $table->unsignedInteger('token_estimate')->default(0);
            $table->json('blocks')->nullable();
            $table->foreignUuid('embedding_model_id')->nullable()->constrained('embedding_models')->nullOnDelete();
            $table->timestamp('embedded_at')->nullable();
            // ponytail: JSON scan is O(n) per query; upgrade path = pgvector + ANN when volume needs it.
            $table->json('embedding_vector')->nullable();
            $table->string('embedding_content_hash')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'anchor']);
            $table->index(['organisation_id', 'company_id', 'product_id']);
        });

        $this->createSearchIndexes();

        Schema::create('structured_tables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('table_key');
            $table->json('columns');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'table_key']);
            $table->index(['organisation_id', 'company_id', 'product_id']);
        });

        Schema::create('structured_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('structured_table_id')->constrained('structured_tables')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('values');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organisation_id', 'company_id', 'product_id']);
            $table->index(['structured_table_id', 'position']);
        });

        Schema::create('item_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('to_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('target');
            $table->string('relation');
            $table->timestamps();

            $table->index(['from_item_id', 'relation']);
            $table->index(['organisation_id', 'company_id', 'product_id']);
        });

        Schema::create('vault_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('label');
            $table->json('source_commits');
            $table->string('signature')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'label']);
        });

        Schema::create('context_freezes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('vault_version_id')->constrained('vault_versions')->restrictOnDelete();
            $table->text('reason');
            $table->foreignUuid('frozen_by_principal_id')->constrained('principals')->restrictOnDelete();
            $table->timestamp('frozen_at');
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'company_id', 'product_id']);
            $table->index('lifted_at');
        });

        Schema::create('agent_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->foreignUuid('vault_version_id')->constrained('vault_versions')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('invalidated_at');
        });

        Schema::create('contract_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('principal_id')->nullable()->constrained('principals')->nullOnDelete();
            $table->foreignUuid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignUuid('agent_session_id')->nullable()->constrained('agent_sessions')->nullOnDelete();
            $table->string('tool_or_endpoint');
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('item_ids')->nullable();
            $table->unsignedInteger('token_estimate')->nullable();
            $table->timestamp('called_at');
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('called_at');
        });

        /*
         * The unique key spans three strings and a UUID. At string(255) they cost 3 × 1020 + 144
         * bytes under utf8mb4, past InnoDB's 3072-byte limit (#356); at 64 characters the key is
         * 3 × 256 + 144 = 872 bytes. The columns hold a ScopeTypeEnum value, a QuotaMetricEnum
         * value and a period name, all short identifiers.
         */
        Schema::create('quotas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type', 64);
            $table->uuid('scope_id');
            $table->string('metric', 64);
            $table->unsignedBigInteger('limit_value');
            $table->string('period', 64);
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'metric', 'period']);
        });

        Schema::create('quota_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('quota_id')->constrained('quotas')->cascadeOnDelete();
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();

            $table->unique(['quota_id', 'period_start']);
        });

        Schema::create('offboarding_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('status');
            $table->string('export_path')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('erased_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('connected_systems', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('system');
            $table->string('base_url');
            $table->timestamps();

            $table->unique(['product_id', 'system']);
        });
    }

    public function down(): void
    {
        $this->dropSearchIndexes();

        Schema::dropIfExists('connected_systems');
        Schema::dropIfExists('offboarding_requests');
        Schema::dropIfExists('quota_usages');
        Schema::dropIfExists('quotas');
        Schema::dropIfExists('contract_calls');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('context_freezes');
        Schema::dropIfExists('vault_versions');
        Schema::dropIfExists('item_links');
        Schema::dropIfExists('structured_rows');
        Schema::dropIfExists('structured_tables');
        Schema::dropIfExists('item_sections');
        Schema::dropIfExists('items');
        Schema::dropIfExists('source_references');
        Schema::dropIfExists('source_versions');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('embedding_models');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('grants');
        Schema::dropIfExists('principals');
        Schema::dropIfExists('product_group_product');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('organisations');
    }

    /**
     * Full-text search helpers for item_sections (Postgres tsvector with language-neutral
     * `simple` config; SQLite FTS5). MySQL has no search index in v0.1.0.
     */
    private function createSearchIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE item_sections ADD COLUMN IF NOT EXISTS search_vector tsvector');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION instituta_item_sections_search_vector()
                RETURNS trigger AS $$
                BEGIN
                    NEW.search_vector := to_tsvector(
                        'simple',
                        coalesce(NEW.heading, '') || ' ' || coalesce(NEW.body, '')
                    );
                    RETURN NEW;
                END
                $$ LANGUAGE plpgsql
                SQL);
            DB::statement('DROP TRIGGER IF EXISTS item_sections_search_vector_trigger ON item_sections');
            DB::statement(<<<'SQL'
                CREATE TRIGGER item_sections_search_vector_trigger
                BEFORE INSERT OR UPDATE OF heading, body ON item_sections
                FOR EACH ROW
                EXECUTE FUNCTION instituta_item_sections_search_vector()
                SQL);
            DB::statement('CREATE INDEX IF NOT EXISTS item_sections_search_vector_gin ON item_sections USING GIN (search_vector)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS item_sections_fts');
            DB::statement(<<<'SQL'
                CREATE VIRTUAL TABLE item_sections_fts USING fts5(
                    section_id UNINDEXED,
                    item_id UNINDEXED,
                    organisation_id UNINDEXED,
                    company_id UNINDEXED,
                    product_id UNINDEXED,
                    title,
                    summary,
                    heading,
                    body
                )
                SQL);
        }
    }

    private function dropSearchIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS item_sections_search_vector_trigger ON item_sections');
            DB::statement('DROP FUNCTION IF EXISTS instituta_item_sections_search_vector()');
            DB::statement('DROP INDEX IF EXISTS item_sections_search_vector_gin');
            DB::statement('ALTER TABLE item_sections DROP COLUMN IF EXISTS search_vector');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS item_sections_fts');
        }
    }
};
