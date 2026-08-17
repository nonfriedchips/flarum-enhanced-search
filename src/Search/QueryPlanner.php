<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class QueryPlanner
{
    private UnicodeText $text;

    public function __construct(UnicodeText $text)
    {
        $this->text = $text;
    }

    public function plan(string $query, SearchOptions $options): QueryPlan
    {
        $query = $this->text->truncate($query, SearchOptions::MAX_QUERY_CHARACTERS * 2);
        $normalized = $this->text->normalize($query);
        $normalized = $this->text->truncate($normalized, SearchOptions::MAX_QUERY_CHARACTERS);
        $terms = $this->text->tokens($normalized, SearchOptions::MAX_QUERY_TERMS);
        $normalized = implode(' ', $terms);
        $compact = $this->text->compact($normalized);
        $allowedEdits = [];

        foreach ($terms as $term) {
            $allowedEdits[$term] = $options->maxEditsFor($term, $this->text);
        }

        return new QueryPlan($normalized, $compact, $terms, $allowedEdits);
    }

    public function length(QueryPlan $plan): int
    {
        return $this->text->length($plan->compact());
    }
}
