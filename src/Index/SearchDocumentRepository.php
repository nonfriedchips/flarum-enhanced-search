<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index;

use Flarum\Post\Post;
use Flarum\Search\SearchState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackend;
use NonFriedChips\EnhancedSearch\Search\QueryPlan;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class SearchDocumentRepository
{
    private ConnectionInterface $connection;
    private UnicodeText $text;
    private SearchIndexBackend $backend;

    public function __construct(
        ConnectionInterface $connection,
        UnicodeText $text,
        SearchIndexBackend $backend
    ) {
        $this->connection = $connection;
        $this->text = $text;
        $this->backend = $backend;
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
        // Both backends index two-character grams. Never replace this lookup
        // with a leading-wildcard LIKE: LIMIT caps returned rows, not scans.
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
        $column = $grammar->wrap('search_documents.'.$this->backend->fulltextColumn());
        $fulltext = $this->backend->query($plan, $this->transpositionVariants($plan));

        if ($fulltext->isEmpty()) {
            return $query
                ->selectRaw('0 AS ngram_score')
                ->whereRaw('1 = 0');
        }

        $naturalMatch = "MATCH ({$column}) AGAINST (? IN NATURAL LANGUAGE MODE)";
        $candidateMatch = $fulltext->candidateUsesBooleanMode()
            ? "MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)"
            : $naturalMatch;
        $scoreExpressions = [$naturalMatch];
        $scoreBindings = [$fulltext->rankingText()];

        if ($candidateMatch !== $naturalMatch || $fulltext->candidateText() !== $fulltext->rankingText()) {
            $scoreExpressions[] = $candidateMatch;
            $scoreBindings[] = $fulltext->candidateText();
        }

        $swapMatch = null;

        if ($fulltext->transpositionText() !== '') {
            // A four-character middle transposition can share no bigram with
            // the query. Add a tightly bounded set of exact adjacent-swap
            // phrases so those rows still reach the PHP edit-distance stage.
            $swapMatch = "MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)";
            $scoreExpressions[] = $swapMatch;
            $scoreBindings[] = $fulltext->transpositionText();
        }

        $scoreExpression = count($scoreExpressions) === 1
            ? $scoreExpressions[0]
            : 'GREATEST('.implode(', ', $scoreExpressions).')';
        $query->selectRaw($scoreExpression.' AS ngram_score', $scoreBindings);
        $query->where(function (Builder $where) use ($candidateMatch, $fulltext, $swapMatch): void {
            $where->whereRaw($candidateMatch, [$fulltext->candidateText()]);

            if ($swapMatch !== null) {
                $where->orWhereRaw($swapMatch, [$fulltext->transpositionText()]);
            }
        });

        return $query
            ->orderByDesc('ngram_score')
            ->orderByDesc('search_documents.model_id');
    }

    /** @return string[] */
    private function transpositionVariants(QueryPlan $plan): array
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

        return array_keys($variants);
    }
}
