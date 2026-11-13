<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use App\Notifications\SyncFinishedNotification;
use Illuminate\Support\Facades\DB;

test('instituta notify-expiring-keys notifies organisation users once per key', function () {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
    ]);

    ApiKey::factory()->create([
        'principal_id' => $principal->id,
        'expires_at' => now()->addDays(3),
        'revoked_at' => null,
    ]);

    $this->artisan('instituta:notify-expiring-keys', ['--days' => 7])
        ->assertSuccessful();

    expect($user->fresh()->notifications()->count())->toBe(1);
    expect($user->fresh()->notifications()->first()->type)
        ->toBe(ApiKeyExpiringNotification::class);

    $this->artisan('instituta:notify-expiring-keys', ['--days' => 7])
        ->assertSuccessful();

    expect($user->fresh()->notifications()->count())->toBe(1);
});

test('instituta prune-notifications deletes old rows', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    $user->notify(new SyncFinishedNotification(
        source: $source,
        itemsUpserted: 1,
    ));

    DB::table('notifications')
        ->update(['created_at' => now()->subDays(40)]);

    $this->artisan('instituta:prune-notifications', ['--days' => 30])
        ->assertSuccessful();

    expect($user->fresh()->notifications()->count())->toBe(0);
});
