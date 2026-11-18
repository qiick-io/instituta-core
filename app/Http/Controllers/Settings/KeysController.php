<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateBrainApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Actions\RotateApiKeyAction;
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
 * API keys for organisation owners: list, create, rotate, revoke (#201 / #329).
 *
 * Key management is session-authenticated only — never reachable with an API key.
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

        $perimeter = $this->sessions->optionalPerimeter($request);

        return Inertia::render('settings/api-keys', [
            ...$this->keys->listFor($user),
            // The areas a new key can be limited to (#334).
            'areaOptions' => $perimeter === null ? [] : $this->keys->areaOptionsFor($perimeter),
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
            $issued = $createKey->execute(
                $owner,
                $perimeter,
                CreateBrainApiKeyData::fromValidated($request->validated()),
                $user,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return to_route('api-keys.index')->with(self::PLAIN_KEY_FLASH, $issued['plain']);
    }

    public function rotate(ApiKey $key, RotateApiKeyAction $rotateKey, Request $request): RedirectResponse
    {
        Gate::authorize('update', $key);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $issued = $rotateKey->execute($key, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return to_route('api-keys.index')->with(self::PLAIN_KEY_FLASH, $issued['plain']);
    }

    public function destroy(ApiKey $key, RevokeApiKeyAction $revokeKey, Request $request): RedirectResponse
    {
        Gate::authorize('delete', $key);

        $user = $request->user();
        $revokeKey->execute($key, $user instanceof User ? $user : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('api-keys.index');
    }
}
