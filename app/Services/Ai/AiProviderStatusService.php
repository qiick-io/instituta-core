<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is the assistant usable right now (#203)? Off unless `ai.assistant.enabled`. A cloud provider
 * counts as online when its key is set; the local OpenAI-compatible endpoint is probed
 * (`GET /models`, 2 s), cached briefly as externa-core does so a busy model does not stick offline.
 */
final class AiProviderStatusService
{
    private const string LOCAL_PROVIDER = 'openai-compatible';

    /**
     * @return array{enabled: bool, provider: string, online: bool, model: string|null, context_window: int|null}
     */
    public function status(): array
    {
        $provider = (string) config('ai.assistant.provider', self::LOCAL_PROVIDER);
        $window = config('ai.providers.'.$provider.'.context_window');
        $base = [
            'enabled' => (bool) config('ai.assistant.enabled'),
            'provider' => $provider,
            'context_window' => is_numeric($window) && (int) $window > 0 ? (int) $window : null,
        ];

        if (! $base['enabled']) {
            return [...$base, 'online' => false, 'model' => null];
        }

        if ($provider !== self::LOCAL_PROVIDER) {
            $key = config('ai.providers.'.$provider.'.key');

            return [...$base, 'online' => is_string($key) && $key !== '', 'model' => null];
        }

        /** @var array{online: bool, model: string|null}|null $cached */
        $cached = Cache::get('ai.assistant.status');
        if (is_array($cached) && $cached['online'] === true) {
            return [...$base, ...$cached];
        }

        $probe = $this->probeLocal();
        Cache::put('ai.assistant.status', $probe, $probe['online'] ? 15 : 3);

        return [...$base, ...$probe];
    }

    /**
     * @return array{online: bool, model: string|null}
     */
    private function probeLocal(): array
    {
        $baseUrl = rtrim((string) config('ai.providers.'.self::LOCAL_PROVIDER.'.url', 'http://127.0.0.1:11434/v1'), '/');
        $apiKey = (string) config('ai.providers.'.self::LOCAL_PROVIDER.'.key', '');
        $configuredModel = config('ai.providers.'.self::LOCAL_PROVIDER.'.models.text.default');
        $model = is_string($configuredModel) && $configuredModel !== '' ? $configuredModel : null;

        try {
            $request = Http::timeout(2)->acceptJson();
            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($baseUrl.'/models');

            return ['online' => $response->successful(), 'model' => $model];
        } catch (ConnectionException) {
            return ['online' => false, 'model' => $model];
        } catch (Throwable) {
            return ['online' => false, 'model' => $model];
        }
    }
}
