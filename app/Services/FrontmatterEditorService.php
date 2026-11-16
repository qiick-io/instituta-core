<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\FrontmatterParser;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Applies a proposal's frontmatter changes to a brain file (#219): set keys, `null` removes one.
 * The body stays byte for byte. Known keys are checked against the format (`docs/FORMAT.md`
 * §4.2); other keys must be strings, numbers, booleans or lists of strings.
 */
final class FrontmatterEditorService
{
    private const array DATE_KEYS = ['decision_date', 'effective_date', 'review_date'];

    private const array STRING_KEYS = ['title', 'summary', 'superseded_by', 'resolution_key', 'type', 'area', 'permalink', 'key'];

    public function __construct(private readonly FrontmatterParser $parser = new FrontmatterParser) {}

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws InvalidArgumentException When a key or value does not fit the format.
     */
    public function assertValid(array $changes): void
    {
        if ($changes === []) {
            throw new InvalidArgumentException('frontmatter must set or remove at least one key.');
        }

        foreach ($changes as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', (string) $key) !== 1) {
                throw new InvalidArgumentException("frontmatter key \"{$key}\" must be snake_case.");
            }

            if ($value === null) {
                continue;
            }

            $isValid = match (true) {
                $key === 'status' => is_string($value) && ItemStatusEnum::tryFrom($value) !== null,
                $key === 'visibility' => is_string($value) && VisibilityEnum::tryFrom($value) !== null,
                in_array($key, self::DATE_KEYS, true) => is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1,
                in_array($key, self::STRING_KEYS, true) => is_string($value) && trim($value) !== '',
                $key === 'tags' => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
                default => is_scalar($value) || (is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value),
            };

            if (! $isValid) {
                throw new InvalidArgumentException("frontmatter \"{$key}\" has an invalid value.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function apply(string $contents, array $changes): string
    {
        $parsed = $this->parser->parse($contents);
        $frontmatter = $parsed['frontmatter'];

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($frontmatter[$key]);
            } else {
                $frontmatter[$key] = $value;
            }
        }

        $body = $parsed['body'];

        if ($frontmatter === []) {
            return ltrim($body, "\r\n");
        }

        $yaml = Yaml::dump($frontmatter, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        return "---\n".$yaml."---\n".$body;
    }
}
