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
    ) {}

    public function index(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        $statusFilter = $request->string('status')->toString();
        $status = in_array($statusFilter, ['open', 'accepted', 'rejected'], true)
            ? ProposalStatusEnum::from($statusFilter)
            : ProposalStatusEnum::Open;

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

        return Inertia::render('brain/proposals/show', [
            'proposal' => $row,
            'canDecide' => $perimeter->role !== RoleEnum::Guest && $row['status'] === ProposalStatusEnum::Open->value,
        ]);
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
