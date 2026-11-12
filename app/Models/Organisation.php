<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Top-level tenancy boundary (a solo person is still one organisation).
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name'])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasMany<ProductGroup, $this>
     */
    public function productGroups(): HasMany
    {
        return $this->hasMany(ProductGroup::class);
    }

    /**
     * @return HasMany<Principal, $this>
     */
    public function principals(): HasMany
    {
        return $this->hasMany(Principal::class);
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return HasMany<VaultVersion, $this>
     */
    public function vaultVersions(): HasMany
    {
        return $this->hasMany(VaultVersion::class);
    }
}
