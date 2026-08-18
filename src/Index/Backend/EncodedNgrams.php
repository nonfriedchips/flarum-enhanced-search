<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class EncodedNgrams
{
    public const TOKEN_LENGTH = 16;

    private const PREFIX = 'bgrm';
    private const WORD_BOUNDARY = 'bgrmwordboundary';

    private UnicodeText $text;

    public function __construct(UnicodeText $text)
    {
        $this->text = $text;
    }

    public function document(string $normalized): string
    {
        $terms = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($terms as $position => $term) {
            if ($position > 0) {
                // Keep Boolean phrases from matching across a normalized word
                // boundary after the source text has been encoded as tokens.
                $tokens[] = self::WORD_BOUNDARY;
            }

            array_push($tokens, ...$this->termTokens($term));
        }

        return implode(' ', $tokens);
    }

    public function query(string $normalized): string
    {
        return implode(' ', $this->tokens($normalized, true));
    }

    /** @param string[] $variants */
    public function phraseQuery(array $variants): string
    {
        $phrases = [];

        foreach ($variants as $variant) {
            $tokens = $this->tokens($variant, false);

            if ($tokens !== []) {
                $phrases['"'.implode(' ', $tokens).'"'] = true;
            }
        }

        return implode(' ', array_keys($phrases));
    }

    /** @return string[] */
    public function tokens(string $normalized, bool $unique): array
    {
        $terms = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($terms as $term) {
            foreach ($this->termTokens($term) as $token) {
                if ($unique) {
                    $tokens[$token] = true;
                } else {
                    $tokens[] = $token;
                }
            }
        }

        return $unique ? array_keys($tokens) : $tokens;
    }

    /** @return string[] */
    private function termTokens(string $term): array
    {
        $characters = $this->text->characters($term);
        $tokens = [];

        for ($position = 0, $last = count($characters) - 1; $position < $last; $position++) {
            $left = mb_ord($characters[$position], 'UTF-8');
            $right = mb_ord($characters[$position + 1], 'UTF-8');

            if ($left === false || $right === false) {
                continue;
            }

            $tokens[] = self::PREFIX.sprintf('%06x%06x', $left, $right);
        }

        return $tokens;
    }
}
