<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Known activity event names exposed as UI filters.
 */
final class FilterableActivityEventsService
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'created',
            'updated',
            'deleted',
            'login',
            'logout',
            'failed',
            'api_key.created',
            'api_key.revoked',
            'principal.deactivated',
            'principal.expired',
            'source.added',
            'source.synced',
            'source.sync_failed',
            'source.scanner_skip',
            'freeze.created',
            'freeze.lifted',
            'export.completed',
            'client.created',
            'client.updated',
            'product.created',
            'product.updated',
            'offboarding.exported',
            'proposal.opened',
            'proposal.accepted',
            'proposal.rejected',
            'settings.updated',
            'two_factor.enabled',
            'two_factor.disabled',
        ];
    }

    public static function isValid(string $event): bool
    {
        return $event !== '' && in_array($event, self::all(), true);
    }
}
