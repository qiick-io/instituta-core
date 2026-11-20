<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourceJob;
use App\Models\Source;
use App\Services\SourceWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives push webhooks (GitHub or any sender signing like it) and queues a sync of that
 * source (#199). No API key: the per-source HMAC signature is the authentication. A source
 * without a webhook secret answers 404, like an unknown one, so ids cannot be probed.
 */
final class SourceWebhookController extends Controller
{
    public function __construct(
        private readonly SourceWebhookService $webhooks,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        // Native UUID columns reject non-UUID binds on PostgreSQL; unknown shapes are 404.
        abort_unless(Str::isUuid($source), 404);
        $model = Source::query()->find($source);
        abort_if($model === null || $model->webhook_secret === null, 404);

        abort_unless($this->webhooks->isSignatureValid($model, $request->getContent(), $request->header('X-Hub-Signature-256')), 401);

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        SyncSourceJob::dispatch($model->id, isForced: false);

        return response()->json(['message' => 'Sync queued.'], 202);
    }
}
