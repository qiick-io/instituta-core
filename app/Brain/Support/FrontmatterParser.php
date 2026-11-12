<?php

declare(strict_types=1);

namespace App\Brain\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FrontmatterParser
{
    /**
     * @return array{frontmatter: array<string, mixed>, body: string, parse_error: bool}
     */
    public function parse(string $contents): array
    {
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $contents, $matches)) {
            return [
                'frontmatter' => [],
                'body' => $contents,
                'parse_error' => false,
            ];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (ParseException) {
            return [
                'frontmatter' => [],
                'body' => $contents,
                'parse_error' => true,
            ];
        }

        if (! is_array($parsed)) {
            return [
                'frontmatter' => [],
                'body' => $matches[2],
                'parse_error' => true,
            ];
        }

        /** @var array<string, mixed> $parsed */
        return [
            'frontmatter' => $parsed,
            'body' => $matches[2],
            'parse_error' => false,
        ];
    }
}
