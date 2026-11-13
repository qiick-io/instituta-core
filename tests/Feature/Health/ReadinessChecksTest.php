<?php

declare(strict_types=1);

use App\Services\Health\ReadinessChecksService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('returns fail for database when the connection throws', function (): void {
    DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('db down'));

    expect(app(ReadinessChecksService::class)->database())->toBe('fail');
});

it('returns fail for redis when ping throws', function (): void {
    Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis down'));

    expect(app(ReadinessChecksService::class)->redis())->toBe('fail');
});

it('requires redis when the queue driver is redis', function (): void {
    config([
        'queue.default' => 'redis',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'broadcasting.default' => 'null',
    ]);

    expect(app(ReadinessChecksService::class)->isRedisRequired())->toBeTrue();
});

it('requires redis when the cache driver is redis', function (): void {
    config([
        'queue.default' => 'sync',
        'cache.default' => 'redis',
        'session.driver' => 'array',
        'broadcasting.default' => 'null',
    ]);

    expect(app(ReadinessChecksService::class)->isRedisRequired())->toBeTrue();
});

it('does not require redis for sync queue and array cache', function (): void {
    config([
        'queue.default' => 'sync',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'broadcasting.default' => 'null',
    ]);

    expect(app(ReadinessChecksService::class)->isRedisRequired())->toBeFalse();
});
