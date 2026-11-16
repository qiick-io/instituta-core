<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Ai\Agents\AppAssistant;
use App\Brain\Enums\RoleEnum;
use App\Enums\RoleEnum as AppRoleEnum;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The Assistant page (#203): the off state, and a question landing in the thread, in light and
 * dark. The streamed answer itself is covered by the feature test (`AiAssistantTest`): the
 * browser test server does not forward the SSE body while the request is open.
 * Runs in the `ui-quality` browser job.
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $organisation = Organisation::factory()->create();
    $this->user = User::factory()->create()->assignRole(AppRoleEnum::Admin->value);
    app(GrantOrganisationAccessAction::class)->execute($this->user, $organisation, RoleEnum::Member);
    $this->actingAs($this->user);
});

it('says how to turn the assistant on while it is off', function (): void {
    config(['ai.assistant.enabled' => false]);

    visit(route('ai.index', absolute: false))
        ->assertSee('The assistant is off')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);
});

it('puts the question in the thread and waits for the answer, in light and dark', function (string $mode): void {
    config(['ai.assistant.enabled' => true, 'ai.providers.openai-compatible.url' => 'http://127.0.0.1:9/v1']);
    AppAssistant::fake(['Teal is the primary colour.']);

    $start = visit(route('ai.index', absolute: false));
    $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

    $page->assertSee('Ask the brain')
        ->type('textarea[aria-label="Ask the brain"]', 'Which colour is primary?')
        ->click('button[aria-label="Send"]')
        ->assertSee('Which colour is primary?')
        ->assertPresent('[data-slot="bubble"][data-align="end"]')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);
})->with(['light', 'dark']);
