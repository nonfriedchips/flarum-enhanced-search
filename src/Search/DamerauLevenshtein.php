<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use NonFriedChips\EnhancedSearch\Support\UnicodeText;

/**
 * Unicode-aware optimal-string-alignment distance.
 *
 * It treats one adjacent transposition as a single edit, matching the typo
 * behaviour users expect for input such as "flarmu" -> "flarum".
 */
final class DamerauLevenshtein
{
    private UnicodeText $text;

    public function __construct(UnicodeText $text)
    {
        $this->text = $text;
    }

    public function distance(string $left, string $right, ?int $maximum = null): int
    {
        $a = $this->text->characters($left);
        $b = $this->text->characters($right);
        $aLength = count($a);
        $bLength = count($b);

        if ($aLength === 0) {
            return $maximum !== null && $bLength > $maximum ? $maximum + 1 : $bLength;
        }

        if ($bLength === 0) {
            return $maximum !== null && $aLength > $maximum ? $maximum + 1 : $aLength;
        }

        if ($maximum !== null && abs($aLength - $bLength) > $maximum) {
            return $maximum + 1;
        }

        $limit = $maximum ?? max($aLength, $bLength);
        $infinity = $limit + 1;
        $previous = array_fill(0, $bLength + 1, $infinity);

        for ($j = 0; $j <= min($bLength, $limit); $j++) {
            $previous[$j] = $j;
        }

        $previousPrevious = null;

        for ($i = 1; $i <= $aLength; $i++) {
            $current = array_fill(0, $bLength + 1, $infinity);

            if ($i <= $limit) {
                $current[0] = $i;
            }

            $start = max(1, $i - $limit);
            $end = min($bLength, $i + $limit);

            for ($j = $start; $j <= $end; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost
                );

                if (
                    $i > 1
                    && $j > 1
                    && is_array($previousPrevious)
                    && $a[$i - 1] === $b[$j - 2]
                    && $a[$i - 2] === $b[$j - 1]
                ) {
                    $current[$j] = min($current[$j], $previousPrevious[$j - 2] + 1);
                }
            }

            $previousPrevious = $previous;
            $previous = $current;
        }

        $distance = $previous[$bLength];

        return $maximum !== null && $distance > $maximum ? $maximum + 1 : $distance;
    }
}
