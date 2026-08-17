<?php

declare(strict_types=1);

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Query\QueryCriteria;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;

set_exception_handler(static function (Throwable $exception): void {
    $message = preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? 'unknown integration error';
    fwrite(STDERR, 'FAIL: '.get_class($exception).': '.substr($message, 0, 500).PHP_EOL);
    exit(1);
});

$root = dirname(__DIR__, 3);
$site = require $root.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();
$extensions = $container->make(ExtensionManager::class);

if (! $extensions->isEnabled('nonfriedchips-enhanced-search')) {
    fwrite(STDERR, "FAIL: enable nonfriedchips-enhanced-search before running integration tests.\n");
    exit(2);
}

/** @var ConnectionInterface $connection */
$connection = $container->make(ConnectionInterface::class);

if (! $connection->getSchemaBuilder()->hasTable(DocumentIndexer::TABLE)) {
    fwrite(STDERR, "FAIL: enhanced search index table is missing; run the extension migrations.\n");
    exit(1);
}

/** @var User|null $actor */
$actor = User::query()->orderBy('id')->first();

if (! $actor) {
    fwrite(STDERR, "FAIL: integration tests need one existing user as the temporary discussion author.\n");
    exit(2);
}

/** @var DocumentIndexer $indexer */
$indexer = $container->make(DocumentIndexer::class);
/** @var DiscussionSearcher $searcher */
$searcher = $container->make(DiscussionSearcher::class);
$schema = $connection->getSchemaBuilder();
$nonce = substr(hash('sha256', (string) microtime(true)), 0, 10);

/**
 * @return array{0: Discussion, 1: CommentPost}
 */
function createIndexedDiscussion(
    string $title,
    string $content,
    User $actor,
    DocumentIndexer $indexer,
    $schema
): array {
    $discussion = Discussion::start($title, $actor);

    if ($schema->hasColumn('discussions', 'is_approved')) {
        $discussion->is_approved = true;
    }

    $discussion->save();

    $post = CommentPost::reply($discussion->id, $content, $actor->id, '127.0.0.1', $actor);

    if ($schema->hasColumn('posts', 'is_approved')) {
        $post->is_approved = true;
    }

    $post->save();

    $discussion->first_post_id = $post->id;
    $discussion->last_post_id = $post->id;
    $discussion->last_post_number = $post->number;
    $discussion->last_posted_at = Carbon::now();
    $discussion->last_posted_user_id = $actor->id;
    $discussion->comment_count = 1;
    $discussion->save();

    $indexer->indexDiscussion($discussion);
    $indexer->indexPost($post);

    return [$discussion, $post];
}

/** @return \Illuminate\Database\Eloquent\Collection */
function search(DiscussionSearcher $searcher, User $actor, string $query)
{
    return $searcher
        ->search(new QueryCriteria($actor, ['q' => $query], [], true), 20, 0)
        ->getResults();
}

function requireCondition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$createdDiscussionIds = [];

try {
    [
        $exactDiscussion,
        $fuzzyDiscussion,
        $contentDiscussion,
        $contentPost,
        $numericDiscussion,
        $transposedDiscussion,
    ] = $connection->transaction(function () use (
        $nonce,
        $actor,
        $indexer,
        $schema,
        &$createdDiscussionIds
    ): array {
        [$exactDiscussion] = createIndexedDiscussion(
            "{$nonce} Flarum 模糊搜索指南",
            '精确标题应优先于带错字的标题。',
            $actor,
            $indexer,
            $schema
        );
        [$fuzzyDiscussion] = createIndexedDiscussion(
            "{$nonce} Flarum 模胡搜索随笔",
            '该标题故意包含一个错别字。',
            $actor,
            $indexer,
            $schema
        );
        [$contentDiscussion, $contentPost] = createIndexedDiscussion(
            "{$nonce} 无关标题",
            "{$nonce} 这篇正文介绍量子检索与论坛索引。",
            $actor,
            $indexer,
            $schema
        );
        [$numericDiscussion] = createIndexedDiscussion(
            "{$nonce} release 2025",
            '数字版本号必须精确匹配。',
            $actor,
            $indexer,
            $schema
        );
        [$transposedDiscussion] = createIndexedDiscussion(
            "{$nonce} 论坛甲丙乙丁实践",
            '标题包含一个与查询没有共同 bigram 的相邻颠倒。',
            $actor,
            $indexer,
            $schema
        );

        $createdDiscussionIds = [
            (int) $exactDiscussion->id,
            (int) $fuzzyDiscussion->id,
            (int) $contentDiscussion->id,
            (int) $numericDiscussion->id,
            (int) $transposedDiscussion->id,
        ];

        return [
            $exactDiscussion,
            $fuzzyDiscussion,
            $contentDiscussion,
            $contentPost,
            $numericDiscussion,
            $transposedDiscussion,
        ];
    });

    // InnoDB does not expose uncommitted FULLTEXT index entries, even to the
    // transaction that inserted them. Search only after committing the
    // uniquely named fixtures, then remove those exact discussions below.

    $ranked = search($searcher, $actor, "{$nonce} 模糊搜索");
    requireCondition($ranked->isNotEmpty(), 'exact/fuzzy title search returned no rows');
    requireCondition((int) $ranked->first()->id === (int) $exactDiscussion->id, 'exact title did not rank above typo title');
    requireCondition($ranked->contains('id', $fuzzyDiscussion->id), 'one-character Chinese typo was not recalled');

    $transposedResults = search($searcher, $actor, '甲乙丙丁');
    requireCondition(
        $transposedResults->contains('id', $transposedDiscussion->id),
        'an adjacent transposition with no shared bigram was not recalled'
    );

    $contentResults = search($searcher, $actor, "{$nonce} 量子捡索");
    $contentMatch = $contentResults->firstWhere('id', $contentDiscussion->id);
    requireCondition($contentMatch !== null, 'one-character typo in post content was not recalled');
    requireCondition(
        (int) $contentMatch->most_relevant_post_id === (int) $contentPost->id,
        'most_relevant_post_id does not identify the matching post'
    );

    // Flarum's native Boolean FULLTEXT treats separate words as optional
    // terms. Query the numeric token alone so a shared fixture nonce cannot
    // create a legitimate native match and mask the typo-tolerance boundary.
    $numericResults = search($searcher, $actor, '2024');
    requireCondition(! $numericResults->contains('id', $numericDiscussion->id), 'numeric typo tolerance should be disabled');

    search($searcher, $actor, "%_'\\");

    fwrite(STDOUT, "Enhanced Search MySQL integration tests passed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n");
    $exitCode = 1;
} finally {
    if ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if ($createdDiscussionIds !== []) {
        try {
            $connection->transaction(function () use ($createdDiscussionIds, $connection): void {
                foreach (Discussion::query()->whereIn('id', $createdDiscussionIds)->get() as $discussion) {
                    $discussion->delete();
                }

                // The normal DiscussionDeleted listener removes these rows.
                // Keep an exact-ID cleanup as a final guard for interrupted
                // event dispatch during a failing integration test.
                $connection->table(DocumentIndexer::TABLE)
                    ->whereIn('discussion_id', $createdDiscussionIds)
                    ->delete();
            });
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, 'FAIL: unable to remove integration fixtures: '.$cleanupException->getMessage()."\n");
            $exitCode = 1;
        }
    }
}

exit($exitCode ?? 0);
