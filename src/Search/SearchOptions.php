<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Settings\SettingsRepositoryInterface;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class SearchOptions
{
    public const PREFIX = 'nonfriedchips-enhanced-search.';

    public const MAX_QUERY_CHARACTERS = 64;
    public const MAX_QUERY_TERMS = 8;
    public const MAX_FUZZY_TERM_CHARACTERS = 24;
    public const MAX_INDEXED_POST_CHARACTERS = 12000;
    public const NGRAM_TOKEN_SIZE = 2;
    public const MAX_CANDIDATES = 300;

    public bool $typoTolerance;
    public bool $searchPostContent;
    public int $oneTypoLength;
    public int $twoTypoLength;
    public int $nativeResultThreshold;
    public int $candidateLimit;
    public int $suggestionMinLength;
    public bool $indexDirty;

    public function __construct(
        bool $typoTolerance = true,
        bool $searchPostContent = true,
        int $oneTypoLength = 4,
        int $twoTypoLength = 8,
        int $nativeResultThreshold = 15,
        int $candidateLimit = 200,
        int $suggestionMinLength = 2,
        bool $indexDirty = false
    ) {
        $this->typoTolerance = $typoTolerance;
        $this->searchPostContent = $searchPostContent;
        $this->oneTypoLength = self::clamp($oneTypoLength, 3, 32);
        $this->twoTypoLength = self::clamp($twoTypoLength, max(5, $this->oneTypoLength), 64);
        $this->nativeResultThreshold = self::clamp($nativeResultThreshold, 1, 100);
        $this->candidateLimit = self::clamp($candidateLimit, 20, self::MAX_CANDIDATES);
        $this->suggestionMinLength = self::clamp($suggestionMinLength, 2, 10);
        $this->indexDirty = $indexDirty;
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): self
    {
        return new self(
            self::boolean($settings->get(self::PREFIX.'typo_tolerance', '1')),
            self::boolean($settings->get(self::PREFIX.'search_post_content', '1')),
            (int) $settings->get(self::PREFIX.'one_typo_length', 4),
            (int) $settings->get(self::PREFIX.'two_typo_length', 8),
            (int) $settings->get(self::PREFIX.'native_result_threshold', 15),
            (int) $settings->get(self::PREFIX.'candidate_limit', 200),
            (int) $settings->get(self::PREFIX.'suggestion_min_length', 2),
            self::dirty($settings->get(self::PREFIX.'index_dirty', '0'))
        );
    }

    public function maxEditsFor(string $term, UnicodeText $text): int
    {
        if (! $this->typoTolerance || $text->isNumeric($term)) {
            return 0;
        }

        $length = $text->length($term);

        if ($length > self::MAX_FUZZY_TERM_CHARACTERS) {
            return 0;
        }

        if ($length >= $this->twoTypoLength) {
            return 2;
        }

        return $length >= $this->oneTypoLength ? 1 : 0;
    }

    private static function boolean($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function dirty($value): bool
    {
        return ! in_array($value, [null, false, 0, '0', '', 'false'], true);
    }

    private static function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }
}
