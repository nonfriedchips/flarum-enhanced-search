<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

final class FulltextQuery
{
    private string $rankingText;
    private string $candidateText;
    private bool $candidateUsesBooleanMode;
    private string $transpositionText;

    public function __construct(
        string $rankingText,
        string $candidateText,
        bool $candidateUsesBooleanMode,
        string $transpositionText
    ) {
        $this->rankingText = $rankingText;
        $this->candidateText = $candidateText;
        $this->candidateUsesBooleanMode = $candidateUsesBooleanMode;
        $this->transpositionText = $transpositionText;
    }

    public function isEmpty(): bool
    {
        return $this->candidateText === '';
    }

    public function rankingText(): string
    {
        return $this->rankingText;
    }

    public function candidateText(): string
    {
        return $this->candidateText;
    }

    public function candidateUsesBooleanMode(): bool
    {
        return $this->candidateUsesBooleanMode;
    }

    public function transpositionText(): string
    {
        return $this->transpositionText;
    }
}
