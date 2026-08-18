<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Search\SearchState;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\QueryException;
use NonFriedChips\EnhancedSearch\Index\SearchDocumentRepository;
use NonFriedChips\EnhancedSearch\Support\LikePattern;
use Psr\Log\LoggerInterface;
use Throwable;

final class HybridUserSearch
{
    private SettingsRepositoryInterface $settings;
    private QueryPlanner $planner;
    private FuzzyScorer $scorer;
    private SearchDocumentRepository $documents;
    private LoggerInterface $logger;

    public function __construct(
        SettingsRepositoryInterface $settings,
        QueryPlanner $planner,
        FuzzyScorer $scorer,
        SearchDocumentRepository $documents,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->planner = $planner;
        $this->scorer = $scorer;
        $this->documents = $documents;
        $this->logger = $logger;
    }

    /** @return array<int, array{id: int, score: int}> */
    public function search(SearchState $search, string $query): array
    {
        $options = SearchOptions::fromSettings($this->settings);
        $plan = $this->planner->plan($query, $options);

        if ($plan->isEmpty()) {
            return [];
        }

        /** @var array<int, array{id: int, score: int}> $ranked */
        $ranked = [];
        $prefixQuery = clone $search->getQuery();
        $column = $prefixQuery->getGrammar()->wrap('users.username');
        $prefixQuery
            ->select(['users.id', 'users.username'])
            ->whereRaw(
                $column." LIKE ? ESCAPE '".LikePattern::ESCAPE_CHARACTER."'",
                [LikePattern::prefix($plan->normalized())]
            )
            ->limit($options->candidateLimit);

        foreach ($prefixQuery->get() as $position => $user) {
            $quality = $this->scorer->score($plan, (string) $user->username);
            $ranked[(int) $user->id] = [
                'id' => (int) $user->id,
                'score' => max(900000 - min(9999, $position), (int) round($quality * 1000000)),
            ];
        }

        if (! $options->indexDirty && $this->planner->length($plan) >= SearchOptions::NGRAM_TOKEN_SIZE) {
            try {
                foreach ($this->documents->userCandidates($search, $plan, $options) as $candidate) {
                    $quality = (bool) $candidate->is_normalized
                        ? $this->scorer->scoreNormalized($plan, (string) $candidate->content)
                        : $this->scorer->score($plan, (string) $candidate->content);

                    if ($quality <= 0.0) {
                        continue;
                    }

                    $id = (int) $candidate->model_id;
                    $score = (int) round($quality * 1000000)
                        + min(999, (int) round(log(1.0 + max(0.0, (float) $candidate->ngram_score)) * 100));

                    if (! isset($ranked[$id]) || $score > $ranked[$id]['score']) {
                        $ranked[$id] = ['id' => $id, 'score' => $score];
                    }
                }
            } catch (QueryException $exception) {
                try {
                    $this->settings->set(SearchOptions::PREFIX.'index_dirty', '1');
                } catch (Throwable $markerException) {
                    $this->logger->critical('Enhanced Search could not mark its failed index as dirty.', [
                        'exception' => get_class($markerException),
                        'code' => $markerException->getCode(),
                    ]);
                }

                $this->logger->warning('Enhanced Search user candidate retrieval failed; returning username-prefix results only.', [
                    'exception' => get_class($exception),
                    'code' => $exception->getCode(),
                ]);
            }
        }

        uasort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'] ?: $right['id'] <=> $left['id'];
        });

        return array_values(array_slice($ranked, 0, $options->candidateLimit, true));
    }
}
