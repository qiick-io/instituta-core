<?php

declare(strict_types=1);

namespace App\Policies;

use App\Brain\Enums\RoleEnum;
use App\Models\Source;
use App\Models\User;

/**
 * Managing sources (#201) is for owners of the source's organisation: they decide what the
 * brain reads. Reading the Sources page stays open to everyone in the perimeter.
 */
class SourcePolicy
{
    public function update(User $user, Source $source): bool
    {
        return $this->isOrganisationOwner($user, $source->organisation_id);
    }

    public function delete(User $user, Source $source): bool
    {
        return $this->isOrganisationOwner($user, $source->organisation_id);
    }

    public function sync(User $user, Source $source): bool
    {
        return $this->isOrganisationOwner($user, $source->organisation_id);
    }

    private function isOrganisationOwner(User $user, string $organisationId): bool
    {
        return $user->principals()
            ->active()
            ->where('organisation_id', $organisationId)
            ->whereHas('grants', fn ($grants) => $grants->active()->where('role', RoleEnum::Owner->value))
            ->exists();
    }
}
