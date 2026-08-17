<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Listener;

use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Renamed as DiscussionRenamed;
use Flarum\Discussion\Event\Started as DiscussionStarted;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Deleted as UserDeleted;
use Flarum\User\Event\Registered;
use Flarum\User\Event\Renamed as UserRenamed;
use Flarum\User\Event\Saving as UserSaving;
use Illuminate\Contracts\Events\Dispatcher;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use Psr\Log\LoggerInterface;
use Throwable;

final class UpdateSearchIndex
{
    private DocumentIndexer $indexer;
    private LoggerInterface $logger;
    private SettingsRepositoryInterface $settings;

    public function __construct(
        DocumentIndexer $indexer,
        LoggerInterface $logger,
        SettingsRepositoryInterface $settings
    ) {
        $this->indexer = $indexer;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(DiscussionStarted::class, [$this, 'discussionSaved']);
        $events->listen(DiscussionRenamed::class, [$this, 'discussionSaved']);
        $events->listen(DiscussionDeleted::class, [$this, 'discussionDeleted']);

        $events->listen(Posted::class, [$this, 'postSaved']);
        $events->listen(Revised::class, [$this, 'postSaved']);
        $events->listen(PostDeleted::class, [$this, 'postDeleted']);

        $events->listen(Registered::class, [$this, 'userSaved']);
        $events->listen(UserRenamed::class, [$this, 'userSaved']);
        $events->listen(UserSaving::class, [$this, 'userSaving']);
        $events->listen(UserDeleted::class, [$this, 'userDeleted']);
    }

    /** @param DiscussionStarted|DiscussionRenamed $event */
    public function discussionSaved($event): void
    {
        $this->safely('index discussion', function () use ($event): void {
            $this->indexer->indexDiscussion($event->discussion);
        });
    }

    public function discussionDeleted(DiscussionDeleted $event): void
    {
        $this->safely('remove discussion', function () use ($event): void {
            $this->indexer->removeDiscussion($event->discussion);
        });
    }

    /** @param Posted|Revised $event */
    public function postSaved($event): void
    {
        $this->safely('index post', function () use ($event): void {
            $this->indexer->indexPost($event->post);
        });
    }

    public function postDeleted(PostDeleted $event): void
    {
        $this->safely('remove post', function () use ($event): void {
            $this->indexer->removePost($event->post);
        });
    }

    /** @param Registered|UserRenamed $event */
    public function userSaved($event): void
    {
        $this->safely('index user', function () use ($event): void {
            $this->indexer->indexUser($event->user);
        });
    }

    public function userSaving(UserSaving $event): void
    {
        // Saving is dispatched before validation and persistence. Deferring
        // the derived-index write avoids indexing an invalid username or a
        // nickname change that never reaches the database.
        $event->user->afterSave(function ($user): void {
            $this->safely('index user', function () use ($user): void {
                $this->indexer->indexUser($user);
            });
        });
    }

    public function userDeleted(UserDeleted $event): void
    {
        $this->safely('remove user', function () use ($event): void {
            $this->indexer->removeUser($event->user);
        });
    }

    private function safely(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            // Search is derived data. A broken index must never prevent a
            // discussion, post, or account mutation from completing.
            try {
                $this->settings->set(SearchOptions::PREFIX.'index_dirty', '1');
            } catch (Throwable $markerException) {
                $this->logger->critical('Enhanced Search could not mark its failed index as dirty.', [
                    'exception' => get_class($markerException),
                    'code' => $markerException->getCode(),
                ]);
            }

            $this->logger->error('Enhanced Search failed to '.$operation.'. Run enhanced-search:reindex after fixing the database error.', [
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
            ]);
        }
    }
}
