<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable machine codes of contract errors (#227): agents branch on the code, never on the
 * message, which may be reworded or translated. Documented with the action to take in
 * `docs/CONTRACTS.md` §4. Values never change once released.
 */
enum ContractErrorCodeEnum: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case WriteDisabled = 'write_disabled';
    case AbilityMissing = 'ability_missing';
    case GuestRefused = 'guest_refused';
    case OutOfPerimeter = 'out_of_perimeter';
    case BaseRequired = 'base_required';
    case StaleBase = 'stale_base';
    case PathMismatch = 'path_mismatch';
    case ItemExists = 'item_exists';
    case SectionMissing = 'section_missing';
    case SectionNotSynced = 'section_not_synced';
    case InvalidChange = 'invalid_change';
    case InvalidFrontmatter = 'invalid_frontmatter';
    case ProposalNotOpen = 'proposal_not_open';
    case SelfAcceptRefused = 'self_accept_refused';
    case AgentAcceptRefused = 'agent_accept_refused';
    case QuotaExceeded = 'quota_exceeded';
    case RateLimited = 'rate_limited';
    case EmbeddingsDisabled = 'embeddings_disabled';
    case SearchUnsupported = 'search_unsupported';
    case RequestFailed = 'request_failed';

    /**
     * The code of an error response that did not set a more specific one.
     */
    public static function forStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            501 => self::SearchUnsupported,
            default => self::RequestFailed,
        };
    }

    /**
     * The MCP tool error text: `[error] message`, so a client without structured errors can still
     * read the machine error first.
     */
    public function mcpText(string $message): string
    {
        return '['.$this->value.'] '.$message;
    }
}
