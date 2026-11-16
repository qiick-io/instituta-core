<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\EraseClientCompanyAction;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Agency portal: erase an exported client subtree.
 */
final class ClientEraseController extends Controller
{
    public function __invoke(
        Request $request,
        Company $company,
        OffboardingRequest $offboarding,
        EraseClientCompanyAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $result = $action->execute($user, $company, $offboarding);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client erased (:count items removed).', [
                'count' => $result['deleted_items'],
            ]),
        ]);

        return to_route('clients.index');
    }
}
