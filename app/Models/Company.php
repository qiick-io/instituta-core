<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyKindEnum;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Company inside an organisation (`own` home company or a `client`).
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $slug
 * @property string $name
 * @property CompanyKindEnum $kind
 * @property string|null $logo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organisation_id', 'slug', 'name', 'kind', 'logo_path'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CompanyKindEnum::class,
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<OffboardingRequest, $this>
     */
    public function offboardingRequests(): HasMany
    {
        return $this->hasMany(OffboardingRequest::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwn(Builder $query): Builder
    {
        return $query->where('kind', CompanyKindEnum::Own);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClient(Builder $query): Builder
    {
        return $query->where('kind', CompanyKindEnum::Client);
    }
}
