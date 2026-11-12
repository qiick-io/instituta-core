<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VaultVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Organisation vault pin (`instituta@vN`) with per-source commits.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $label
 * @property array<string, string> $source_commits
 * @property string|null $signature
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organisation_id', 'label', 'source_commits', 'signature'])]
class VaultVersion extends Model
{
    /** @use HasFactory<VaultVersionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_commits' => 'array',
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
     * @return HasMany<ContextFreeze, $this>
     */
    public function contextFreezes(): HasMany
    {
        return $this->hasMany(ContextFreeze::class);
    }

    /**
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }
}
