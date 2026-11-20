<?php

declare(strict_types=1);

use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Fleet core hardening: secrets at rest use Laravel encryption (encrypted cast or Fortify).
 */
it('stores source webhook secrets with the encrypted cast', function (): void {
    $source = Source::factory()->create(['webhook_secret' => 'plain-webhook-secret']);

    $raw = DB::table('sources')->where('id', $source->id)->value('webhook_secret');

    expect($raw)->not->toBe('plain-webhook-secret')
        ->and($source->fresh()->webhook_secret)->toBe('plain-webhook-secret');
});

it('stores fortify two-factor secrets encrypted at rest', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $rawSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    $rawCodes = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

    expect($rawSecret)->not->toBe('secret')
        ->and($rawSecret)->not->toBeNull()
        ->and(Crypt::decrypt($rawSecret))->toBe('secret')
        ->and($rawCodes)->not->toBeNull()
        ->and($rawCodes)->not->toContain('recovery-code-1');
});
