<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourceJob;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Sync now" on the Sources page (#201): queues a full sync, like `instituta:sync --queue`.
 */
final class SourceSyncController extends Controller
{
    public function __invoke(Source $source): RedirectResponse
    {
        Gate::authorize('sync', $source);

        SyncSourceJob::dispatch($source->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync queued.')]);

        return to_route('repositories.index');
    }
}
