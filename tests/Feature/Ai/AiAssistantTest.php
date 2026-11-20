<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Ai\Agents\AppAssistant;
use App\Ai\Tools\OutlineBrainItemTool;
use App\Ai\Tools\SearchBrainTool;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\RoleEnum as AppRoleEnum;
use App\Models\Activity;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Source;
use App\Models\User;
use App\Services\SessionPerimeterService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The in-app assistant backend (#203): off by default, permission and perimeter bound, daily
 * caps, streamed answers, own conversations only. Never a real provider.
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    config(['ai.assistant.enabled' => true, 'ai.daily_prompt_limit' => 0, 'ai.daily_token_budget' => 0]);

    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create()->assignRole(AppRoleEnum::Admin->value);
    app(GrantOrganisationAccessAction::class)->execute($this->user, $this->organisation, RoleEnum::Member);
});

it('reports the assistant off by default and never probes a provider then', function (): void {
    config(['ai.assistant.enabled' => false]);
    Http::fake();

    $this->actingAs($this->user)
        ->getJson(route('ai.status'))
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('online', false)
        ->assertJsonPath('daily_usage', null);

    $this->actingAs($this->user)->postJson(route('ai.chat'), ['message' => 'Hello'])->assertStatus(503);
    Http::assertNothingSent();
});

it('probes the local provider when the assistant is on', function (): void {
    Http::fake(['127.0.0.1:11434/v1/models' => Http::response(['data' => [['id' => 'local-model']]])]);
    config(['ai.providers.openai-compatible.url' => 'http://127.0.0.1:11434/v1']);

    $this->actingAs($this->user)
        ->getJson(route('ai.status'))
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('online', true)
        ->assertJsonPath('context_window', 131072);
});

it('needs the can-use-ai permission', function (): void {
    $reader = User::factory()->create()->assignRole(AppRoleEnum::Reader->value);

    $this->actingAs($reader)->getJson(route('ai.status'))->assertForbidden();
    $this->actingAs($reader)->postJson(route('ai.chat'), ['message' => 'Hello'])->assertForbidden();
});

it('streams an answer, remembers the conversation and logs the prompt without the answer text', function (): void {
    AppAssistant::fake(['Teal is the primary colour.']);

    $response = $this->actingAs($this->user)->post(route('ai.chat'), ['message' => 'Which colour is primary?']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $content = $response->streamedContent();
    expect($content)->toContain('"type":"text_delta"')
        ->and($content)->toContain('"type":"conversation"')
        ->and($content)->toContain('data: [DONE]');

    AppAssistant::assertPrompted('Which colour is primary?');

    $prompt = Activity::query()->where('log_name', 'ai')->where('event', 'ai_prompt')->sole();
    $answer = Activity::query()->where('log_name', 'ai')->where('event', 'ai_response')->sole();
    expect($prompt->properties['organisation_id'])->toBe($this->organisation->id)
        ->and($prompt->properties['surface'])->toBe('chat')
        ->and(json_encode($answer->properties))->not->toContain('Teal is the primary');

    $conversationId = $this->actingAs($this->user)->getJson(route('ai.conversations.index'))->json('conversations.0.id');
    expect($conversationId)->toBeString();

    $this->actingAs($this->user)
        ->getJson(route('ai.conversations.show', $conversationId))
        ->assertOk()
        ->assertJsonPath('messages.0.role', 'user');
});

it('refuses a new prompt once the daily prompt limit is used', function (): void {
    config(['ai.daily_prompt_limit' => 1]);
    activity('ai')->causedBy($this->user)->event('ai_prompt')->log('ai_prompt');
    AppAssistant::fake(['Never sent']);

    $this->actingAs($this->user)
        ->postJson(route('ai.chat'), ['message' => 'One more'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Daily AI prompt limit reached.');

    AppAssistant::assertNeverPrompted();

    config(['ai.assistant.enabled' => true]);
    $this->actingAs($this->user)->getJson(route('ai.status'))->assertJsonPath('daily_usage.prompts_used', 1);
});

it('keeps conversations private to their owner', function (): void {
    $conversationId = (string) Str::uuid7();
    Conversation::query()->create([
        'id' => $conversationId,
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => $this->user->id,
        'title' => 'Brand questions',
    ]);
    $other = User::factory()->create()->assignRole(AppRoleEnum::Admin->value);
    app(GrantOrganisationAccessAction::class)->execute($other, $this->organisation, RoleEnum::Member);

    $this->actingAs($other)->getJson(route('ai.conversations.show', $conversationId))->assertNotFound();
    $this->actingAs($other)->deleteJson(route('ai.conversations.destroy', $conversationId))->assertNotFound();
    $this->actingAs($other)->postJson(route('ai.chat'), ['message' => 'Hi', 'conversation_id' => $conversationId])->assertNotFound();

    $this->actingAs($this->user)->deleteJson(route('ai.conversations.destroy', $conversationId))->assertOk();
    expect(Conversation::query()->find($conversationId))->toBeNull();
});

it('answers 404 to a user without a brain perimeter', function (): void {
    $outsider = User::factory()->create()->assignRole(AppRoleEnum::Admin->value);
    AppAssistant::fake(['Never sent']);

    $this->actingAs($outsider)->postJson(route('ai.chat'), ['message' => 'Hi'])->assertNotFound();
});

it('lets the brain tools read only inside the user perimeter', function (): void {
    skipUnlessKeywordSearchSupported();

    $source = Source::factory()->create(['organisation_id' => $this->organisation->id]);
    $own = Item::factory()->create(['organisation_id' => $this->organisation->id, 'source_id' => $source->id, 'title' => 'Teal brand colour', 'visibility' => VisibilityEnum::Internal]);
    $foreign = Organisation::factory()->create();
    $foreignSource = Source::factory()->create(['organisation_id' => $foreign->id]);
    $foreignItem = Item::factory()->create(['organisation_id' => $foreign->id, 'source_id' => $foreignSource->id, 'title' => 'Teal secret of another company', 'visibility' => VisibilityEnum::Internal]);

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $this->user);
    $request->setLaravelSession(app('session.store'));
    $perimeter = app(SessionPerimeterService::class)->requirePerimeter($request);

    $outline = new OutlineBrainItemTool($perimeter);

    expect($outline->handle(new ToolRequest(['item' => $own->id])))->toContain($own->path)
        ->and($outline->handle(new ToolRequest(['item' => $foreignItem->id])))->toBe('{"error":"Not found in your perimeter."}')
        ->and((new SearchBrainTool($perimeter))->handle(new ToolRequest(['q' => 'another company'])))->not->toContain('another company');
});

it('renders the assistant page with its status, also while the assistant is off', function (): void {
    config(['ai.assistant.enabled' => false]);

    $this->withoutVite()
        ->actingAs($this->user)
        ->get(route('ai.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ai/index')
            ->where('status.enabled', false)
            ->where('conversations', []));

    $reader = User::factory()->create()->assignRole(AppRoleEnum::Reader->value);
    $this->actingAs($reader)->get(route('ai.index'))->assertForbidden();
});
