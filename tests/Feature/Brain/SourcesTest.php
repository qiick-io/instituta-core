<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SyncStatusEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function sourcesSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Sources Org']);
    $company = Company::factory()->create(['organisation_id' => $org->id]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    Source::factory()->create([
        'organisation_id' => $org->id,
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => 'product-brain',
        'sync_status' => SyncStatusEnum::Ok,
        'last_synced_commit' => 'abc1234567890',
        'last_sync_report' => ['indexed' => 3, 'skipped' => 0],
    ]);

    return compact('user');
}

it('lists sources in the perimeter', function (): void {
    $seed = sourcesSeed();

    $this->actingAs($seed['user'])
        ->get(route('repositories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/sources')
            ->where('hasPerimeter', true)
            ->has('sources', 1)
            ->where('sources.0.name', 'product-brain')
            ->where('sources.0.sync_status', 'ok')
            ->where('sources.0.last_synced_commit', 'abc1234567890'));
});

it('requires authentication for sources', function (): void {
    $this->get(route('repositories.index'))->assertRedirect();
});
