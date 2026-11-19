<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reapply the tenancy changes that were made to a frozen migration in place.
 *
 * The freeze baseline records the original bytes of
 * `2026_11_12_220000_create_instituta_tenancy_and_index_tables.php`. Those bytes are restored in
 * this branch; this migration carries the delta so fresh installs and installs that already have
 * the current schema both end up with the same columns. Every operation is conditional because
 * an install created from `develop` already has the target schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reapplyApiKeyChanges();
        $this->shortenQuotaKeyColumns();
    }

    public function down(): void
    {
        $this->restoreFrozenApiKeyColumns();
        $this->restoreQuotaKeyColumnLengths();
    }

    /**
     * Move the API key columns from the frozen definition to the current one.
     */
    private function reapplyApiKeyChanges(): void
    {
        if (! Schema::hasTable('api_keys')) {
            return;
        }

        if (Schema::hasColumn('api_keys', 'display_prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->dropIndex(['display_prefix']);
                $table->dropColumn('display_prefix');
            });
        }

        if (Schema::hasIndex('api_keys', ['hash'], 'unique')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->dropUnique(['hash']);
            });
        }

        if (Schema::hasColumn('api_keys', 'prefix') && ! Schema::hasColumn('api_keys', 'key_prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('prefix', 'key_prefix');
            });
        }

        if (Schema::hasColumn('api_keys', 'key_prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->string('key_prefix', 32)->change();
            });

            if (! Schema::hasIndex('api_keys', ['key_prefix'], 'unique')) {
                Schema::table('api_keys', function (Blueprint $table): void {
                    $table->unique('key_prefix');
                });
            }
        }

        if (Schema::hasColumn('api_keys', 'hash') && ! Schema::hasColumn('api_keys', 'key_hash')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('hash', 'key_hash');
            });
        }

        if (Schema::hasColumn('api_keys', 'abilities') && ! Schema::hasColumn('api_keys', 'scopes')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('abilities', 'scopes');
            });
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_keys', 'created_by')) {
                $column = $table->foreignId('created_by')->nullable();

                // SQLite cannot add a foreign key to an existing table.
                if (DB::getDriverName() !== 'sqlite') {
                    $column->constrained('users')->nullOnDelete();
                }
            }

            if (! Schema::hasColumn('api_keys', 'rate_limit_per_minute')) {
                $table->unsignedInteger('rate_limit_per_minute')->nullable();
            }

            if (! Schema::hasColumn('api_keys', 'last_used_ip')) {
                $table->string('last_used_ip', 45)->nullable();
            }

            if (! Schema::hasColumn('api_keys', 'previous_key_hash')) {
                $table->string('previous_key_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('api_keys', 'previous_key_valid_until')) {
                $table->timestamp('previous_key_valid_until')->nullable();
            }
        });
    }

    /**
     * Keep the quota unique key inside InnoDB's 3072-byte limit.
     */
    private function shortenQuotaKeyColumns(): void
    {
        if (! Schema::hasTable('quotas')) {
            return;
        }

        Schema::table('quotas', function (Blueprint $table): void {
            $table->string('scope_type', 64)->change();
            $table->string('metric', 64)->change();
            $table->string('period', 64)->change();
        });
    }

    /**
     * Return the API key table to the frozen definition.
     */
    private function restoreFrozenApiKeyColumns(): void
    {
        if (! Schema::hasTable('api_keys')) {
            return;
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn([
                'created_by',
                'rate_limit_per_minute',
                'last_used_ip',
                'previous_key_hash',
                'previous_key_valid_until',
            ]);
        });

        if (Schema::hasIndex('api_keys', ['key_prefix'], 'unique')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->dropUnique(['key_prefix']);
            });
        }

        if (Schema::hasColumn('api_keys', 'key_prefix') && ! Schema::hasColumn('api_keys', 'prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('key_prefix', 'prefix');
            });
        }

        if (Schema::hasColumn('api_keys', 'prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->string('prefix')->change();
            });
        }

        if (Schema::hasColumn('api_keys', 'key_hash') && ! Schema::hasColumn('api_keys', 'hash')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('key_hash', 'hash');
            });
        }

        if (Schema::hasColumn('api_keys', 'hash')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->string('hash', 64)->unique()->change();
            });
        }

        if (Schema::hasColumn('api_keys', 'scopes') && ! Schema::hasColumn('api_keys', 'abilities')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->renameColumn('scopes', 'abilities');
            });
        }

        if (! Schema::hasColumn('api_keys', 'display_prefix')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->string('display_prefix', 12);
                $table->index('display_prefix');
            });
        }
    }

    /**
     * Return the quota key columns to the frozen definition.
     */
    private function restoreQuotaKeyColumnLengths(): void
    {
        if (! Schema::hasTable('quotas')) {
            return;
        }

        Schema::table('quotas', function (Blueprint $table): void {
            $table->string('scope_type')->change();
            $table->string('metric')->change();
            $table->string('period')->change();
        });
    }
};
