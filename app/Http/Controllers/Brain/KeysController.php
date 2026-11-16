<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\CreateBrainApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Data\CreateBrainApiKeyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\BrainKeysService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * API keys for organisation owners: list, create for themself or a new agent, revoke (#201).
 * The plain secret travels once, through the session flash, and is never stored.
 */
final class KeysController extends Controller
{
    private const string PLAIN_KEY_FLASH = 'plain_text_api_key';

    public function __construct(
        private readonly BrainKeysService $keys,
        private readonly SessionPerimeterService $sessions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Gate::authorize('viewAny', ApiKey::class);

        return Inertia::render('brain/keys', [
            ...$this->keys->listFor($user),
            'plainTextKey' => $request->session()->get(self::PLAIN_KEY_FLASH),
        ]);
    }

    public function store(StoreApiKeyRequest $request, CreateBrainApiKeyAction $createKey): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $perimeter = $this->sessions->requirePerimeter($request);
        $owner = $this->sessions->personPrincipal($user);
        abort_if($owner === null, 403);

        try {
            $issued = $createKey->execute($owner, $perimeter, CreateBrainApiKeyData::fromValidated($request->validated()));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return to_route('brain.keys')->with(self::PLAIN_KEY_FLASH, $issued['plain']);
    }

    public function destroy(ApiKey $key, RevokeApiKeyAction $revokeKey): RedirectResponse
    {
        Gate::authorize('delete', $key);

        $revokeKey->execute($key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('brain.keys');
    }
}
