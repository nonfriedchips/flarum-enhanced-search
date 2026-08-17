<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;

final class ReindexCommand extends Command
{
    protected $signature = 'enhanced-search:reindex
        {--chunk=200 : Models processed per database batch}';

    protected $description = 'Rebuild the local n-gram search index for discussions, posts, and users';

    private DocumentIndexer $indexer;
    private ConnectionInterface $connection;
    private SettingsRepositoryInterface $settings;

    public function __construct(
        DocumentIndexer $indexer,
        ConnectionInterface $connection,
        SettingsRepositoryInterface $settings
    ) {
        parent::__construct();

        $this->indexer = $indexer;
        $this->connection = $connection;
        $this->settings = $settings;
    }

    public function handle(): int
    {
        $chunkSize = max(20, min(2000, (int) $this->option('chunk')));
        $generation = 'reindex:'.bin2hex(random_bytes(16));
        $this->settings->set(SearchOptions::PREFIX.'index_dirty', $generation);
        $discussionCount = Discussion::query()->count();
        $postCount = Post::query()->where('type', 'comment')->count();
        $userCount = User::query()->count();
        $total = $discussionCount + $postCount + $userCount;

        $this->info("Rebuilding {$total} enhanced-search documents in a transaction...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $this->connection->transaction(function () use ($chunkSize, $bar): void {
            $this->indexer->clear();

            Discussion::query()->chunkById($chunkSize, function ($discussions) use ($bar): void {
                foreach ($discussions as $discussion) {
                    $this->indexer->indexDiscussion($discussion);
                    $bar->advance();
                }
            });

            Post::query()->where('type', 'comment')->chunkById($chunkSize, function ($posts) use ($bar): void {
                foreach ($posts as $post) {
                    $this->indexer->indexPost($post);
                    $bar->advance();
                }
            });

            User::query()->chunkById($chunkSize, function ($users) use ($bar): void {
                foreach ($users as $user) {
                    $this->indexer->indexUser($user);
                    $bar->advance();
                }
            });
        });

        $bar->finish();
        $this->newLine();

        // A failed live update replaces the generation token with "1".
        // Only clear the marker if no listener reported a failure while this
        // rebuild was running; otherwise remain safely in native-only mode.
        $cleared = $this->connection->table('settings')
            ->where('key', SearchOptions::PREFIX.'index_dirty')
            ->where('value', $generation)
            ->update(['value' => '0']);

        if ($cleared !== 1) {
            $this->warn('The index was rebuilt, but a concurrent live update failed. The index remains marked dirty; run reindex again in a write-free maintenance window.');

            return self::FAILURE;
        }

        $this->info('Enhanced search index rebuilt successfully.');

        return self::SUCCESS;
    }
}
