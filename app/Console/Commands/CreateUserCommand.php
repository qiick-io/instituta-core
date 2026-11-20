<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateUserAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a dashboard user from the CLI.
 *
 * This is the way in when mail is not configured: there is no public sign-up by
 * default. The email is marked as verified because the operator created the
 * account on the server. The first user becomes super-admin by default, later
 * users reader. Copied from excubia-app `CreateUserCommand`.
 *
 * Instituta addition: the user also gets a perimeter in an organisation (person
 * principal + organisation grant), otherwise every brain screen is empty for them.
 * With one organisation it is picked automatically; the first person of an
 * organisation becomes its brain owner, later ones members.
 */
#[AsCommand(name: 'instituta:create-user')]
class CreateUserCommand extends Command
{
    protected $signature = 'instituta:create-user
        {--first-name= : First name}
        {--last-name= : Last name (optional)}
        {--email= : Login email}
        {--role= : Role name (default: super-admin for the first user, reader afterwards)}
        {--organisation= : Organisation UUID or slug the user gets access to (asked when there are several)}
        {--brain-role= : Brain role in that organisation: owner, member or guest (default: owner for its first person, member afterwards)}';

    protected $description = 'Create a dashboard user (the password is prompted)';

    public function handle(CreateUserAction $createUser, InitialSuperAdminPasswordService $passwordValidation): int
    {
        // Built-in roles/permissions must exist even when seed was never run.
        $this->callSilently('instituta:sync-permissions');
        $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $defaultRole = User::query()->exists() ? RoleEnum::Reader->value : RoleEnum::SuperAdmin->value;
        $roles = Role::query()->orderBy('name')->pluck('name')->all();

        $input = [
            'first_name' => $this->option('first-name') ?? text('First name', required: true),
            'last_name' => $this->option('last-name') ?? text('Last name'),
            'email' => Str::lower((string) ($this->option('email') ?? text('Email', required: true))),
            'password' => password('Password', required: true),
            'role' => $this->option('role') ?? select('Role', $roles, default: $defaultRole),
        ];

        $validator = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $passwordValidation->rules(app()->environment()),
            'role' => ['required', 'string', Rule::in($roles)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $organisation = $this->resolveOrganisation();

        if ($organisation === false) {
            return self::FAILURE;
        }

        $brainRole = BrainRoleEnum::Member;

        if ($organisation instanceof Organisation) {
            $resolvedBrainRole = $this->resolveBrainRole($organisation);

            if ($resolvedBrainRole === null) {
                return self::FAILURE;
            }

            $brainRole = $resolvedBrainRole;
        }

        $user = $createUser->execute(
            $validated['first_name'],
            ($validated['last_name'] ?? '') !== '' ? $validated['last_name'] : null,
            $validated['email'],
            $validated['password'],
            [$validated['role']],
            organisation: $organisation,
            brainRole: $brainRole,
        );

        $this->info("Created user {$user->email} with role {$validated['role']}.");

        if ($organisation instanceof Organisation) {
            $this->info("Granted {$brainRole->value} access to organisation {$organisation->name} ({$organisation->slug}).");
        } else {
            $this->warn('No organisation exists yet: the user has no brain perimeter. Run instituta:install to create one.');
        }

        return self::SUCCESS;
    }

    /**
     * The organisation from `--organisation` (UUID or slug), the only one, or a prompt.
     * Null when none exists yet; false (after printing the error) when the option matches none.
     */
    private function resolveOrganisation(): Organisation|false|null
    {
        $option = $this->option('organisation');

        if (is_string($option) && $option !== '') {
            $organisation = Organisation::query()
                ->where('id', $option)
                ->orWhere('slug', $option)
                ->first();

            if (! $organisation instanceof Organisation) {
                $this->error("Organisation {$option} not found.");

                return false;
            }

            return $organisation;
        }

        $organisations = Organisation::query()->orderBy('name')->get();

        if ($organisations->count() <= 1) {
            return $organisations->first();
        }

        $selectedId = select(
            'Organisation',
            $organisations->mapWithKeys(fn (Organisation $organisation): array => [$organisation->id => $organisation->name])->all(),
        );

        return $organisations->firstWhere('id', $selectedId);
    }

    /**
     * The brain role from `--brain-role`, otherwise owner for the organisation's first person
     * and member afterwards. Null (after printing the error) when the option is not a role.
     */
    private function resolveBrainRole(Organisation $organisation): ?BrainRoleEnum
    {
        $option = $this->option('brain-role');

        if (is_string($option) && $option !== '') {
            $brainRole = BrainRoleEnum::tryFrom(strtolower($option));

            if (! $brainRole instanceof BrainRoleEnum) {
                $this->error('The brain role must be one of: owner, member, guest.');
            }

            return $brainRole;
        }

        $hasPerson = Principal::query()
            ->where('organisation_id', $organisation->id)
            ->where('type', PrincipalTypeEnum::Person)
            ->exists();

        return $hasPerson ? BrainRoleEnum::Member : BrainRoleEnum::Owner;
    }
}
