<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\User;

/**
 * A review status with no rows keeps its filter bar, so the user can go back to Open (#364).
 * Runs in the `ui-quality` browser job, not in `php artisan test`.
 */
it('keeps the status filter on an empty status and goes back to open', function (): void {
    $organisation = Organisation::factory()->create();
    $owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($owner, $organisation, RoleEnum::Owner);
    $this->actingAs($owner);

    visit(route('review.proposals.index', ['status' => 'accepted'], absolute: false))
        ->assertSee('No matching proposals')
        ->assertPresent('[aria-label="Accepted"][data-state="on"]')
        ->assertPresent('[aria-label="Open"]')
        ->press('Clear filters')
        ->assertSee('No proposals in this filter')
        ->assertQueryStringHas('status', 'open')
        ->assertNoJavaScriptErrors();
});
