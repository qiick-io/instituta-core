<?php

declare(strict_types=1);

namespace App\Brain\Scanners;

/**
 * Minimal secret / PII scanner. On a match, the file is skipped and only the
 * reason is recorded — never the matched substring.
 */
final class SecretPiiScanner
{
    /**
     * @var list<array{reason: string, pattern: string}>
     */
    private array $rules = [
        ['reason' => 'private_key', 'pattern' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/'],
        ['reason' => 'api_token', 'pattern' => '/\b(?:sk|pk|api|ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b/'],
        ['reason' => 'env_assignment', 'pattern' => '/^(?:export\s+)?[A-Z][A-Z0-9_]*(?:SECRET|TOKEN|PASSWORD|API_KEY)\s*=\s*\S+/m'],
        ['reason' => 'password_in_url', 'pattern' => '/[a-z][a-z0-9+.-]*:\/\/[^\/\s]+:[^\/\s]+@/i'],
        ['reason' => 'email_address', 'pattern' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i'],
        ['reason' => 'phone_number', 'pattern' => '/(?<!\w)(?:\+\d{1,3}[\s.-])?(?:\(?\d{2,4}\)?[\s.-])\d{3,4}[\s.-]\d{3,4}(?!\w)/'],
    ];

    /**
     * Path hygiene rules (basename / path segment).
     *
     * @var list<string>
     */
    private array $pathRules = [
        'vendor/',
        'node_modules/',
        '.claude/',
        '.git/',
        '.env',
        '.env.local',
        '.env.production',
    ];

    public function skipReasonForPath(string $relativePath): ?string
    {
        $normalized = str_replace('\\', '/', strtolower($relativePath));

        foreach ($this->pathRules as $rule) {
            if ($normalized === rtrim($rule, '/') || str_contains($normalized, $rule) || str_ends_with($normalized, '/'.rtrim($rule, '/'))) {
                return 'path_hygiene:'.$rule;
            }
        }

        $basename = basename($normalized);
        if (str_starts_with($basename, '.') && str_contains($basename, 'env')) {
            return 'path_hygiene:.env';
        }

        return null;
    }

    /**
     * Returns a stable reason code when content looks like a secret / PII, else null.
     * The matched text itself is never returned.
     */
    public function skipReasonForContent(string $contents): ?string
    {
        foreach ($this->rules as $rule) {
            if (preg_match($rule['pattern'], $contents) === 1) {
                return 'secret_pii:'.$rule['reason'];
            }
        }

        return null;
    }
}
