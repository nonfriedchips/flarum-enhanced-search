<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index;

use Flarum\Post\Post;
use Flarum\Search\SearchState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use NonFriedChips\EnhancedSearch\Search\QueryPlan;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class SearchDocumentRepository
{
    private ConnectionInterface $connection;
    private UnicodeText $text;

    public function __construct(ConnectionInterface $connection, UnicodeText $text)
    {
        $this->connection = $connection;
        $this->text = $text;
    }

    /** @return object[] */
    public function discussionCandidates(SearchState $search, QueryPlan $plan, SearchOptions $options): array
    {
        if ($this->text->length($plan->compact()) < SearchOptions::NGRAM_TOKEN_SIZE) {
            return [];
        }

        $titleLimit = $options->searchPostContent
            ? intdiv($options->candidateLimit, 2)
            : $options->candidateLimit;
        $postLimit = $options->candidateLimit - $titleLimit;
        $eligibleTitles = $this->eligibleDiscussionIds($search);
        $titles = $this->documentQuery(DocumentIndexer::TYPE_DISCUSSION, $plan)
            ->addSelect('search_documents.model_id as discussion_id')
            ->whereColumn('search_documents.model_id', 'search_documents.discussion_id')
            ->whereIn('search_documents.model_id', $eligibleTitles)
            ->limit($titleLimit)
            ->get()
            ->all();

        if (! $options->searchPostContent) {
            return $titles;
        }

        $eligiblePosts = $this->eligibleDiscussionIds($search);
        $visiblePostIds = Post::whereVisibleTo($search->getActor())
            ->select('posts.id')
            ->where('posts.type', 'comment')
            ->whereIn('posts.discussion_id', $eligiblePosts);

        $posts = $this->documentQuery(DocumentIndexer::TYPE_POST, $plan)
            ->join('posts as indexed_posts', 'indexed_posts.id', '=', 'search_documents.model_id')
            ->addSelect('indexed_posts.discussion_id as discussion_id')
            ->whereColumn('search_documents.discussion_id', 'indexed_posts.discussion_id')
            ->whereIn('search_documents.model_id', $visiblePostIds)
            ->limit($postLimit)
            ->get()
            ->all();

        return array_merge($titles, $posts);
    }

    /** @return object[] */
    public function userCandidates(SearchState $search, QueryPlan $plan, SearchOptions $options): array
    {
        if ($this->text->length($plan->compact()) < SearchOptions::NGRAM_TOKEN_SIZE) {
            return [];
        }

        $eligibleUsers = clone $search->getQuery();
        $eligibleUsers->select('users.id');

        return $this->documentQuery(DocumentIndexer::TYPE_USER, $plan)
            ->whereIn('search_documents.model_id', $eligibleUsers)
            ->limit($options->candidateLimit)
            ->get()
            ->all();
    }

    private function eligibleDiscussionIds(SearchState $search): Builder
    {
        $query = clone $search->getQuery();
        $query->select('discussions.id');

        return $query;
    }

    private function documentQuery(string $type, QueryPlan $plan): Builder
    {
        // MySQL's ngram parser needs at least two characters with the default
        // token size. Never replace that indexed lookup with a leading-
        // wildcard LIKE: LIMIT would cap returned rows but not rows scanned.
        if ($this->text->length($plan->compact()) < SearchOptions::NGRAM_TOKEN_SIZE) {
            throw new \InvalidArgumentException('The ngram candidate query requires at least two characters.');
        }

        $query = $this->connection->table(DocumentIndexer::TABLE.' as search_documents')
            ->select([
                'search_documents.model_type',
                'search_documents.model_id',
                'search_documents.content',
                'search_documents.is_normalized',
            ])
            ->where('search_documents.model_type', $type);

        $grammar = $query->getGrammar();
        $column = $grammar->wrap('search_documents.content');
        $naturalMatch = "MATCH ({$column}) AGAINST (? IN NATURAL LANGUAGE MODE)";
        $transpositions = $this->transpositionQuery($plan);

        if ($transpositions === '') {
            $query
                ->selectRaw($naturalMatch.' AS ngram_score', [$plan->normalized()])
                ->whereRaw($naturalMatch, [$plan->normalized()]);
        } else {
            // A four-character middle transposition can share no bigram with
            // the query. Add a tightly bounded set of exact adjacent-swap
            // phrases so those rows still reach the PHP edit-distance stage.
            $swapMatch = "MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)";
            $query
                ->selectRaw(
                    "GREATEST({$naturalMatch}, {$swapMatch}) AS ngram_score",
                    [$plan->normalized(), $transpositions]
                )
                ->where(function (Builder $where) use ($naturalMatch, $swapMatch, $plan, $transpositions): void {
                    $where
                        ->whereRaw($naturalMatch, [$plan->normalized()])
                        ->orWhereRaw($swapMatch, [$transpositions]);
                });
        }

        return $query
            ->orderByDesc('ngram_score')
            ->orderByDesc('search_documents.model_id');
    }

    private function transpositionQuery(QueryPlan $plan): string
    {
        $variants = [];

        foreach ($plan->terms() as $term) {
            if ($plan->allowedEdits($term) < 1) {
                continue;
            }

            // Longer terms retain at least one unchanged bigram after a
            // single adjacent swap. Keeping this bound small prevents a user
            // from manufacturing a large Boolean expansion.
            foreach ($this->text->adjacentTranspositions($term) as $variant) {
                $variants[$variant] = true;

                if (count($variants) >= 32) {
                    break 2;
                }
            }
        }

        return implode(' ', array_map(static function (string $variant): string {
            return '"'.$variant.'"';
        }, array_keys($variants)));
    }
}
