<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Discussion\Search\Gambit\FulltextGambit;
use Flarum\Search\SearchState;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

final class NativeDiscussionSearch
{
    private FulltextGambit $gambit;
    private LoggerInterface $logger;

    public function __construct(FulltextGambit $gambit, LoggerInterface $logger)
    {
        $this->gambit = $gambit;
        $this->logger = $logger;
    }

    public function apply(SearchState $search, string $query): void
    {
        $this->gambit->apply($search, $query);
    }

    /** @return object[] */
    public function candidates(SearchState $search, string $query, int $limit): array
    {
        $candidateQuery = clone $search->getQuery();
        $candidateQuery->select('discussions.*');

        $nativeState = new SearchState($candidateQuery, $search->getActor());

        try {
            $this->gambit->apply($nativeState, $query);

            $sort = $nativeState->getDefaultSort();

            if (is_callable($sort)) {
                $sort($candidateQuery);
            }

            return $candidateQuery->limit($limit)->get()->all();
        } catch (QueryException $exception) {
            $this->logger->warning('Enhanced Search could not use Flarum native FULLTEXT retrieval; continuing with the n-gram index.', [
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
            ]);

            return [];
        }
    }
}
