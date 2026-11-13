<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use App\Notifications\SyncFinishedNotification;
use App\Services\NotifyOrganisationUsersService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

test('guests cannot access notification routes', function () {
    $this->getJson(route('notifications.index'))->assertUnauthorized();
    $this->getJson(route('notifications.unread-count'))->assertUnauthorized();
    $this->postJson(route('notifications.read'))->assertUnauthorized();
    $this->postJson(route('notifications.unread'), ['ids' => [(string) Str::uuid()]])
        ->assertUnauthorized();
});

test('users can list only their notifications and mark them read', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    $owner->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 3));
    $other->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 1));

    $this->actingAs($owner);

    $this->getJson(route('notifications.unread-count'))
        ->assertOk()
        ->assertJsonPath('count', 1);

    $listResponse = $this->getJson(route('notifications.index'))
        ->assertOk();

    expect($listResponse->json('data'))->toHaveCount(1);
    expect($listResponse->json('data.0.data.type'))->toBe('sync_finished');
    expect($listResponse->json('total'))->toBe(1);

    $notificationId = $listResponse->json('data.0.id');

    $this->postJson(route('notifications.read'), [
        'ids' => [$notificationId],
    ])
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($owner->fresh()->unreadNotifications()->count())->toBe(0);
    expect($other->fresh()->unreadNotifications()->count())->toBe(1);
});

test('users can mark notifications unread by ids without touching others', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    $owner->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 1));
    $other->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 2));

    $this->actingAs($owner);

    $ownerNotificationId = $owner->notifications()->firstOrFail()->id;
    $otherNotificationId = $other->notifications()->firstOrFail()->id;

    $this->postJson(route('notifications.read'), [
        'ids' => [$ownerNotificationId],
    ])
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    $this->postJson(route('notifications.unread'), [
        'ids' => [$ownerNotificationId, $otherNotificationId],
    ])
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    expect($owner->fresh()->unreadNotifications()->count())->toBe(1);
    expect($owner->fresh()->notifications()->firstOrFail()->read_at)->toBeNull();
    expect($other->fresh()->unreadNotifications()->count())->toBe(1);
});

test('mark unread requires a non-empty ids list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('notifications.unread'), [])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson(route('notifications.unread'), ['ids' => []])
        ->assertUnprocessable();
});

test('users can mark all notifications read', function () {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    $user->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 1));
    $user->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 2));

    $this->actingAs($user);

    $this->postJson(route('notifications.read'), [
        'all' => true,
    ])
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('inertia shares unread notification count and sound preference', function () {
    $user = User::factory()->create([
        'notification_sound_enabled' => true,
    ]);
    $organisation = Organisation::factory()->create();
    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    $user->notify(new SyncFinishedNotification(source: $source, itemsUpserted: 1));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unread_count', 1)
            ->where('notifications.sound_enabled', true));
});

test('users can update notification sound preference', function () {
    $user = User::factory()->create([
        'notification_sound_enabled' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('notification-preferences.update'), [
            'sound_notifications_enabled' => true,
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->notification_sound_enabled)->toBeTrue();
});

test('notify organisation users reaches only principals in that organisation', function () {
    $organisation = Organisation::factory()->create();
    $otherOrganisation = Organisation::factory()->create();

    $inOrg = User::factory()->create();
    $outOrg = User::factory()->create();

    Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $inOrg->id,
    ]);
    Principal::factory()->create([
        'organisation_id' => $otherOrganisation->id,
        'user_id' => $outOrg->id,
    ]);

    $source = Source::factory()->create(['organisation_id' => $organisation->id]);

    app(NotifyOrganisationUsersService::class)->notify(
        $organisation->id,
        new SyncFinishedNotification(source: $source, itemsUpserted: 4),
    );

    expect($inOrg->fresh()->notifications()->count())->toBe(1);
    expect($outOrg->fresh()->notifications()->count())->toBe(0);
});
