<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\UpdateProposalStatusAction;
use App\Brain\Enums\RoleEnum;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\UpdateBrainProposalStatusRequest;
use App\Models\User;
use App\Services\BrainProposalsService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Human UI to list and accept/reject write proposals (#55).
 */
final class ProposalsController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainProposalsService $proposals,
        private readonly UpdateProposalStatusAction $updateStatus,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        $statusFilter = $request->string('status')->toString();
        $status = in_array($statusFilter, ['open', 'accepted', 'rejected'], true)
            ? ProposalStatusEnum::from($statusFilter)
            : ProposalStatusEnum::Open;

        return Inertia::render('brain/proposals', [
            'proposals' => $perimeter === null ? [] : $this->proposals->list($perimeter, $status),
            'hasPerimeter' => $perimeter !== null,
            'statusFilter' => $status->value,
            'canDecide' => $perimeter !== null && $perimeter->role !== RoleEnum::Guest,
        ]);
    }

    public function show(Request $request, string $proposal): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        if ($perimeter === null) {
            abort(404);
        }

        $row = $this->proposals->find($perimeter, $proposal);
        if ($row === null) {
            abort(404);
        }

        return Inertia::render('brain/proposals/show', [
            'proposal' => $row,
            'canDecide' => $perimeter->role !== RoleEnum::Guest && $row['status'] === ProposalStatusEnum::Open->value,
        ]);
    }

    public function update(UpdateBrainProposalStatusRequest $request, string $proposal): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        if ($perimeter === null) {
            abort(404);
        }

        $model = $this->proposals->findModel($perimeter, $proposal);
        if ($model === null) {
            abort(404);
        }

        try {
            $this->updateStatus->execute(
                $perimeter,
                null,
                $model,
                new UpdateProposalStatusData(
                    status: ProposalStatusEnum::from($request->validated('status')),
                ),
                'web',
                $user,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return redirect()
            ->route('brain.proposals.show', $proposal)
            ->with('success', true);
    }
}
