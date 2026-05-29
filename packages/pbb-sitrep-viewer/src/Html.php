<?php

namespace Pbb\Sitreps\Viewer;

final class Html
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function text(mixed $value, string $fallback = ''): string
    {
        $text = trim((string) $value);

        return self::escape($text !== '' ? $text : $fallback);
    }

    /**
     * @param array<int, mixed> $parts
     */
    public static function joined(array $parts, string $separator = ' · '): string
    {
        $filtered = array_values(array_filter(array_map(
            fn (mixed $part): string => trim((string) $part),
            $parts,
        ), fn (string $part): bool => $part !== ''));

        return self::escape(implode($separator, $filtered));
    }

    public static function number(mixed $value, string $fallback = '0'): string
    {
        if (is_int($value) || is_float($value)) {
            return self::escape((string) $value);
        }

        $text = trim((string) $value);

        return self::escape($text !== '' ? $text : $fallback);
    }
}
