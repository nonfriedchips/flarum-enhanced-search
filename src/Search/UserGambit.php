<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;

final class UserGambit implements GambitInterface
{
    private HybridUserSearch $engine;

    public function __construct(HybridUserSearch $engine)
    {
        $this->engine = $engine;
    }

    public function apply(SearchState $search, $bit)
    {
        $results = $this->engine->search($search, (string) $bit);
        $query = $search->getQuery();

        if ($results === []) {
            $query->whereRaw('0 = 1');

            return true;
        }

        $ids = array_column($results, 'id');
        $query->whereIn('users.id', $ids);

        $grammar = $query->getGrammar();
        $userId = $grammar->wrap('users.id');
        $scoreParts = [];
        $bindings = [];

        foreach ($results as $result) {
            $scoreParts[] = 'WHEN ? THEN ?';
            $bindings[] = $result['id'];
            $bindings[] = $result['score'];
        }

        $scoreCase = 'CASE '.$userId.' '.implode(' ', $scoreParts).' ELSE 0 END';

        $search->setDefaultSort(static function ($query) use ($scoreCase, $bindings): void {
            $query
                ->orderByRaw($scoreCase.' DESC', $bindings)
                ->orderBy('users.username', 'asc');
        });

        return true;
    }
}
