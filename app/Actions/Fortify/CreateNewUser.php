<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Features;

/**
 * Fortify registration action: create a user from first_name / last_name / email / password.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['username'] = self::normalizeUsername($input['username'] ?? null);

        if (! Features::enabled(Features::registration())
            || ! app(SettingsRepositoryService::class)->isRegistrationEnabled()) {
            abort(403);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'] ?? null,
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
