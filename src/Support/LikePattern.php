<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Support;

final class LikePattern
{
    public const ESCAPE_CHARACTER = '!';

    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }

    public static function prefix(string $value): string
    {
        return self::escape($value).'%';
    }

    public static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [self::ESCAPE_CHARACTER.self::ESCAPE_CHARACTER, self::ESCAPE_CHARACTER.'%', self::ESCAPE_CHARACTER.'_'],
            $value
        );
    }
}
