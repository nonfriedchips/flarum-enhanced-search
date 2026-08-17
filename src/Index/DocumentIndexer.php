<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

final class DocumentIndexer
{
    public const TABLE = 'enhanced_search_documents';
    public const TYPE_DISCUSSION = 'discussion';
    public const TYPE_POST = 'post';
    public const TYPE_USER = 'user';

    private ConnectionInterface $connection;
    private UnicodeText $text;

    public function __construct(ConnectionInterface $connection, UnicodeText $text)
    {
        $this->connection = $connection;
        $this->text = $text;
    }

    public function indexDiscussion(Discussion $discussion): void
    {
        if (! $discussion->exists || ! $discussion->id) {
            return;
        }

        $this->upsert(
            self::TYPE_DISCUSSION,
            (int) $discussion->id,
            (int) $discussion->id,
            (string) $discussion->title
        );
    }

    public function removeDiscussion(Discussion $discussion): void
    {
        if (! $discussion->id) {
            return;
        }

        $this->connection->table(self::TABLE)
            ->where('discussion_id', (int) $discussion->id)
            ->delete();
    }

    public function indexPost(Post $post): void
    {
        if (! $post->exists || ! $post->id || $post->type !== 'comment') {
            if ($post->id) {
                $this->removePost($post);
            }

            return;
        }

        $content = $post instanceof CommentPost
            ? strip_tags((string) $post->formatContent())
            : (string) $post->content;

        $this->upsert(
            self::TYPE_POST,
            (int) $post->id,
            (int) $post->discussion_id,
            $this->text->truncate($content, SearchOptions::MAX_INDEXED_POST_CHARACTERS)
        );
    }

    public function removePost(Post $post): void
    {
        if (! $post->id) {
            return;
        }

        $this->connection->table(self::TABLE)
            ->where('model_type', self::TYPE_POST)
            ->where('model_id', (int) $post->id)
            ->delete();
    }

    public function indexUser(User $user): void
    {
        if (! $user->exists || ! $user->id) {
            return;
        }

        $searchableName = trim((string) $user->username.' '.(string) $user->display_name);

        $this->upsert(self::TYPE_USER, (int) $user->id, null, $searchableName);
    }

    public function removeUser(User $user): void
    {
        if (! $user->id) {
            return;
        }

        $this->connection->table(self::TABLE)
            ->where('model_type', self::TYPE_USER)
            ->where('model_id', (int) $user->id)
            ->delete();
    }

    public function clear(): void
    {
        $this->connection->table(self::TABLE)->delete();
    }

    private function upsert(string $type, int $modelId, ?int $discussionId, string $content): void
    {
        $normalized = $this->text->normalize($content);

        if ($normalized === '') {
            $this->connection->table(self::TABLE)
                ->where('model_type', $type)
                ->where('model_id', $modelId)
                ->delete();

            return;
        }

        $this->connection->table(self::TABLE)->upsert(
            [[
                'model_type' => $type,
                'model_id' => $modelId,
                'discussion_id' => $discussionId,
                'content' => $normalized,
                'is_normalized' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]],
            ['model_type', 'model_id'],
            ['discussion_id', 'content', 'is_normalized', 'updated_at']
        );
    }
}
