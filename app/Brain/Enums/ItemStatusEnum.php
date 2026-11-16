<?php

declare(strict_types=1);

namespace App\Brain\Enums;

/**
 * Whether a brain entry is still valid (#218), from its frontmatter `status`. Agents prefer
 * `active` entries; the others stay readable but are marked, ranked lower and never win a
 * resolution over an active entry.
 */
enum ItemStatusEnum: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Superseded = 'superseded';

    /**
     * The status a file declares: `status` when it is a known value, `superseded` when only
     * `superseded_by` is set, `active` otherwise.
     *
     * @param  array<string, mixed>  $frontmatter
     */
    public static function fromFrontmatter(array $frontmatter): self
    {
        $declared = is_string($frontmatter['status'] ?? null) ? self::tryFrom(strtolower(trim($frontmatter['status']))) : null;

        if ($declared !== null) {
            return $declared;
        }

        $supersededBy = $frontmatter['superseded_by'] ?? null;

        return is_string($supersededBy) && trim($supersededBy) !== '' ? self::Superseded : self::Active;
    }

    public function isCurrent(): bool
    {
        return $this === self::Active;
    }
}
