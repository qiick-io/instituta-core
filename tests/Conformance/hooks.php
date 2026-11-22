<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\ContractErrorCodeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\Admin\UserResource;
use App\Http\Resources\ContractCallReplayResource;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use App\Services\Authorization\PermissionsTeamContextService;
use App\Services\Authorization\SuperAdminGuardService;
use App\Services\InitialSuperAdminPasswordService;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product-owned behaviour hooks for the fleet conformance suite.
 *
 * The sync writes this file once and never overwrites or checks it: it is the
 * repo's own. Scalar product settings (user model, table names, feature flags)
 * live in `qiick-standards.json` → `conformance`. Callables that exercise the
 * real app (soft-deleted groups, erasure, SSRF, …) go here.
 *
 * Each key matches a check that calls `ConformanceHooks::requireCallable()`.
 * Omit a key until the product is ready; the check fails until you implement
 * it or declare a divergence in `qiick-standards.json`.
 *
 * @return array<string, callable(): void>
 */
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Send a real request through the application kernel, without a PHPUnit test case.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 */
$sendRequest = static function (string $method, string $uri, array $payload = [], array $headers = []): Response {
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $content = $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR);

    if ($content !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        $server['CONTENT_LENGTH'] = strlen($content);
    }

    $request = Request::create($uri, $method, [], [], [], $server, $content);
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

/**
 * Decode a kernel response body as an array.
 *
 * @return array<string, mixed>
 */
$decode = static function (Response $response): array {
    $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
};

/**
 * @param  JsonResource<mixed>  $resource
 * @return array<string, mixed>
 */
$resolveResource = static function (JsonResource $resource): array {
    $decoded = $resource->resolve(app(Request::class));

    return is_array($decoded) ? $decoded : [];
};

return [
    'assertSoftDeletedGroupGrantsNothing' => static function () use ($assert): void {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $resolver = app(EffectivePermissionResolverService::class);

        /** @var UserGroup $group */
        $group = UserGroup::factory()->create();
        $group->roles()->attach(Role::findByName(RoleEnum::SuperAdmin->value));
        $user = User::factory()->create();
        $user->groups()->attach($group);

        $assert($resolver->isSuperAdmin($user), 'A group with the super-admin role must grant super-admin.');
        $assert($resolver->permissionsFor($user) === PermissionEnum::values(), 'A group role must grant its permissions.');

        $group->delete();
        $resolver->forget($user);

        $assert(! $resolver->isSuperAdmin($user), 'A soft-deleted group must not grant super-admin.');
        $assert($resolver->permissionsFor($user) === [], 'A soft-deleted group must grant no permissions.');
        $assert(! Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value), 'A soft-deleted group must not pass the gate.');
    },

    'assertGlobalRoleGuard' => static function () use ($assert): void {
        $assert(Schema::hasColumn('roles', 'guard_name'), 'Roles must carry a guard_name.');

        $guardNameColumn = collect(Schema::getColumns('roles'))->firstWhere('name', 'guard_name');
        $assert($guardNameColumn !== null && $guardNameColumn['nullable'] === false, 'roles.guard_name must be NOT NULL to close the Spatie hole.');

        $teamKey = (string) config('permission.column_names.team_foreign_key', 'organisation_id');
        $expectedUnique = config('permission.teams')
            ? [$teamKey, 'guard_name', 'name']
            : ['guard_name', 'name'];
        sort($expectedUnique);

        $hasUniqueNameAndGuard = collect(Schema::getIndexes('roles'))->contains(static function (array $index) use ($expectedUnique): bool {
            if (! ($index['unique'] ?? false)) {
                return false;
            }

            $columns = $index['columns'] ?? [];
            sort($columns);

            return $columns === $expectedUnique;
        });
        $assert(
            $hasUniqueNameAndGuard,
            config('permission.teams')
                ? "roles must have a unique ({$teamKey}, name, guard_name) index when teams are on."
                : 'roles must have a unique (name, guard_name) index.',
        );

        Role::query()->create([
            'name' => 'conformance-global-role',
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key') => null,
        ]);

        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $superAdmin = User::factory()->create();
        app(InstanceSuperAdminGroupService::class)->grant($superAdmin);

        $duplicateRequest = \App\Http\Requests\Admin\RoleRequest::create('/settings/roles', 'POST', [
            'name' => 'conformance-global-role',
            'permissions' => [],
        ]);
        $duplicateRequest->setUserResolver(static fn (): User => $superAdmin);
        $duplicateRequest->setContainer(app());
        $duplicateRequest->setRedirector(app('redirect'));

        $assert(
            Validator::make($duplicateRequest->all(), $duplicateRequest->rules())->fails(),
            'The role form must reject a duplicate global name.',
        );

        $builtInRequest = \App\Http\Requests\Admin\RoleRequest::create('/settings/roles', 'POST', [
            'name' => RoleEnum::Admin->value,
            'permissions' => [],
        ]);
        $builtInRequest->setUserResolver(static fn (): User => $superAdmin);
        $builtInRequest->setContainer(app());
        $builtInRequest->setRedirector(app('redirect'));

        $assert(
            Validator::make($builtInRequest->all(), $builtInRequest->rules())->fails(),
            'The role form must reject a built-in role name.',
        );
    },

    'assertApiKeyActivityUuid' => static function () use ($assert, $sendRequest, $decode): void {
        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
        Grant::factory()->create([
            'principal_id' => $principal->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'role' => App\Brain\Enums\RoleEnum::Owner,
            'max_visibility' => VisibilityEnum::Restricted,
        ]);

        config([
            'instituta.api.write_enabled' => true,
            'instituta.mcp.write_enabled' => true,
        ]);

        $issued = app(CreateApiKeyAction::class)->execute(
            principal: $principal,
            name: 'conformance-write',
            scopeType: ScopeTypeEnum::Organisation,
            scopeId: $organisation->id,
            role: App\Brain\Enums\RoleEnum::Owner,
            maxVisibility: VisibilityEnum::Restricted,
            scopes: [ApiKeyScopeEnum::BrainRead->value, ApiKeyScopeEnum::BrainWrite->value],
        );
        $key = $issued['key'];

        $response = $sendRequest('POST', '/api/v1/proposals', [
            'action' => 'create',
            'stable_key' => 'rules/conformance',
            'title' => 'Conformance proposal',
            'path' => 'rules/conformance.md',
            'area' => 'rules',
            'body' => '# Conformance',
        ], ['Authorization' => 'Bearer '.$issued['plain']]);

        $assert($response->getStatusCode() === 201, 'The API-key-authenticated write must succeed.');

        $activity = Activity::query()
            ->where('description', 'proposal.opened')
            ->where('subject_id', $decode($response)['id'])
            ->firstOrFail();

        $assert($activity->properties?->get('api_key_id') === $key->id, 'Activity properties.api_key_id must hold the public UUID.');
        $assert(is_string($activity->subject_id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $activity->subject_id) === 1, 'Activity morph ids must be public UUID strings.');

        $contractCall = ContractCall::query()->where('api_key_id', $key->id)->firstOrFail();
        $assert(is_string($contractCall->id) && strlen($contractCall->id) === 36, 'Contract call ids must be UUIDs.');
    },

    'assertErasure' => static function () use ($assert): void {
        $email = 'erasure-conformance@example.test';
        $username = 'erasure_conformance';
        $firstName = 'Erasure';
        $lastName = 'Conformance';
        $ip = '203.0.113.77';

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'username' => $username,
            'last_login_ip' => $ip,
        ]);
        $principal = Principal::factory()->create([
            'user_id' => $user->id,
            'name' => $firstName.' '.$lastName,
        ]);
        $apiKey = ApiKey::factory()->create([
            'owner_principal_id' => $principal->id,
            'created_by' => $user->id,
            'name' => $firstName.' key',
        ]);

        DB::table('sessions')->insert([
            'id' => 'sess-erasure-conformance',
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => 'token',
            'created_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['email' => $email, 'name' => $firstName.' '.$lastName]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        activity()->causedBy($user)->performedOn($user)->withProperties([
            'attributes' => ['email' => $email, 'first_name' => $firstName, 'last_login_ip' => $ip],
            'old' => ['email' => 'old@example.test', 'first_name' => 'Old'],
        ])->log('c17 user row');

        activity()->performedOn($principal)->withProperties([
            'attributes' => ['name' => $firstName.' '.$lastName],
            'old' => ['name' => 'Old name'],
        ])->log('c17 principal row');

        activity()->performedOn($apiKey)->withProperties([
            'attributes' => ['name' => $firstName.' key'],
            'old' => ['name' => 'Old key'],
        ])->log('c17 key row');

        $databaseKey = $user->getKey();
        $publicId = $user->public_id;
        $principalId = $principal->id;
        $apiKeyId = $apiKey->id;

        app(DeleteUserAction::class)->execute($user);

        $erasedUser = User::withTrashed()->findOrFail($databaseKey);
        $assert($erasedUser->trashed(), 'Erasure must soft-delete the user.');
        $assert($erasedUser->email === 'deleted+'.$publicId.'@example.invalid', 'Erasure must replace the email with a unique placeholder.');
        $assert($erasedUser->username === 'deleted_'.substr(str_replace('-', '', $publicId), 0, 24), 'Erasure must replace the username with a unique placeholder.');
        $assert($erasedUser->first_name === 'Deleted', 'Erasure must anonymise the first name.');
        $assert($erasedUser->last_name === null, 'Erasure must anonymise the last name.');

        $erasedPrincipal = Principal::query()->findOrFail($principalId);
        $assert($erasedPrincipal->user_id === null, 'Erasure must detach the user from its principals.');
        $assert($erasedPrincipal->deactivated_at !== null, 'Erasure must deactivate principals.');

        $erasedKey = ApiKey::query()->findOrFail($apiKeyId);
        $assert($erasedKey->revoked_at !== null, 'Erasure must revoke keys owned by deactivated principals.');

        $needles = [$email, $username, $firstName, $lastName, $ip];
        $driver = DB::connection()->getDriverName();
        foreach (Schema::getTables() as $table) {
            $tableName = $table['name'] ?? $table['name'] ?? null;
            if (! is_string($tableName) || $tableName === '') {
                continue;
            }

            foreach (Schema::getColumns($tableName) as $column) {
                $columnName = $column['name'] ?? null;
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
                if (! is_string($columnName) || $columnName === '') {
                    continue;
                }

                $isText = str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'json') || $type === 'clob';
                if (! $isText) {
                    continue;
                }

                foreach ($needles as $needle) {
                    $query = DB::table($tableName);
                    $wrapped = $query->getGrammar()->wrap($columnName);
                    if (str_contains($type, 'json')) {
                        $expression = match ($driver) {
                            'pgsql' => $wrapped.'::text',
                            'mysql', 'mariadb' => 'CAST('.$wrapped.' AS CHAR)',
                            default => $wrapped,
                        };
                        $exists = $query->whereRaw($expression.' LIKE ?', ['%'.$needle.'%'])->exists();
                    } else {
                        $exists = $query->where($columnName, 'like', '%'.$needle.'%')->exists();
                    }

                    $assert(! $exists, "Erasure left {$needle} in {$tableName}.{$columnName}.");
                }
            }
        }

        $replacement = User::factory()->create([
            'email' => $email,
            'username' => $username,
        ]);
        $assert($replacement->email === $email, 'Erasure must release the original email.');
        $assert($replacement->username === $username, 'Erasure must release the original username.');
    },

    'assertInitialAdminSeeder' => static function () use ($assert): void {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        config([
            'super_admin.first_name' => 'Super',
            'super_admin.last_name' => 'Admin',
            'super_admin.email' => 'conformance-superadmin@example.test',
            'super_admin.password' => null,
        ]);
        app()->detectEnvironment(static fn (): string => 'production');
        app(CreateSuperAdminSeeder::class)->run(app(InitialSuperAdminPasswordService::class));
        $assert(User::query()->count() === 0, 'The seeder must skip when no explicit password is configured.');

        config([
            'super_admin.password' => 'password',
        ]);
        app(CreateSuperAdminSeeder::class)->run(app(InitialSuperAdminPasswordService::class));
        $assert(User::query()->count() === 0, 'The seeder must skip the known default password outside local/testing.');

        config([
            'super_admin.password' => 'Fleet-Secret-9x',
        ]);
        Password::defaults(static fn (): Password => Password::min(8)->mixedCase()->numbers());
        app(CreateSuperAdminSeeder::class)->run(app(InitialSuperAdminPasswordService::class));

        $superAdmin = User::query()->where('email', 'conformance-superadmin@example.test')->sole();
        $assert(
            app(EffectivePermissionResolverService::class)->isSuperAdmin($superAdmin),
            'The seeder must grant instance super-admin (group membership).',
        );
        $assert($superAdmin->is_active, 'The seeder must create an active user.');
        $assert($superAdmin->email_verified_at !== null, 'The seeder must create a verified user.');
        $assert(Hash::check('Fleet-Secret-9x', $superAdmin->password), 'The seeder must hash the explicit password.');

        $superAdmin->forceFill(['first_name' => 'Changed Locally'])->save();
        app(CreateSuperAdminSeeder::class)->run(app(InitialSuperAdminPasswordService::class));
        $assert($superAdmin->fresh()->first_name === 'Changed Locally', 'The seeder must never modify an existing user.');
    },

    'assertErrorContract' => static function () use ($assert, $sendRequest, $decode): void {
        config(['app.debug' => false]);

        $unauthenticated = $sendRequest('GET', '/api/v1/scopes');
        $assert($unauthenticated->getStatusCode() === 401, 'An unauthenticated API call must answer 401.');
        $assert(str_contains((string) $unauthenticated->headers->get('content-type'), 'application/json'), 'API errors must be JSON without Accept.');
        $assert(($decode($unauthenticated)['error'] ?? null) === ContractErrorCodeEnum::Unauthenticated->value, '401 must carry the documented error code.');

        $notFound = $sendRequest('GET', '/api/v1/does-not-exist');
        $assert($notFound->getStatusCode() === 404, 'A missing API route must answer 404.');
        $assert(($decode($notFound)['error'] ?? null) === ContractErrorCodeEnum::NotFound->value, '404 must carry the documented error code.');
        $assert(! str_contains((string) $notFound->getContent(), 'trace'), 'A production error must not expose a stack trace.');

        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
        Grant::factory()->create([
            'principal_id' => $principal->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'role' => App\Brain\Enums\RoleEnum::Owner,
            'max_visibility' => VisibilityEnum::Restricted,
        ]);
        $plainKey = app(CreateApiKeyAction::class)->execute(
            principal: $principal,
            name: 'conformance-errors',
            scopeType: ScopeTypeEnum::Organisation,
            scopeId: $organisation->id,
            role: App\Brain\Enums\RoleEnum::Owner,
            maxVisibility: VisibilityEnum::Restricted,
            scopes: [ApiKeyScopeEnum::BrainRead->value],
        )['plain'];

        $validation = $sendRequest('GET', '/api/v1/search', [], ['Authorization' => 'Bearer '.$plainKey]);
        $assert($validation->getStatusCode() === 422, 'Validation must answer 422.');
        $validationBody = $decode($validation);
        $assert(($validationBody['error'] ?? null) === ContractErrorCodeEnum::ValidationFailed->value, '422 must carry the documented error code.');
        $assert(isset($validationBody['errors']['q']), '422 must keep the field errors.');

        $mcp = $sendRequest('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);
        $assert(str_contains((string) $mcp->headers->get('content-type'), 'application/json'), '/mcp must answer JSON without Accept.');
    },

    'assertPaginationShape' => static function () use ($assert, $sendRequest, $decode): void {
        $organisation = Organisation::factory()->create();
        $principal = Principal::factory()->create(['organisation_id' => $organisation->id]);
        Grant::factory()->create([
            'principal_id' => $principal->id,
            'scope_type' => ScopeTypeEnum::Organisation,
            'scope_id' => $organisation->id,
            'role' => App\Brain\Enums\RoleEnum::Owner,
            'max_visibility' => VisibilityEnum::Restricted,
        ]);

        $source = \App\Models\Source::factory()->create(['organisation_id' => $organisation->id]);
        \App\Models\Item::factory()->count(2)->create([
            'source_id' => $source->id,
            'organisation_id' => $organisation->id,
            'company_id' => null,
            'product_id' => null,
        ]);

        $issued = app(CreateApiKeyAction::class)->execute(
            principal: $principal,
            name: 'conformance-list',
            scopeType: ScopeTypeEnum::Organisation,
            scopeId: $organisation->id,
            role: App\Brain\Enums\RoleEnum::Owner,
            maxVisibility: VisibilityEnum::Restricted,
            scopes: [ApiKeyScopeEnum::BrainRead->value],
        );

        $response = $sendRequest('GET', '/api/v1/items?scope='.rawurlencode($organisation->id).'&limit=1', [], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$issued['plain'],
        ]);

        $assert($response->getStatusCode() === 200, 'The paginated API list must answer 200.');

        $body = $decode($response);
        $assert(array_key_exists('items', $body), 'The API list must return its collection.');
        $assert(array_key_exists('next_cursor', $body), 'The API list must return a next_cursor key.');
        $assert(is_string($body['next_cursor']) && $body['next_cursor'] !== '', 'A limited list with more rows must return a next_cursor.');

        $second = $sendRequest('GET', '/api/v1/items?scope='.rawurlencode($organisation->id).'&limit=1&cursor='.rawurlencode((string) $body['next_cursor']), [], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$issued['plain'],
        ]);

        $assert($second->getStatusCode() === 200, 'The paginated API list must answer 200 for the next cursor.');
        $secondBody = $decode($second);
        $assert(array_key_exists('items', $secondBody), 'The next page must return its collection.');
        $assert(array_key_exists('next_cursor', $secondBody), 'The next page must return a next_cursor key.');

        $assert(array_key_exists('data', $body) && array_key_exists('links', $body) && array_key_exists('meta', $body), 'Paginated API lists must use the data/links/meta envelope shape.');
    },

    'assertThrottleHeaders' => static function () use ($assert, $sendRequest): void {
        config(['instituta.mcp.rate_limit_per_minute' => 1]);
        RateLimiter::clear('instituta-api:127.0.0.1');

        $first = $sendRequest('GET', '/api/v1/openapi.json');
        $assert($first->getStatusCode() === 200, 'The first API call must succeed.');
        $assert($first->headers->has('X-RateLimit-Limit'), 'Throttled responses must carry X-RateLimit-Limit.');
        $assert($first->headers->has('X-RateLimit-Remaining'), 'Throttled responses must carry X-RateLimit-Remaining.');

        $second = $sendRequest('GET', '/api/v1/openapi.json');
        $assert($second->getStatusCode() === 429, 'The second API call must be throttled.');
        $assert($second->headers->has('Retry-After'), 'A 429 must carry Retry-After.');
    },

    'assertSuperAdmin' => static function () use ($assert): void {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $assert(Organisation::query()->count() === 0, 'The super-admin check requires an empty organisation table.');

        $user = User::factory()->create();
        app(InstanceSuperAdminGroupService::class)->grant($user);
        $resolver = app(EffectivePermissionResolverService::class);

        $assert(Gate::forUser($user)->allows('non-existent-ability'), 'A super-admin must pass every gate.');
        $assert($resolver->isSuperAdmin($user), 'The resolver must recognise the instance super-admin.');
        $assert($resolver->permissionsFor($user) === PermissionEnum::values(), 'A super-admin must hold every permission.');
        $assert(app(SuperAdminGuardService::class)->isLastSuperAdmin($user), 'The only super-admin must be protected.');

        $superAdminRoleId = Role::findByName(RoleEnum::SuperAdmin->value)->getKey();
        $hasTeamPivot = DB::table('model_has_roles')
            ->where('role_id', $superAdminRoleId)
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->exists();
        $assert(! $hasTeamPivot, 'Instance super-admin must not be a Spatie team assignment.');
    },

    'assertTeamSwitch' => static function () use ($assert): void {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $organisationA = Organisation::factory()->create();
        $organisationB = Organisation::factory()->create();
        $user = User::factory()->create();
        $teamContext = app(PermissionsTeamContextService::class);

        $teamContext->setOrganisation($organisationA->id, $user);
        $user->assignRole(RoleEnum::Admin->value);
        $assert($user->hasRole(RoleEnum::Admin->value), 'Admin role must be visible in organisation A.');

        $teamContext->setOrganisation($organisationB->id, $user);
        $assert(! $user->hasRole(RoleEnum::Admin->value), 'Admin role from organisation A must not be visible in organisation B.');
        $assert(
            ! Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value),
            'Permissions from organisation A must not apply in organisation B.',
        );

        $teamContext->setOrganisation($organisationA->id, $user);
        $assert($user->hasRole(RoleEnum::Admin->value), 'Switching back to organisation A must restore the Admin role.');
        $assert(
            Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value),
            'Permissions from organisation A must apply again after switching back.',
        );

        // Registrar helpers used by products must match the service.
        setPermissionsTeamId($organisationB->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $assert(! $user->hasRole(RoleEnum::Admin->value), 'setPermissionsTeamId must isolate roles across organisations.');
    },

    'assertApiResourcesHideBigints' => static function () use ($assert, $resolveResource): void {
        $user = User::factory()->create();
        $userData = $resolveResource(new UserResource($user));
        $assert($userData['id'] === $user->public_id, 'UserResource must expose the public UUID as its id.');
        $assert($userData['id'] !== $user->id, 'UserResource must not expose the internal user id.');

        $activity = activity()->causedBy($user)->performedOn($user)->log('conformance resource');
        $activityData = $resolveResource(new ActivityLogResource($activity));
        $assert($activityData['id'] === $activity->public_id, 'ActivityLogResource must expose the public UUID as its id.');

        $contractCall = ContractCall::factory()->create();
        $contractCallData = $resolveResource(new ContractCallReplayResource($contractCall));
        foreach (['id', 'scope_id', 'organisation_id', 'agent_session_id'] as $field) {
            $value = $contractCallData[$field] ?? null;
            $assert($value === null || is_string($value), "Contract call {$field} must be a public UUID or null.");
        }
    },

    'assertEncryptedCasts' => static function () use ($assert): void {
        $source = Source::factory()->create(['webhook_secret' => 'plain-webhook-secret']);
        $rawSecret = DB::table('sources')->where('id', $source->id)->value('webhook_secret');
        $assert($rawSecret !== 'plain-webhook-secret', 'Webhook secrets must be encrypted at rest.');
        $assert($source->fresh()->webhook_secret === 'plain-webhook-secret', 'The encrypted cast must decrypt webhook secrets.');

        $user = User::factory()->withTwoFactor()->create();
        $rawTwoFactorSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $assert($rawTwoFactorSecret !== null && $rawTwoFactorSecret !== 'secret', 'Two-factor secrets must be encrypted at rest.');
        $assert(Crypt::decrypt($rawTwoFactorSecret) === 'secret', 'Fortify two-factor secrets must decrypt.');

        $plainToken = 'ins_test_'.str_repeat('a', 32);
        $apiKey = ApiKey::factory()->create(['key_hash' => hash('sha256', $plainToken)]);
        $assert($apiKey->key_hash === hash('sha256', $plainToken), 'API keys must keep a plain SHA-256 hash.');
        $assert(is_array(config('app.previous_keys')), 'APP_PREVIOUS_KEYS must be configurable for encryption key rotation.');
    },
];
