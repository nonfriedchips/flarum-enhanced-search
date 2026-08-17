<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

final class QueryPlan
{
    private string $normalized;
    private string $compact;

    /** @var string[] */
    private array $terms;

    /** @var array<string, int> */
    private array $allowedEdits;

    /**
     * @param string[] $terms
     * @param array<string, int> $allowedEdits
     */
    public function __construct(string $normalized, string $compact, array $terms, array $allowedEdits)
    {
        $this->normalized = $normalized;
        $this->compact = $compact;
        $this->terms = $terms;
        $this->allowedEdits = $allowedEdits;
    }

    public function isEmpty(): bool
    {
        return $this->compact === '' || $this->terms === [];
    }

    public function normalized(): string
    {
        return $this->normalized;
    }

    public function compact(): string
    {
        return $this->compact;
    }

    /** @return string[] */
    public function terms(): array
    {
        return $this->terms;
    }

    public function allowedEdits(string $term): int
    {
        return $this->allowedEdits[$term] ?? 0;
    }
}
