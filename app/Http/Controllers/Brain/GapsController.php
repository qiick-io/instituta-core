<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\DecideGapReportAction;
use App\Brain\Enums\RoleEnum;
use App\Exceptions\ContractException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\IndexBrainGapsRequest;
use App\Models\User;
use App\Services\GapReportQueryService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owners' list of gap reports (#229): read them, turn one into a proposal (the propose
 * drawer, which links it) or dismiss it. Members and guests see that the list is for owners.
 */
final class GapsController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly GapReportQueryService $gapReports,
        private readonly DecideGapReportAction $decide,
    ) {}

    public function index(IndexBrainGapsRequest $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);
        $isOwner = $perimeter !== null && $perimeter->role === RoleEnum::Owner;
        $status = $request->statusFilter();

        return Inertia::render('brain/gaps', [
            'gaps' => $isOwner ? $this->gapReports->listForUi($perimeter, $status) : [],
            'hasPerimeter' => $perimeter !== null,
            'isOwner' => $isOwner,
            'statusFilter' => $status->value,
        ]);
    }

    /**
     * Dismisses a report; converting happens when the proposal it opened is stored.
     */
    public function destroy(Request $request, string $gap): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $perimeter = $this->sessions->requirePerimeter($request);
        abort_unless($perimeter->role === RoleEnum::Owner, 403);

        $gapReport = $this->gapReports->findInPerimeter($perimeter, $gap);
        abort_if($gapReport === null, 404);

        try {
            $this->decide->execute($gapReport, $user);
        } catch (ContractException $exception) {
            return back()->withErrors(['gap' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Gap dismissed.')]);

        return redirect()->route('brain.gaps.index');
    }
}
