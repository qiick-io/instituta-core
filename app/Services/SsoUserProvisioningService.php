<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\CreateUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Support\Str;
use Qiick\Oidc\Contracts\UserProvisioningContract;
use Qiick\Oidc\Data\AuthenticatedIdentityData;

/**
 * Creates the local account on a just-in-time SSO login. The package default fills a single
 * `name` column; Instituta has first and last name, and gives new accounts the `reader` role,
 * the least privileged one. Brain access is granted separately, as for any new user.
 *
 * The password is random and never shown, so the account signs in through SSO; the email is
 * verified because the package only provisions from an IdP-verified email.
 */
final readonly class SsoUserProvisioningService implements UserProvisioningContract
{
    public function __construct(private CreateUserAction $createUser) {}

    /**
     * @param  string  $email  the verified, lower-cased email
     */
    public function provision(AuthenticatedIdentityData $identity, string $email): User
    {
        $personName = $identity->personName();

        return $this->createUser->execute(
            firstName: $personName === null ? Str::before($email, '@') : $personName->givenName,
            lastName: $personName !== null && $personName->familyName !== '' ? $personName->familyName : null,
            email: $email,
            password: Str::random(64),
            roles: [RoleEnum::Reader->value],
        );
    }
}
