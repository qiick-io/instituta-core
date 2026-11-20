<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\Principal;
use Illuminate\Support\Str;

/**
 * Fleet decision 2026-11-19/20: tenancy and index PKs are UUID v7 (HasUuids), not ULID.
 */
it('creates organisations and principals with uuid v7 primary keys', function (): void {
    $organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);

    expect(Str::isUuid($organisation->id))->toBeTrue()
        ->and(Str::isUuid($principal->id))->toBeTrue()
        ->and(strlen($organisation->id))->toBe(36)
        ->and(strlen($principal->id))->toBe(36);
});
