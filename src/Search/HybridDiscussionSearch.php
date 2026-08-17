<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Search\SearchState;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\QueryException;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;
use NonFriedChips\EnhancedSearch\Index\SearchDocumentRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final class HybridDiscussionSearch
{
    private const TITLE_WEIGHT = 2000000;
    private const POST_WEIGHT = 1000000;
    private const NATIVE_BODY_SCORE = 1100000;

    private SettingsRepositoryInterface $settings;
    private QueryPlanner $planner;
    private FuzzyScorer $scorer;
    private NativeDiscussionSearch $native;
    private SearchDocumentRepository $documents;
    private LoggerInterface $logger;

    public function __construct(
        SettingsRepositoryInterface $settings,
        QueryPlanner $planner,
        FuzzyScorer $scorer,
        NativeDiscussionSearch $native,
        SearchDocumentRepository $documents,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->planner = $planner;
        $this->scorer = $scorer;
        $this->native = $native;
        $this->documents = $documents;
        $this->logger = $logger;
    }

    /**
     * @return array{
     *     use_native: bool,
     *     query: string,
     *     results: array<int, array{id: int, score: int, post_id: int|null, requires_post: bool}>
     * }
     */
    public function search(SearchState $search, string $query): array
    {
        $options = SearchOptions::fromSettings($this->settings);
        $plan = $this->planner->plan($query, $options);

        if ($plan->isEmpty()) {
            return ['use_native' => false, 'query' => '', 'results' => []];
        }

        /** @var array<int, array{id: int, score: int, post_id: int|null, post_quality: float, requires_post: bool}> $ranked */
        $ranked = [];
        $native = $this->native->candidates($search, $plan->normalized(), $options->nativeResultThreshold);

        // Dense, ordinary FULLTEXT result sets do not need typo recovery.
        // Applying Flarum's gambit directly keeps SQL-level pagination over
        // the complete native result set instead of a bounded PHP window.
        if (count($native) >= $options->nativeResultThreshold) {
            return ['use_native' => true, 'query' => $plan->normalized(), 'results' => []];
        }

        foreach ($native as $position => $discussion) {
            $id = (int) $discussion->id;
            $titleQuality = $this->scorer->score($plan, (string) $discussion->title);
            $score = max(
                self::NATIVE_BODY_SCORE - min(9999, $position),
                (int) round($titleQuality * self::TITLE_WEIGHT)
            );

            $postId = isset($discussion->most_relevant_post_id)
                ? (int) $discussion->most_relevant_post_id
                : null;

            $ranked[$id] = [
                'id' => $id,
                'score' => $score,
                'post_id' => $postId,
                'post_quality' => $postId !== null && $postId !== (int) $discussion->first_post_id ? 0.95 : 0.0,
                'requires_post' => $titleQuality <= 0.0,
            ];
        }

        if (! $options->indexDirty && $this->planner->length($plan) >= SearchOptions::NGRAM_TOKEN_SIZE) {
            try {
                $candidates = $this->documents->discussionCandidates($search, $plan, $options);

                foreach ($candidates as $candidate) {
                    $quality = (bool) $candidate->is_normalized
                        ? $this->scorer->scoreNormalized($plan, (string) $candidate->content)
                        : $this->scorer->score($plan, (string) $candidate->content);

                    if ($quality <= 0.0) {
                        continue;
                    }

                    $discussionId = (int) $candidate->discussion_id;
                    $isTitle = $candidate->model_type === DocumentIndexer::TYPE_DISCUSSION;
                    $weight = $isTitle ? self::TITLE_WEIGHT : self::POST_WEIGHT;
                    $ngramBonus = min(999, (int) round(log(1.0 + max(0.0, (float) $candidate->ngram_score)) * 100));
                    $score = (int) round($quality * $weight) + $ngramBonus;

                    if (! isset($ranked[$discussionId])) {
                        $ranked[$discussionId] = [
                            'id' => $discussionId,
                            'score' => $score,
                            'post_id' => null,
                            'post_quality' => 0.0,
                            'requires_post' => ! $isTitle,
                        ];
                    } else {
                        $ranked[$discussionId]['score'] = max($ranked[$discussionId]['score'], $score);

                        if ($isTitle) {
                            $ranked[$discussionId]['requires_post'] = false;
                        }
                    }

                    if (! $isTitle && $quality > $ranked[$discussionId]['post_quality']) {
                        $ranked[$discussionId]['post_id'] = (int) $candidate->model_id;
                        $ranked[$discussionId]['post_quality'] = $quality;
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

                $this->logger->warning('Enhanced Search n-gram retrieval failed; returning native FULLTEXT results only.', [
                    'exception' => get_class($exception),
                    'code' => $exception->getCode(),
                ]);
            }
        }

        uasort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'] ?: $right['id'] <=> $left['id'];
        });

        $results = array_values(array_map(static function (array $item): array {
            unset($item['post_quality']);

            return $item;
        }, array_slice($ranked, 0, $options->candidateLimit, true)));

        return ['use_native' => false, 'query' => $plan->normalized(), 'results' => $results];
    }
}
