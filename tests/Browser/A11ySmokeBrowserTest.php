<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\User;

/**
 * Axe smoke for key pages (#52), as in excubia-app: fails on critical or serious
 * violations (`assertNoAccessibilityIssues(1)`) in light and dark, plus one high-contrast
 * pass. Asserts that axe's `color-contrast` rule actually ran. Runs in the
 * `ui-quality / accessibility` CI job, not in `php artisan test`.
 */

/**
 * Visit with Playwright colour-scheme matching the a11y mode so `.dark` / tokens
 * apply on first paint.
 *
 * @param  'light'|'dark'|'high-contrast'  $mode
 */
function visitA11y(string $path, string $mode): mixed
{
    $page = $mode === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $isDark = $mode === 'dark' ? 'true' : 'false';
    $isHighContrast = $mode === 'high-contrast' ? 'true' : 'false';

    return $page->assertScript(
        "() => { const root = document.documentElement; root.classList.toggle('dark', {$isDark}); root.classList.toggle('high-contrast', {$isHighContrast}); root.style.colorScheme = {$isDark} ? 'dark' : 'light'; return true; }",
        true,
    );
}

/**
 * Asserts that axe really evaluated `color-contrast` on the page (app-ui docs/theme-tokens.md).
 * Call after `assertNoAccessibilityIssues()`, which loads axe on the page.
 */
function assertAxeCheckedColorContrast(mixed $page): mixed
{
    expect($page->script(<<<'JS'
        async () => (await window.axe.run({ runOnly: ['color-contrast'] }))
            .incomplete.filter((result) => result.error)
            .map((result) => result.error.message ?? String(result.error))
        JS))->toBe([]);

    return $page;
}

/**
 * Owner user with an organisation perimeter so brain pages render.
 *
 * @return array{user: User}
 */
function seededA11yOwner(): array
{
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create(['name' => 'A11y Org']);
    $company = Company::factory()->create(['organisation_id' => $organisation->id]);
    Product::factory()->create(['company_id' => $company->id]);
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return ['user' => $user];
}

it('has no critical or serious axe issues on login in light, dark and high contrast', function (): void {
    foreach (['light', 'dark', 'high-contrast'] as $mode) {
        $page = visitA11y('/login', $mode)
            ->assertPresent('#main-content')
            ->assertNoAccessibilityIssues(1);

        assertAxeCheckedColorContrast($page)->assertNoJavaScriptErrors();
    }
});

it('has no critical or serious axe issues on the main authenticated pages in light and dark', function (): void {
    $seed = seededA11yOwner();
    $this->actingAs($seed['user']);

    $paths = [
        route('dashboard', absolute: false),
        route('brain.map', absolute: false),
        route('brain.tree', absolute: false),
        route('brain.resolved', absolute: false),
        route('brain.tables.index', absolute: false),
        route('brain.sources', absolute: false),
        route('brain.keys', absolute: false),
        route('brain.clients.index', absolute: false),
        route('brain.session-replay', absolute: false),
        route('activity.index', absolute: false),
        route('profile.edit', absolute: false),
        route('appearance.edit', absolute: false),
        route('security.edit', absolute: false),
    ];

    foreach ($paths as $path) {
        foreach (['light', 'dark'] as $mode) {
            $page = visitA11y($path, $mode)
                ->assertPresent('#main-content')
                ->assertNoAccessibilityIssues(1);

            assertAxeCheckedColorContrast($page)->assertNoJavaScriptErrors();
        }
    }
});

it('has no critical or serious axe issues on the dashboard in high contrast', function (): void {
    $seed = seededA11yOwner();
    $this->actingAs($seed['user']);

    $page = visitA11y(route('dashboard', absolute: false), 'high-contrast')
        ->assertPresent('#main-content')
        ->assertNoAccessibilityIssues(1);

    assertAxeCheckedColorContrast($page)->assertNoJavaScriptErrors();
});
