<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\MaterialiseProposalAction;
use App\Jobs\Middleware\SetPermissionsTeamJobMiddleware;
use App\Models\Proposal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues git / GitHub PR materialisation for an accepted proposal.
 */
final class MaterialiseProposalJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $proposalId,
    ) {}

    public function uniqueId(): string
    {
        return $this->proposalId;
    }

    /**
     * @return list<class-string>
     */
    public function middleware(): array
    {
        return [SetPermissionsTeamJobMiddleware::class];
    }

    public function organisationIdForPermissions(): ?string
    {
        $organisationId = Proposal::query()->whereKey($this->proposalId)->value('organisation_id');

        return is_string($organisationId) && $organisationId !== '' ? $organisationId : null;
    }

    public function handle(MaterialiseProposalAction $materialise): void
    {
        $proposal = Proposal::query()->find($this->proposalId);

        if ($proposal === null) {
            return;
        }

        $materialise->execute($proposal);
    }
}
