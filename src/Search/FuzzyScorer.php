<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class FuzzyScorer
{
    private const MAX_ANCHORED_WINDOWS = 80;
    private const MAX_OCCURRENCES_PER_BIGRAM = 16;

    private UnicodeText $text;
    private DamerauLevenshtein $distance;

    public function __construct(UnicodeText $text, DamerauLevenshtein $distance)
    {
        $this->text = $text;
        $this->distance = $distance;
    }

    /**
     * Return a deterministic quality score in the range 0..1.
     */
    public function score(QueryPlan $plan, string $field): float
    {
        if ($plan->isEmpty() || $field === '') {
            return 0.0;
        }

        $normalizedField = $this->text->normalize(
            $this->text->truncate($field, SearchOptions::MAX_INDEXED_POST_CHARACTERS)
        );

        return $this->scoreNormalized($plan, $normalizedField);
    }

    /**
     * Score text already normalized by DocumentIndexer.
     */
    public function scoreNormalized(QueryPlan $plan, string $normalizedField): float
    {
        if ($plan->isEmpty() || $normalizedField === '') {
            return 0.0;
        }

        $normalizedField = $this->text->truncate(
            $normalizedField,
            SearchOptions::MAX_INDEXED_POST_CHARACTERS
        );
        $compactField = $this->text->compact($normalizedField);

        if ($compactField === '') {
            return 0.0;
        }

        $wholeScore = $this->scoreTerm(
            $plan->compact(),
            $compactField,
            $this->maxEditsForWholeQuery($plan)
        );

        if (count($plan->terms()) === 1) {
            return $wholeScore;
        }

        $termScores = [];

        foreach ($plan->terms() as $term) {
            $termScore = $this->scoreTerm($term, $compactField, $plan->allowedEdits($term));

            if ($termScore <= 0.0) {
                return $wholeScore;
            }

            $termScores[] = $termScore;
        }

        // Separate token matches are slightly weaker than a contiguous phrase.
        $tokenScore = (array_sum($termScores) / count($termScores)) * 0.97;

        return max($wholeScore, $tokenScore);
    }

    private function maxEditsForWholeQuery(QueryPlan $plan): int
    {
        // Multi-word typo tolerance is evaluated per term below. Running a
        // second approximate scan over the concatenated phrase is both less
        // precise and disproportionately expensive for long post bodies.
        if (count($plan->terms()) > 1) {
            return 0;
        }

        $maximum = 0;

        foreach ($plan->terms() as $term) {
            $maximum += $plan->allowedEdits($term);
        }

        return min(2, $maximum);
    }

    private function scoreTerm(string $term, string $field, int $maximumEdits): float
    {
        if ($term === '' || $field === '') {
            return 0.0;
        }

        if ($field === $term) {
            return 1.0;
        }

        $position = mb_strpos($field, $term, 0, 'UTF-8');

        if ($position === 0) {
            return 0.98;
        }

        if ($position !== false) {
            return 0.95;
        }

        if ($maximumEdits < 1) {
            return 0.0;
        }

        $match = $this->bestApproximateWindow($term, $field, $maximumEdits);

        if ($match === null) {
            return 0.0;
        }

        [$edits, $startsAtBeginning] = $match;
        $score = $edits === 1 ? 0.82 : 0.68;

        return min(0.90, $score + ($startsAtBeginning ? 0.02 : 0.0));
    }

    /**
     * @return array{0: int, 1: bool}|null
     */
    private function bestApproximateWindow(string $term, string $field, int $maximumEdits): ?array
    {
        $termLength = $this->text->length($term);
        $fieldLength = $this->text->length($field);

        if ($termLength < 2 || $fieldLength === 0) {
            return null;
        }

        $seenStarts = [];
        $windowsChecked = 0;
        $bestDistance = $maximumEdits + 1;
        $bestStart = -1;

        $this->evaluateStart(
            $term,
            $field,
            0,
            $termLength,
            $fieldLength,
            $maximumEdits,
            $bestDistance,
            $bestStart
        );
        $seenStarts[0] = true;
        $windowsChecked++;

        if ($bestDistance === 1) {
            return [1, true];
        }

        // A short middle transposition can have no common bigram at all.
        // Check its bounded adjacent-swap forms directly before relying on
        // shared-bigram anchors to locate approximate windows.
        if ($maximumEdits >= 1 && $termLength <= 6) {
            foreach ($this->text->adjacentTranspositions($term) as $variant) {
                $matchPosition = mb_strpos($field, $variant, 0, 'UTF-8');

                if ($matchPosition !== false) {
                    return [1, $matchPosition === 0];
                }
            }
        }

        $bigramCount = max(0, $termLength - 1);

        for ($offset = 0; $offset < $bigramCount; $offset++) {
            $bigram = $this->text->substring($term, $offset, 2);
            $searchOffset = 0;
            $occurrences = 0;

            while ($occurrences < self::MAX_OCCURRENCES_PER_BIGRAM) {
                $position = mb_strpos($field, $bigram, $searchOffset, 'UTF-8');

                if ($position === false) {
                    break;
                }

                for ($shift = -$maximumEdits; $shift <= $maximumEdits; $shift++) {
                    $start = max(0, $position - $offset + $shift);

                    if (isset($seenStarts[$start])) {
                        continue;
                    }

                    $seenStarts[$start] = true;
                    $windowsChecked++;
                    $this->evaluateStart(
                        $term,
                        $field,
                        $start,
                        $termLength,
                        $fieldLength,
                        $maximumEdits,
                        $bestDistance,
                        $bestStart
                    );

                    if ($bestDistance === 1) {
                        return [1, $bestStart === 0];
                    }

                    if ($windowsChecked >= self::MAX_ANCHORED_WINDOWS) {
                        break 3;
                    }
                }

                $searchOffset = $position + 1;
                $occurrences++;
            }
        }

        if ($bestDistance > $maximumEdits) {
            return null;
        }

        return [$bestDistance, $bestStart === 0];
    }

    private function evaluateStart(
        string $term,
        string $field,
        int $start,
        int $termLength,
        int $fieldLength,
        int $maximumEdits,
        int &$bestDistance,
        int &$bestStart
    ): void {
        if ($start >= $fieldLength) {
            return;
        }

        $minimumLength = max(1, $termLength - $maximumEdits);
        $maximumLength = min($fieldLength - $start, $termLength + $maximumEdits);

        for ($length = $minimumLength; $length <= $maximumLength; $length++) {
            $window = $this->text->substring($field, $start, $length);
            $distance = $this->distance->distance($term, $window, $maximumEdits);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestStart = $start;
            }

            if ($bestDistance === 1) {
                return;
            }
        }
    }
}
