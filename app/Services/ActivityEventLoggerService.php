<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\OffboardingRequest;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit activity events that are not plain model attribute changes.
 *
 * Proposal helpers are ready for #53; call them when write proposals land.
 */
final class ActivityEventLoggerService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $logName,
        string $event,
        string $description,
        ?Model $subject = null,
        ?User $causer = null,
        array $properties = [],
    ): void {
        $logger = activity($logName)->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($properties !== []) {
            $logger->withProperties($properties);
        }

        $logger->log($description);
    }

    public function sourceAdded(Source $source, ?User $causer = null): void
    {
        $this->log(
            'ingest',
            'source.added',
            'source.added',
            $source,
            $causer,
            ['organisation_id' => $source->organisation_id],
        );
    }

    /**
     * @param  list<array{path?: string, reason?: string}>  $skipped
     */
    public function scannerSkips(Source $source, array $skipped): void
    {
        foreach ($skipped as $skip) {
            $this->log(
                'ingest',
                'source.scanner_skip',
                'source.scanner_skip',
                $source,
                null,
                [
                    'organisation_id' => $source->organisation_id,
                    'path' => $skip['path'] ?? null,
                    'reason' => $skip['reason'] ?? null,
                ],
            );
        }
    }

    public function freezeCreated(ContextFreeze $freeze, ?User $causer = null): void
    {
        $this->log(
            'security',
            'freeze.created',
            'freeze.created',
            $freeze,
            $causer,
            [
                'organisation_id' => $freeze->organisation_id,
                'vault_version_id' => $freeze->vault_version_id,
                'reason' => $freeze->reason,
            ],
        );
    }

    public function freezeLifted(ContextFreeze $freeze, ?User $causer = null): void
    {
        $this->log(
            'security',
            'freeze.lifted',
            'freeze.lifted',
            $freeze,
            $causer,
            ['organisation_id' => $freeze->organisation_id],
        );
    }

    public function exportCompleted(Company $company, OffboardingRequest $request, ?User $causer = null): void
    {
        $this->log(
            'tenancy',
            'export.completed',
            'export.completed',
            $request,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'company_id' => $company->id,
                'export_path' => $request->export_path,
            ],
        );
    }

    /**
     * Counts only — never item titles, bodies or secrets.
     *
     * @param  array{deleted_items?: int, revoked_keys?: int}  $counts
     */
    public function offboardingErased(
        Company $company,
        OffboardingRequest $request,
        ?User $causer = null,
        array $counts = [],
    ): void {
        $this->log(
            'tenancy',
            'offboarding.erased',
            'offboarding.erased',
            $request,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'company_id' => $company->id,
                'deleted_items' => $counts['deleted_items'] ?? 0,
                'revoked_keys' => $counts['revoked_keys'] ?? 0,
            ],
        );
    }

    public function clientCreated(Company $company, ?User $causer = null): void
    {
        $this->log(
            'tenancy',
            'client.created',
            'client.created',
            $company,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'slug' => $company->slug,
            ],
        );
    }

    /**
     * @param  list<string>  $changedKeys
     */
    public function clientUpdated(Company $company, ?User $causer = null, array $changedKeys = []): void
    {
        $this->log(
            'tenancy',
            'client.updated',
            'client.updated',
            $company,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'keys' => $changedKeys,
            ],
        );
    }

    public function productCreated(Product $product, Company $company, ?User $causer = null): void
    {
        $this->log(
            'tenancy',
            'product.created',
            'product.created',
            $product,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'company_id' => $company->id,
                'slug' => $product->slug,
            ],
        );
    }

    /**
     * @param  list<string>  $changedKeys
     */
    public function productUpdated(
        Product $product,
        Company $company,
        ?User $causer = null,
        array $changedKeys = [],
    ): void {
        $this->log(
            'tenancy',
            'product.updated',
            'product.updated',
            $product,
            $causer,
            [
                'organisation_id' => $company->organisation_id,
                'company_id' => $company->id,
                'keys' => $changedKeys,
            ],
        );
    }

    public function principalExpired(Principal $principal): void
    {
        $this->log(
            'keys',
            'principal.expired',
            'principal.expired',
            $principal,
            null,
            ['organisation_id' => $principal->organisation_id],
        );
    }

    /**
     * Settings keys only — never secret values.
     *
     * @param  list<string>  $changedKeys
     */
    public function settingsUpdated(User $user, array $changedKeys, ?string $organisationId = null): void
    {
        $this->log(
            'settings',
            'settings.updated',
            'settings.updated',
            $user,
            $user,
            [
                'organisation_id' => $organisationId,
                'keys' => $changedKeys,
            ],
        );
    }

    public function twoFactorEnabled(User $user, ?string $organisationId = null): void
    {
        $this->log(
            'auth',
            'two_factor.enabled',
            'two_factor.enabled',
            $user,
            $user,
            ['organisation_id' => $organisationId],
        );
    }

    public function twoFactorDisabled(User $user, ?string $organisationId = null): void
    {
        $this->log(
            'auth',
            'two_factor.disabled',
            'two_factor.disabled',
            $user,
            $user,
            ['organisation_id' => $organisationId],
        );
    }

    /**
     * Logged when a brain write proposal is opened (#54). Never include body/secrets.
     *
     * @param  array<string, mixed>  $properties
     */
    public function proposalOpened(Model $subject, ?User $causer, array $properties): void
    {
        $this->log('brain', 'proposal.opened', 'proposal.opened', $subject, $causer, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function proposalAccepted(Model $subject, ?User $causer, array $properties): void
    {
        $this->log('brain', 'proposal.accepted', 'proposal.accepted', $subject, $causer, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function proposalRejected(Model $subject, ?User $causer, array $properties): void
    {
        $this->log('brain', 'proposal.rejected', 'proposal.rejected', $subject, $causer, $properties);
    }
}
