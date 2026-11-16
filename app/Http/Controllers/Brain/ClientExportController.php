<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\ExportClientCompanyAction;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\ClientExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Agency portal: export a client subtree and download the resulting archive.
 */
final class ClientExportController extends Controller
{
    public function __construct(
        private readonly ClientExportService $exports,
    ) {}

    public function store(
        Request $request,
        Company $company,
        ExportClientCompanyAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $action->execute($user, $company);
        $itemCount = (int) ($result['manifest']['counts']['items'] ?? 0);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client export ready (:count items).', ['count' => $itemCount]),
        ]);

        return to_route('clients.index', [
            'exported' => $result['request']->id,
        ]);
    }

    public function download(
        Request $request,
        Company $company,
        OffboardingRequest $offboarding,
    ): BinaryFileResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Gate::authorize('view', $company);

        if ($offboarding->company_id !== $company->id) {
            abort(404);
        }

        $exportPath = $offboarding->export_path;

        if (! is_string($exportPath) || $exportPath === '' || ! File::isDirectory($exportPath)) {
            abort(404);
        }

        $zipPath = $this->exports->zipExportDirectory($exportPath, $company->slug);

        return response()
            ->download($zipPath, $company->slug.'-export.zip')
            ->deleteFileAfterSend(true);
    }
}
