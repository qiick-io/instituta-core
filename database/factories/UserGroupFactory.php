<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\UserGroup;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Organisation-scoped user groups by default. The instance super-admin group is created only
 * through {@see InstanceSuperAdminGroupService}.
 *
 * @extends Factory<UserGroup>
 */
class UserGroupFactory extends Factory
{
    protected $model = UserGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'is_instance' => false,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
