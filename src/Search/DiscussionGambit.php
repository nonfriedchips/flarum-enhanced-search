<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Search;

use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;

final class DiscussionGambit implements GambitInterface
{
    private HybridDiscussionSearch $engine;
    private NativeDiscussionSearch $native;

    public function __construct(HybridDiscussionSearch $engine, NativeDiscussionSearch $native)
    {
        $this->engine = $engine;
        $this->native = $native;
    }

    public function apply(SearchState $search, $bit)
    {
        $outcome = $this->engine->search($search, (string) $bit);

        if ($outcome['use_native']) {
            $this->native->apply($search, $outcome['query']);

            return true;
        }

        $results = $outcome['results'];
        $query = $search->getQuery();

        if ($results === []) {
            $query->whereRaw('0 = 1');

            return true;
        }

        $ids = array_column($results, 'id');

        $grammar = $query->getGrammar();
        $discussionId = $grammar->wrap('discussions.id');
        $firstPostId = $grammar->wrap('discussions.first_post_id');
        $mostRelevantAlias = $grammar->wrap('most_relevant_post_id');
        $scoreParts = [];
        $scoreBindings = [];
        $postParts = [];
        $postBindings = [];

        foreach ($results as $result) {
            $scoreParts[] = 'WHEN ? THEN ?';
            $scoreBindings[] = $result['id'];
            $scoreBindings[] = $result['score'];

            if ($result['post_id'] !== null) {
                $postParts[] = 'WHEN ? THEN ?';
                $postBindings[] = $result['id'];
                $postBindings[] = $result['post_id'];
            }
        }

        if ($postParts === []) {
            $query->whereIn('discussions.id', $ids);
            $query->selectRaw("{$firstPostId} AS {$mostRelevantAlias}");
        } else {
            $postCase = 'CASE '.$discussionId.' '.implode(' ', $postParts).' END';
            $postIds = array_values(array_unique(array_filter(array_column($results, 'post_id'), static function ($id): bool {
                return $id !== null;
            })));
            $visiblePosts = Post::whereVisibleTo($search->getActor())
                ->select(['posts.id', 'posts.discussion_id'])
                ->where('posts.type', 'comment')
                ->whereIn('posts.id', $postIds);
            $visibleAlias = 'enhanced_search_visible_posts';
            $visiblePostId = $grammar->wrap($visibleAlias.'.id');
            $visibleDiscussionId = $grammar->wrap($visibleAlias.'.discussion_id');

            // Re-check post visibility and ownership in the final discussion
            // SQL. This closes the gap where a post is hidden or unapproved
            // after candidate retrieval but before mostRelevantPost loading.
            $query->leftJoinSub($visiblePosts, $visibleAlias, static function ($join) use (
                $visibleDiscussionId,
                $discussionId,
                $visiblePostId,
                $postCase,
                $postBindings
            ): void {
                $join->whereRaw(
                    "{$visibleDiscussionId} = {$discussionId} AND {$visiblePostId} = {$postCase}",
                    $postBindings
                );
            });
            $query->selectRaw("COALESCE({$visiblePostId}, {$firstPostId}) AS {$mostRelevantAlias}");

            $independentIds = [];
            $postRequiredIds = [];

            foreach ($results as $result) {
                if ($result['requires_post']) {
                    $postRequiredIds[] = $result['id'];
                } else {
                    $independentIds[] = $result['id'];
                }
            }

            // If a discussion matched only through a post, require that same
            // post to remain visible in the final SQL. A concurrent hide,
            // unapproval, move, or deletion then removes the result instead
            // of leaking that the stale body matched.
            $query->where(static function ($candidateQuery) use (
                $independentIds,
                $postRequiredIds,
                $visibleAlias
            ): void {
                $candidateQuery->whereIn('discussions.id', $independentIds);

                if ($postRequiredIds !== []) {
                    $candidateQuery->orWhere(static function ($postQuery) use ($postRequiredIds, $visibleAlias): void {
                        $postQuery
                            ->whereIn('discussions.id', $postRequiredIds)
                            ->whereNotNull($visibleAlias.'.id');
                    });
                }
            });
        }

        $scoreCase = 'CASE '.$discussionId.' '.implode(' ', $scoreParts).' ELSE 0 END';

        $search->setDefaultSort(static function ($query) use ($scoreCase, $scoreBindings): void {
            $query
                ->orderByRaw($scoreCase.' DESC', $scoreBindings)
                ->orderBy('discussions.last_posted_at', 'desc')
                ->orderBy('discussions.id', 'desc');
        });

        return true;
    }
}
