<?php

declare(strict_types=1);

it('answers liveness without auth', function (): void {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('answers readiness when the database is up', function (): void {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok');
});
