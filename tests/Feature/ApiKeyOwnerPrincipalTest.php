<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Principal;
use Illuminate\Support\Facades\Schema;

it('owns api keys through owner_principal_id with future-dated datetime columns', function (): void {
    expect(Schema::hasColumns('api_keys', [
        'owner_principal_id',
        'expires_at',
        'previous_key_valid_until',
        'created_by',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'principal_id'))->toBeFalse();

    $principal = Principal::factory()->create();
    $key = ApiKey::factory()->create(['owner_principal_id' => $principal->id]);

    expect($key->owner_principal_id)->toBe($principal->id)
        ->and($key->principal->is($principal))->toBeTrue();
});
