<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use App\Models\GapReport;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * An owner turns a reported gap into a proposal from the Gaps page, and dismisses another (#229).
 * Runs in the `ui-quality` browser job, not in `php artisan test`.
 */
it('turns a gap into a prefilled proposal and dismisses another, in light and dark', function (string $mode): void {
    Queue::fake();
    $organisation = Organisation::factory()->create();
    $owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($owner, $organisation, RoleEnum::Owner);
    $reporter = Principal::factory()->create(['organisation_id' => $organisation->id, 'name' => 'Docs agent']);

    $gap = fn (string $summary): GapReport => GapReport::query()->create([
        'organisation_id' => $organisation->id,
        'scope_type' => 'organisation',
        'scope_id' => $organisation->id,
        'kind' => GapReportKindEnum::Missing,
        'status' => GapReportStatusEnum::Open,
        'summary' => $summary,
        'reported_by_principal_id' => $reporter->id,
    ]);
    $toPropose = $gap('No rule on commit messages');
    $toDismiss = $gap('Glossary lacks “vault”');

    $this->actingAs($owner);
    $start = visit(route('brain.gaps.index', absolute: false));
    $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

    $page->assertSee('No rule on commit messages')
        ->assertSee('Docs agent')
        ->assertNoAccessibilityIssues(1)
        ->click('tr:has-text("Glossary lacks") [aria-label="Dismiss"]')
        ->assertSee('Dismiss this gap?')
        ->press('Dismiss')
        ->assertDontSee('Glossary lacks')
        ->click('[aria-label="Propose a change"]')
        ->assertValue('#proposal_title', 'No rule on commit messages')
        ->assertNoJavaScriptErrors();

    expect($toDismiss->fresh()?->status)->toBe(GapReportStatusEnum::Dismissed)
        ->and($toPropose->fresh()?->status)->toBe(GapReportStatusEnum::Open);
})->with(['light', 'dark']);
