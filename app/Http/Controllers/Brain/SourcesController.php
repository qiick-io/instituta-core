<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\RemoveSourceAction;
use App\Actions\UpdateSourceAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Exceptions\DenylistHitException;
use App\Data\SourceFormData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\StoreSourceRequest;
use App\Http\Requests\Brain\UpdateSourceRequest;
use App\Models\Organisation;
use App\Models\Source;
use App\Models\User;
use App\Services\BrainSourcesService;
use App\Services\PerimeterScopeColumnsService;
use App\Services\SessionPerimeterService;
use App\Services\SourceRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Sources of the session perimeter: everyone in it reads the list, owners add, edit and remove
 * sources (#201) through the same registry and denylist as `instituta:source:add`.
 */
final class SourcesController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainSourcesService $sources,
    ) {}

    public function index(Request $request): Response
    {
        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('brain/sources', [
            'sources' => $perimeter === null ? [] : $this->sources->list($perimeter),
            'hasPerimeter' => $perimeter !== null,
            'canManage' => $perimeter?->role === RoleEnum::Owner,
        ]);
    }

    public function store(StoreSourceRequest $request, SourceRegistryService $registry, PerimeterScopeColumnsService $scopeColumns): RedirectResponse
    {
        $perimeter = $this->sessions->requirePerimeter($request);
        $data = SourceFormData::fromValidated($request->validated());
        [$organisationId, $companyId, $productId] = $scopeColumns->columnsFor($perimeter);

        try {
            $registry->add(
                organisation: Organisation::query()->findOrFail($organisationId),
                name: $data->name,
                provider: $data->provider,
                remoteUrl: $data->remoteUrl,
                localPath: $data->localPath,
                ref: $data->ref,
                path: $data->path,
                adapter: $data->adapter,
                defaultVisibility: $data->defaultVisibility,
                isShared: $data->isShared,
                scopeType: $perimeter->scopeType,
                companyId: $companyId,
                productId: $productId,
                syncIntervalMinutes: $data->syncIntervalMinutes,
            );
        } catch (DenylistHitException|InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source added. Sync it to index its content.')]);

        return to_route('brain.sources');
    }

    public function update(UpdateSourceRequest $request, Source $source, UpdateSourceAction $updateSource): RedirectResponse
    {
        try {
            $updateSource->execute($source, SourceFormData::fromValidated($request->validated()));
        } catch (DenylistHitException|InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source updated.')]);

        return to_route('brain.sources');
    }

    public function destroy(Request $request, Source $source, RemoveSourceAction $removeSource): RedirectResponse
    {
        Gate::authorize('delete', $source);

        $actor = $request->user();
        $removeSource->execute($source, $actor instanceof User ? $actor : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Source removed.')]);

        return to_route('brain.sources');
    }
}
