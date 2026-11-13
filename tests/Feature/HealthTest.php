<?php

declare(strict_types=1);

use App\Services\Health\ReadinessChecksService;

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

it('returns 503 when a readiness check fails', function (): void {
    $this->mock(ReadinessChecksService::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn([
            'database' => 'fail',
        ]);
    });

    $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.database', 'fail');
});
