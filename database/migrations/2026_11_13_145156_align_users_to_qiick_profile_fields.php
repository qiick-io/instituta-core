<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align users with externa-core / excubia-app profile columns (first_name / last_name).
 *
 * Pre-release: new forward migration only; existing `name` values are split on the first space.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->default('User')->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('locale')->default('en')->after('password');
            $table->string('timezone')->default('UTC')->after('locale');
            $table->boolean('is_active')->default(true)->after('timezone');
            $table->dateTime('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip')->nullable()->after('last_login_at');
        });

        $users = DB::table('users')->select(['id', 'name'])->get();

        foreach ($users as $user) {
            $fullName = trim((string) $user->name);
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            $firstName = ($parts[0] ?? '') !== '' ? $parts[0] : 'User';
            $lastName = $parts[1] ?? null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $firstName,
                'last_name' => $lastName === '' ? null : $lastName,
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->default('')->after('id');
        });

        $users = DB::table('users')->select(['id', 'first_name', 'last_name'])->get();

        foreach ($users as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim((string) $user->first_name.' '.(string) $user->last_name),
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name',
                'last_name',
                'locale',
                'timezone',
                'is_active',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
