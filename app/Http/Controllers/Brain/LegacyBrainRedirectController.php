<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pages left the `/brain` prefix (#252): old links and bookmarks land on the new address.
 * Writes are not redirected (a redirected POST would turn into a GET).
 */
final class LegacyBrainRedirectController extends Controller
{
    /**
     * Old path prefix => new one, longest first.
     *
     * @var array<string, string>
     */
    private const array PREFIXES = [
        'items/' => 'explorer/',
        'tree' => 'explorer',
        'map' => 'map',
        'graph' => 'graph',
        'resolved' => 'resolved',
        'tables' => 'tables',
        'proposals' => 'review/proposals',
        'gaps' => 'review/gaps',
        'sources' => 'settings/repositories',
        'keys' => 'settings/api-keys',
        'clients' => 'clients',
        'session-replay' => 'activity/agent-calls',
    ];

    public function __invoke(Request $request, string $path): RedirectResponse
    {
        $query = $request->getQueryString();

        foreach (self::PREFIXES as $oldPrefix => $newPrefix) {
            if (str_starts_with($path, $oldPrefix)) {
                return redirect('/'.$newPrefix.substr($path, strlen($oldPrefix)).($query !== null ? '?'.$query : ''), 301);
            }
        }

        abort(404);
    }
}
