<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\ProposeBrainChangeAction;
use App\Actions\UpdateProposalStatusAction;
use App\Brain\Enums\RoleEnum;
use App\Data\ProposeBrainChangeData;
use App\Data\UpdateProposalStatusData;
use App\Enums\ProposalStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\IndexBrainProposalsRequest;
use App\Http\Requests\Brain\StoreBrainProposalRequest;
use App\Http\Requests\Brain\UpdateBrainProposalStatusRequest;
use App\Models\User;
use App\Services\ProposalDiffService;
use App\Services\ProposalQueryService;
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
        private readonly ProposalQueryService $proposals,
        private readonly UpdateProposalStatusAction $updateStatus,
        private readonly ProposeBrainChangeAction $propose,
        private readonly ProposalDiffService $diffs,
    ) {}

    public function index(IndexBrainProposalsRequest $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);
        $status = $request->statusFilter();

        return Inertia::render('brain/proposals', [
            'proposals' => $perimeter === null ? [] : $this->proposals->listForUi($perimeter, $status),
            'hasPerimeter' => $perimeter !== null,
            'statusFilter' => $status->value,
            'canDecide' => $perimeter !== null && $perimeter->role !== RoleEnum::Guest,
        ]);
    }

    public function show(Request $request, string $proposal): Response
    {
        $perimeter = $this->sessions->requirePerimeter($request);

        $row = $this->proposals->findForUi($perimeter, $proposal);
        if ($row === null) {
            abort(404);
        }

        $model = $this->proposals->findInPerimeter($perimeter, $proposal);

        return Inertia::render('brain/proposals/show', [
            'proposal' => $row,
            'diff' => $model === null ? null : $this->diffs->forProposal($model),
            'canDecide' => $perimeter->role !== RoleEnum::Guest && $row['status'] === ProposalStatusEnum::Open->value,
        ]);
    }

    /**
     * A person proposes a change from the reading view or the tree. Like every surface it only
     * opens a proposal; refusals (stale version, guest, duplicate) come back as form errors.
     */
    public function store(StoreBrainProposalRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $proposal = $this->propose->execute(
                $this->sessions->requirePerimeter($request),
                null,
                ProposeBrainChangeData::fromInput($request->validated()),
                'web',
                $user,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['body' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposal opened.')]);

        return redirect()->route('brain.proposals.show', $proposal);
    }

    public function update(UpdateBrainProposalStatusRequest $request, string $proposal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $perimeter = $this->sessions->requirePerimeter($request);

        $model = $this->proposals->findInPerimeter($perimeter, $proposal);
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
                    note: $request->validated('note'),
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
