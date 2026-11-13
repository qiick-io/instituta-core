<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
 */
#[AsCommand(name: 'instituta:create-user')]
class CreateUserCommand extends Command
{
    protected $signature = 'instituta:create-user
        {--first-name= : First name}
        {--last-name= : Last name (optional)}
        {--email= : Login email}
        {--role= : Role name (default: super-admin for the first user, reader afterwards)}';

    protected $description = 'Create a dashboard user (the password is prompted)';

    public function handle(CreateUserAction $createUser): int
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
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', 'string', Rule::in($roles)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $user = $createUser->execute(
            $validated['first_name'],
            ($validated['last_name'] ?? '') !== '' ? $validated['last_name'] : null,
            $validated['email'],
            $validated['password'],
            [$validated['role']],
        );

        $this->info("Created user {$user->email} with role {$validated['role']}.");

        return self::SUCCESS;
    }
}
