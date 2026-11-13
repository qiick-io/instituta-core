<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GlobalSearchResultData;
use App\Http\Requests\GlobalSearchRequest;
use App\Models\User;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;

/**
 * Command palette server search: grouped records the signed-in user may open in the perimeter.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearchService $search): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        $perimeter = $search->perimeterFor($user);
        $groups = [];

        foreach ($search->search($user, $request->searchQuery(), $perimeter) as $type => $results) {
            $groups[] = [
                'type' => $type,
                'items' => array_map(
                    static fn (GlobalSearchResultData $result): array => $result->toArray(),
                    $results,
                ),
            ];
        }

        return response()->json(['groups' => $groups]);
    }
}
