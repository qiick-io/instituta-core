<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApiKey;
use App\Models\ContractCall;
use App\Models\Principal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialize a contract call for session replay (metadata only).
 *
 * @mixin ContractCall
 */
class ContractCallReplayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $principal = $this->relationLoaded('principal') ? $this->principal : null;
        $apiKey = $this->relationLoaded('apiKey') ? $this->apiKey : null;

        return [
            'id' => $this->id,
            'tool_or_endpoint' => $this->tool_or_endpoint,
            'scope_type' => $this->scope_type->value,
            'scope_id' => $this->scope_id,
            'organisation_id' => $this->organisation_id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'item_ids' => $this->item_ids ?? [],
            'token_estimate' => $this->token_estimate,
            'called_at' => $this->called_at->toIso8601String(),
            'principal' => $principal instanceof Principal ? [
                'id' => $principal->id,
                'name' => $principal->name,
            ] : null,
            'api_key' => $apiKey instanceof ApiKey ? [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'display_prefix' => $apiKey->display_prefix,
            ] : null,
            'agent_session_id' => $this->agent_session_id,
        ];
    }
}
