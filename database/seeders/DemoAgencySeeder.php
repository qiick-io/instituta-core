<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CreateApiKeyAction;
use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\PrincipalTypeEnum;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\CompanyKindEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\RoleEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Company;
use App\Models\ContractCall;
use App\Models\GapReport;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * A fictional agency for demos and screenshots (#323): "Northwind Studio" with its handbook brain,
 * a small UI kit registry, three client companies with two or three products each, client and
 * product brains that override the handbook, proposals and gaps in the review inbox, and agent
 * calls made through the real REST API (so the call log is genuine).
 *
 * Every name and every line of content is invented; the brains live in `database/demo/`. Run on an
 * empty database only: `php artisan migrate:fresh && php artisan db:seed --class=DemoAgencySeeder`.
 * Sign in as ada@northwind.example / `password`.
 *
 * @side-effect Syncs the demo sources (copies demo assets to private storage) and issues API keys.
 */
final class DemoAgencySeeder extends Seeder
{
    public const string OWNER_EMAIL = 'ada@northwind.example';

    public const string PASSWORD = 'password';

    /** @var array<string, array{name: string, products: list<array{slug: string, name: string}>, brain_product: string}> */
    private const array CLIENTS = [
        'harbor-coffee' => [
            'name' => 'Harbor Coffee Co.',
            'products' => [['slug' => 'harbor-app', 'name' => 'Harbor App'], ['slug' => 'loyalty-portal', 'name' => 'Loyalty Portal']],
            'brain_product' => 'loyalty-portal',
        ],
        'fjord-mobility' => [
            'name' => 'Fjord Mobility',
            'products' => [['slug' => 'fleet-console', 'name' => 'Fleet Console'], ['slug' => 'rider-app', 'name' => 'Rider App'], ['slug' => 'partner-api', 'name' => 'Partner API']],
            'brain_product' => 'partner-api',
        ],
        'lumen-health' => [
            'name' => 'Lumen Health Clinic',
            'products' => [['slug' => 'patient-portal', 'name' => 'Patient Portal'], ['slug' => 'booking-widget', 'name' => 'Booking Widget']],
            'brain_product' => 'booking-widget',
        ],
    ];

    public function run(
        GrantOrganisationAccessAction $grantAccess,
        CreateApiKeyAction $createApiKey,
        SourceRegistryService $sources,
        SourceSyncService $sync,
    ): void {
        Artisan::call('instituta:sync-permissions');
        $this->call(RoleSeeder::class);

        $organisation = Organisation::query()->create(['slug' => 'northwind', 'name' => 'Northwind Studio']);
        Company::query()->create([
            'organisation_id' => $organisation->id,
            'slug' => 'northwind-own',
            'name' => 'Northwind Studio',
            'kind' => CompanyKindEnum::Own,
        ]);

        $owner = $this->person('Ada', 'Lindqvist', self::OWNER_EMAIL, RoleEnum::SuperAdmin);
        $ownerPrincipal = $grantAccess->execute($owner, $organisation, BrainRoleEnum::Owner);
        $marco = $this->person('Marco', 'Bianchi', 'marco@northwind.example', RoleEnum::Admin);
        $grantAccess->execute($marco, $organisation, BrainRoleEnum::Member);
        $priya = $this->person('Priya', 'Nair', 'priya@northwind.example', RoleEnum::Reader);
        $grantAccess->execute($priya, $organisation, BrainRoleEnum::Member);

        $team = UserGroup::query()->create(['name' => 'Delivery team', 'description' => 'Developers who ship client releases']);
        $team->users()->attach([$marco->id, $priya->id]);

        $demo = database_path('demo');
        $handbook = $sources->add($organisation, 'northwind-handbook', SourceProviderEnum::Local, localPath: $demo.'/northwind-handbook', ref: null, isShared: true);
        $uiKit = $sources->add($organisation, 'northwind-ui', SourceProviderEnum::Local, localPath: $demo.'/northwind-ui', ref: null, adapter: SourceAdapterEnum::ShadcnRegistry, isShared: true);
        $toSync = [$handbook, $uiKit];
        $companies = [];

        foreach (self::CLIENTS as $slug => $client) {
            $company = Company::query()->create([
                'organisation_id' => $organisation->id,
                'slug' => $slug,
                'name' => $client['name'],
                'kind' => CompanyKindEnum::Client,
            ]);
            $companies[$slug] = $company;
            $products = [];

            foreach ($client['products'] as $productData) {
                $products[$productData['slug']] = Product::query()->create([
                    'company_id' => $company->id,
                    'slug' => $productData['slug'],
                    'name' => $productData['name'],
                    'inherits_from_parent' => true,
                ]);
            }

            $toSync[] = $sources->add($organisation, $slug, SourceProviderEnum::Local, localPath: $demo.'/clients/'.$slug, ref: null, scopeType: ScopeTypeEnum::Company, companyId: $company->id);
            $brainProduct = $products[$client['brain_product']] ?? null;

            if ($brainProduct === null) {
                continue;
            }

            $toSync[] = $sources->add($organisation, $slug.'-'.$brainProduct->slug, SourceProviderEnum::Local, localPath: $demo.'/products/'.$slug.'-'.$brainProduct->slug, ref: null, scopeType: ScopeTypeEnum::Product, companyId: $company->id, productId: $brainProduct->id);
        }

        foreach ($toSync as $source) {
            $sync->sync($source->fresh() ?? $source);
        }

        $this->reviewInbox($organisation, $ownerPrincipal);
        $this->agentCalls($organisation, $companies, $createApiKey);

        $this->command?->info('Demo agency ready. Sign in as '.self::OWNER_EMAIL.' / '.self::PASSWORD.'.');
    }

    private function person(string $firstName, string $lastName, string $email, RoleEnum $role): User
    {
        $user = User::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * Open proposals from people and agents, and a gap an agent reported.
     */
    private function reviewInbox(Organisation $organisation, Principal $owner): void
    {
        $agent = Principal::query()->create(['organisation_id' => $organisation->id, 'type' => PrincipalTypeEnum::Agent, 'name' => 'Claude Code · Northwind']);
        $proposals = [
            [$agent, 'Add the Friday release freeze to the release process', 'rules/release-process.md', 'rules', ProposalActionEnum::Update, "# Release process\n\n## Steps\n\n1. Freeze the release branch and run the full test suite.\n2. Write release notes in plain language for the client.\n3. Tag the release and deploy to staging, then production after the client's sign-off.\n\n## Freeze\n\nNo production releases after 14:00 on Fridays; see [[Staged rollouts for client apps]].\n"],
            [$agent, 'Document the rate limits of the Partner API', 'rules/partner-api-limits.md', 'rules', ProposalActionEnum::Create, "# Partner API rate limits\n\n## Limits\n\n- 600 requests per minute per partner key.\n- Bursts of 50 requests per second.\n\n## When a partner hits the limit\n\nThe API answers 429 with a `Retry-After` header.\n"],
            [$owner, 'Glossary: add "client brain override"', 'glossary/terms.md', 'glossary', ProposalActionEnum::Update, "# Glossary\n\n## Terms\n\n- **Client brain override**: a client rule with the same key as a handbook rule replaces it for that client.\n"],
        ];

        foreach ($proposals as [$principal, $title, $path, $area, $action, $body]) {
            Proposal::query()->create([
                'proposed_by_principal_id' => $principal->id,
                'organisation_id' => $organisation->id,
                'scope_type' => ScopeTypeEnum::Organisation,
                'scope_id' => $organisation->id,
                'source_id' => Source::query()->where('name', 'northwind-handbook')->value('id'),
                'status' => ProposalStatusEnum::Open,
                'action' => $action,
                'stable_key' => preg_replace('/\.md$/', '', $path),
                'title' => $title,
                'path' => $path,
                'area' => $area,
                'body' => $body,
            ]);
        }

        $releaseProcess = Item::query()->where('stable_key', 'rules/release-process')->first();
        GapReport::query()->create([
            'organisation_id' => $organisation->id,
            'scope_type' => ScopeTypeEnum::Organisation->value,
            'scope_id' => $organisation->id,
            'kind' => GapReportKindEnum::Missing,
            'status' => GapReportStatusEnum::Open,
            'summary' => 'No rollback steps for a failed staged rollout',
            'reported_by_principal_id' => $agent->id,
            'related' => $releaseProcess === null ? null : [[
                'item' => $releaseProcess->id,
                'stable_key' => $releaseProcess->stable_key,
                'path' => $releaseProcess->path,
                'title' => $releaseProcess->title,
                'anchor' => null,
            ]],
        ]);
    }

    /**
     * Agents reading the brain through the real REST API, so the call log holds genuine calls;
     * then spread over the last week, as a working agency's log would be.
     *
     * @param  array<string, Company>  $companies
     */
    private function agentCalls(Organisation $organisation, array $companies, CreateApiKeyAction $createApiKey): void
    {
        $keys = [];

        foreach (['Claude Code · Northwind' => [ScopeTypeEnum::Organisation, $organisation->id], 'Cursor · Fjord Mobility' => [ScopeTypeEnum::Company, $companies['fjord-mobility']->id], 'Codex · Harbor Coffee' => [ScopeTypeEnum::Company, $companies['harbor-coffee']->id]] as $name => [$scopeType, $scopeId]) {
            $principal = Principal::query()->firstOrCreate(['organisation_id' => $organisation->id, 'type' => PrincipalTypeEnum::Agent, 'name' => $name]);
            // An agent reads only where a grant lets it, like a person.
            Grant::query()->firstOrCreate(
                ['principal_id' => $principal->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                ['role' => BrainRoleEnum::Member, 'max_visibility' => VisibilityEnum::Internal],
            );
            $keys[] = ['plain' => $createApiKey->execute($principal, $name, $scopeType, $scopeId, BrainRoleEnum::Member, VisibilityEnum::Internal)['plain'], 'scope' => $scopeId];
        }

        $coding = Item::query()->where('stable_key', 'rules/coding')->whereNull('company_id')->value('id');
        $since = now()->subMonth()->toIso8601String();
        $calls = [
            [0, '/api/v1/search', ['q' => 'release process']],
            [0, '/api/v1/items/'.$coding, []],
            [0, '/api/v1/items/'.$coding.'/outline', []],
            [0, '/api/v1/context', ['q' => 'accessibility checks before a release']],
            [1, '/api/v1/search', ['q' => 'rate limits']],
            [1, '/api/v1/scopes', []],
            [1, '/api/v1/changes', ['since' => $since]],
            [2, '/api/v1/search', ['q' => 'brand voice']],
            [2, '/api/v1/items', ['area' => 'rules']],
            [0, '/api/v1/health/brain', []],
        ];

        foreach ($calls as [$keyIndex, $uri, $query]) {
            app()->handle(Request::create($uri, 'GET', [...$query, 'scope' => $keys[$keyIndex]['scope']], server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$keys[$keyIndex]['plain'],
                'HTTP_ACCEPT' => 'application/json',
            ]));
        }

        ContractCall::query()->orderBy('called_at')->get()->each(function (ContractCall $call, int $index): void {
            $call->forceFill(['called_at' => now()->subHours(150 - $index * 14)->subMinutes(($index * 17) % 50)])->save();
        });
    }
}
