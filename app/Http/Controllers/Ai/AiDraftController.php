<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\DraftProposalRequest;
use App\Models\User;
use App\Services\Ai\ProposalDraftService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Throwable;

/**
 * "Draft with AI" in the propose drawer (#203): returns markdown for the form, never a proposal.
 */
final class AiDraftController extends Controller
{
    public function __invoke(DraftProposalRequest $request, SessionPerimeterService $sessions, ProposalDraftService $drafts): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        try {
            $markdown = $drafts->draft(
                $user,
                $sessions->requirePerimeter($request),
                (string) $validated['request'],
                isset($validated['stable_key']) && is_string($validated['stable_key']) && $validated['stable_key'] !== '' ? $validated['stable_key'] : null,
                isset($validated['section_anchor']) && is_string($validated['section_anchor']) && $validated['section_anchor'] !== '' ? $validated['section_anchor'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('The draft could not be written: :reason', ['reason' => $exception->getMessage()])], 502);
        }

        return response()->json(['markdown' => $markdown]);
    }
}
