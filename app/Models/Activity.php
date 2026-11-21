<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Spatie activity row with an organisation perimeter for tenant isolation.
 *
 * The database key stays bigint; `public_id` is the UUID exposed by resources.
 *
 * @property string $public_id
 * @property string|null $organisation_id
 * @property string|null $causer_id
 * @property string|null $subject_id
 * @property Collection<string, mixed>|null $properties
 * @property Collection<string, mixed>|null $attribute_changes
 */
class Activity extends SpatieActivity
{
    use HasUuids;

    /**
     * Only `public_id` is generated as a UUID; the primary key stays bigint.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Restrict the query to one organisation (and rows without an org only when null is allowed).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganisation(Builder $query, string $organisationId): Builder
    {
        return $query->where('organisation_id', $organisationId);
    }
}
