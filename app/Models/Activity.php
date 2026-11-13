<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Spatie activity row with an organisation perimeter for tenant isolation.
 *
 * @property string|null $organisation_id
 */
class Activity extends SpatieActivity
{
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
