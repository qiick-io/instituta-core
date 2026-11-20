<?php

declare(strict_types=1);

namespace App\Models;

use App\Brain\Enums\ScopeTypeEnum;
use Database\Factories\ContractCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit / replay log row for a contract call (never stores content or secrets).
 *
 * @property string $id
 * @property string|null $principal_id
 * @property string|null $api_key_id
 * @property string|null $agent_session_id
 * @property string $tool_or_endpoint
 * @property ScopeTypeEnum $scope_type
 * @property string $scope_id
 * @property string|null $organisation_id
 * @property string|null $company_id
 * @property string|null $product_id
 * @property list<string>|null $item_ids
 * @property int|null $token_estimate
 * @property Carbon $called_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'principal_id',
    'api_key_id',
    'agent_session_id',
    'tool_or_endpoint',
    'scope_type',
    'scope_id',
    'organisation_id',
    'company_id',
    'product_id',
    'item_ids',
    'token_estimate',
    'called_at',
])]
class ContractCall extends Model
{
    /** @use HasFactory<ContractCallFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeTypeEnum::class,
            'item_ids' => 'array',
            'token_estimate' => 'integer',
            'called_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * @return BelongsTo<AgentSession, $this>
     */
    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
