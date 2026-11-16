<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Pages left `/brain` (#252); old links land on the new address.
 */
it('redirects old brain urls to their new address', function (string $oldPath, string $newPath): void {
    $this->actingAs(User::factory()->create())
        ->get($oldPath)
        ->assertStatus(301)
        ->assertRedirect($newPath);
})->with([
    'item' => ['/brain/items/rules/coding', '/explorer/rules/coding'],
    'tree with its filter' => ['/brain/tree?area=rules', '/explorer?area=rules'],
    'proposal' => ['/brain/proposals/01abc', '/review/proposals/01abc'],
    'gaps' => ['/brain/gaps', '/review/gaps'],
    'sources' => ['/brain/sources', '/settings/repositories'],
    'keys' => ['/brain/keys', '/settings/api-keys'],
    'client products' => ['/brain/clients/01abc/products', '/clients/01abc/products'],
    'session replay' => ['/brain/session-replay', '/activity/agent-calls'],
]);

it('answers 404 for an unknown old address', function (): void {
    $this->actingAs(User::factory()->create())->get('/brain/nowhere')->assertNotFound();
});
