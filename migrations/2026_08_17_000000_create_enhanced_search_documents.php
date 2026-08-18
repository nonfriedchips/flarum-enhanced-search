<?php

declare(strict_types=1);

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use NonFriedChips\EnhancedSearch\Index\Backend\EncodedNgrams;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackendFactory;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

return [
    'up' => function (Builder $schema): void {
        $connection = $schema->getConnection();
        $text = new UnicodeText();
        $backend = (new SearchIndexBackendFactory(
            $connection,
            new EncodedNgrams($text)
        ))->make();
        $backend->assertCompatible();

        if ($schema->hasTable(DocumentIndexer::TABLE)) {
            // A previous interrupted enable can leave an untracked table
            // without its FULLTEXT index. This table contains derived data
            // only, so rebuilding it from the source tables is the safest
            // idempotent recovery.
            $schema->drop(DocumentIndexer::TABLE);
        }

        try {
            $schema->create(DocumentIndexer::TABLE, function (Blueprint $table) use ($backend): void {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('model_type', 16);
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('discussion_id')->nullable();
                $table->mediumText('content');
                $table->boolean('is_normalized')->default(true);
                $table->timestamp('updated_at')->nullable();

                $table->unique(['model_type', 'model_id'], 'enhanced_search_model_unique');
                $table->index(['model_type', 'discussion_id'], 'enhanced_search_discussion_lookup');
                $backend->configureTable($table);
            });

            $indexer = new DocumentIndexer($connection, $text, $backend);

            // Use exactly the same formatter and normalizer as live updates.
            // This avoids indexing raw formatter XML that a renderer might
            // intentionally hide from readers. Build before FULLTEXT creation
            // so MySQL can construct the index once after the bulk import.
            Discussion::query()->chunkById(200, function ($discussions) use ($indexer): void {
                foreach ($discussions as $discussion) {
                    $indexer->indexDiscussion($discussion);
                }
            });

            Post::query()->where('type', 'comment')->chunkById(200, function ($posts) use ($indexer): void {
                foreach ($posts as $post) {
                    $indexer->indexPost($post);
                }
            });

            User::query()->chunkById(200, function ($users) use ($indexer): void {
                foreach ($users as $user) {
                    $indexer->indexUser($user);
                }
            });

            $backend->createFulltextIndex(DocumentIndexer::TABLE);
            $connection->table('settings')->upsert(
                [[
                    'key' => SearchOptions::PREFIX.'index_dirty',
                    'value' => '0',
                ]],
                ['key'],
                ['value']
            );
        } catch (Throwable $exception) {
            $schema->dropIfExists(DocumentIndexer::TABLE);

            throw new RuntimeException(
                'Unable to build the '.$backend->name().' index required by Enhanced Search.',
                0,
                $exception
            );
        }
    },

    'down' => function (Builder $schema): void {
        $schema->dropIfExists(DocumentIndexer::TABLE);
        $schema->getConnection()->table('settings')
            ->where('key', SearchOptions::PREFIX.'index_dirty')
            ->delete();
    },
];
