<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Support;

use Normalizer;

final class UnicodeText
{
    public function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\p{M}_]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public function compact(string $value): string
    {
        return preg_replace('/\s+/u', '', $value) ?? '';
    }

    public function truncate(string $value, int $length): string
    {
        if ($length < 1 || $this->length($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length, 'UTF-8');
    }

    public function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    public function substring(string $value, int $start, ?int $length = null): string
    {
        return $length === null
            ? mb_substr($value, $start, null, 'UTF-8')
            : mb_substr($value, $start, $length, 'UTF-8');
    }

    /** @return string[] */
    public function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return string[] */
    public function adjacentTranspositions(string $value, int $maximumLength = 6): array
    {
        $characters = $this->characters($value);
        $length = count($characters);

        if ($length < 2 || $length > $maximumLength) {
            return [];
        }

        $variants = [];

        for ($position = 0; $position < $length - 1; $position++) {
            if ($characters[$position] === $characters[$position + 1]) {
                continue;
            }

            $swapped = $characters;
            [$swapped[$position], $swapped[$position + 1]] = [$swapped[$position + 1], $swapped[$position]];
            $variants[implode('', $swapped)] = true;
        }

        return array_keys($variants);
    }

    /** @return string[] */
    public function tokens(string $value, int $limit = 8): array
    {
        $tokens = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($tokens, 0, max(1, $limit));
    }

    public function isNumeric(string $value): bool
    {
        return $value !== '' && preg_match('/^\p{N}+$/u', $value) === 1;
    }
}
