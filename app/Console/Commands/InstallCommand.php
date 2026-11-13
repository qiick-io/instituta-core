<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Console\Concerns\ManagesDotEnvTrait;
use App\Enums\CompanyKindEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\ApiKeyIssuerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * First-run installer for a fresh Instituta tree (CI and operators).
 */
#[AsCommand(name: 'instituta:install')]
class InstallCommand extends Command
{
    use ManagesDotEnvTrait;

    protected $signature = 'instituta:install
        {--force : Allow overwriting an existing .env / running when APP_ENV looks like production}
        {--no-npm : Skip npm install / build}
        {--no-owner : Skip owner bootstrap (org, company, user, principal, key)}
        {--org-name=Instituta : Organisation display name}
        {--org-slug= : Organisation slug (default: slug of --org-name)}
        {--company-name=Own : Own company display name}
        {--owner-name=Owner : Owner user display name}
        {--owner-email=owner@instituta.local : Owner user e-mail}
        {--owner-password= : Owner password (random if omitted; printed once)}';

    protected $description = 'First-run setup: write .env, migrate, bootstrap owner, optional npm build';

    public function handle(ApiKeyIssuerService $keys): int
    {
        $force = (bool) $this->option('force');

        if ($this->looksLikeProduction() && ! $force) {
            $this->error('Refusing: APP_ENV looks like production. Pass --force if you really mean it.');

            return self::FAILURE;
        }

        if (! File::exists(base_path('.env.example'))) {
            $this->error('.env.example missing.');

            return self::FAILURE;
        }

        $envPath = base_path('.env');

        if (! File::exists($envPath) || $force) {
            File::copy(base_path('.env.example'), $envPath);
            $this->info('Wrote .env from .env.example');
        }

        $sqlite = database_path('database.sqlite');

        if (! File::exists($sqlite)) {
            File::put($sqlite, '');
            $this->line('Created database/database.sqlite');
        }

        if ($this->call('key:generate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->call('storage:link');

        if (! $this->option('no-owner')) {
            $boot = $this->bootstrapOwner($keys);
            if ($boot !== self::SUCCESS) {
                return $boot;
            }
        }

        if (! $this->option('no-npm')) {
            $this->info('npm ci…');
            $install = Process::timeout(600)->run(['npm', 'ci']);

            if ($install->failed()) {
                $this->error($install->errorOutput());

                return self::FAILURE;
            }

            $this->info('npm run build…');
            $build = Process::timeout(600)->run(['npm', 'run', 'build']);

            if ($build->failed()) {
                $this->error($build->errorOutput());

                return self::FAILURE;
            }
        }

        $this->info('Instituta install complete.');

        return self::SUCCESS;
    }

    private function bootstrapOwner(ApiKeyIssuerService $keys): int
    {
        if (Organisation::query()->exists()) {
            $this->warn('Organisation already exists; skipping owner bootstrap.');

            return self::SUCCESS;
        }

        $orgName = (string) $this->option('org-name');
        $orgSlug = (string) ($this->option('org-slug') ?: Str::slug($orgName));
        $companyName = (string) $this->option('company-name');
        $ownerName = (string) $this->option('owner-name');
        $ownerEmail = (string) $this->option('owner-email');
        $passwordOption = $this->option('owner-password');
        $plainPassword = is_string($passwordOption) && $passwordOption !== ''
            ? $passwordOption
            : Str::password(20);

        /** @var array{org: Organisation, company: Company, user: User, principal: Principal, plain: string} $created */
        $created = DB::transaction(function () use (
            $keys,
            $orgName,
            $orgSlug,
            $companyName,
            $ownerName,
            $ownerEmail,
            $plainPassword,
        ): array {
            $org = Organisation::query()->create([
                'slug' => $orgSlug,
                'name' => $orgName,
            ]);

            $company = Company::query()->create([
                'organisation_id' => $org->id,
                'slug' => Str::slug($companyName).'-own',
                'name' => $companyName,
                'kind' => CompanyKindEnum::Own,
            ]);

            $user = User::query()->create([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => Hash::make($plainPassword),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $principal = Principal::query()->create([
                'organisation_id' => $org->id,
                'type' => PrincipalTypeEnum::Person,
                'user_id' => $user->id,
                'name' => $ownerName,
            ]);

            Grant::query()->create([
                'principal_id' => $principal->id,
                'scope_type' => ScopeTypeEnum::Organisation,
                'scope_id' => $org->id,
                'role' => RoleEnum::Owner,
                'max_visibility' => VisibilityEnum::Restricted,
            ]);

            $issued = $keys->create(
                principal: $principal,
                name: 'owner',
                scopeType: ScopeTypeEnum::Organisation,
                scopeId: $org->id,
                role: RoleEnum::Owner,
                maxVisibility: VisibilityEnum::Restricted,
            );

            return [
                'org' => $org,
                'company' => $company,
                'user' => $user,
                'principal' => $principal,
                'plain' => $issued['plain'],
            ];
        });

        $this->info('Owner bootstrap complete. Store these secrets; they will not be shown again.');
        $this->line('organisation: '.$created['org']->id.' ('.$created['org']->slug.')');
        $this->line('company: '.$created['company']->id);
        $this->line('user: '.$created['user']->email);
        $this->line('password: '.$plainPassword);
        $this->line('principal: '.$created['principal']->id);
        $this->line('api_key: '.$created['plain']);

        return self::SUCCESS;
    }
}
