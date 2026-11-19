<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared username normalization and validation for every surface that writes a user.
 *
 * All writers must use the same canonical form: trimmed, lowercase, and null when blank.
 * Validation and the database unique key then agree on what counts as a duplicate.
 */
trait UsernameValidationRules
{
    /**
     * Normalize a submitted username before validation or persistence.
     */
    protected static function normalizeUsername(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $username = Str::lower(trim($value));

        return $username === '' ? null : $username;
    }

    /**
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected static function usernameRules(?int $ignoreUserId = null): array
    {
        $unique = Rule::unique(User::class, 'username');

        if ($ignoreUserId !== null) {
            $unique->ignore($ignoreUserId);
        }

        return [
            'nullable',
            'string',
            'min:3',
            'max:32',
            'regex:/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/',
            $unique,
        ];
    }
}
