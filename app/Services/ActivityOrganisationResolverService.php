<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\ContextFreeze;
use App\Models\Grant;
use App\Models\OffboardingRequest;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the organisation id that should stamp an activity row.
 */
final class ActivityOrganisationResolverService
{
    /**
     * Prefer an explicit property, then the subject, then the authenticated user's principal.
     *
     * @param  array<string, mixed>  $properties
     */
    public function resolve(?Model $subject, array $properties = []): ?string
    {
        $fromProperties = $properties['organisation_id'] ?? null;

        if (is_string($fromProperties) && $fromProperties !== '') {
            return $fromProperties;
        }

        $fromSubject = $this->fromSubject($subject);

        if ($fromSubject !== null) {
            return $fromSubject;
        }

        return $this->fromAuthenticatedUser();
    }

    private function fromSubject(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        if ($subject instanceof Principal || $subject instanceof Source || $subject instanceof ContextFreeze) {
            return $subject->organisation_id;
        }

        if ($subject instanceof ApiKey) {
            $subject->loadMissing('principal');

            return $subject->principal?->organisation_id;
        }

        if ($subject instanceof Grant) {
            $subject->loadMissing('principal');

            return $subject->principal?->organisation_id;
        }

        if ($subject instanceof OffboardingRequest) {
            $subject->loadMissing('company');

            return $subject->company?->organisation_id;
        }

        if (isset($subject->organisation_id) && is_string($subject->organisation_id) && $subject->organisation_id !== '') {
            return $subject->organisation_id;
        }

        return null;
    }

    private function fromAuthenticatedUser(): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->principals()
            ->active()
            ->where('type', 'person')
            ->orderBy('id')
            ->value('organisation_id');
    }
}
