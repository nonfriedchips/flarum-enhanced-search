<?php

declare(strict_types=1);

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use NonFriedChips\EnhancedSearch\Index\DocumentIndexer;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

return [
    'up' => function (Builder $schema): void {
        if ($schema->hasTable(DocumentIndexer::TABLE)) {
            // A previous interrupted enable can leave an untracked table
            // without its FULLTEXT index. This table contains derived data
            // only, so rebuilding it from the source tables is the safest
            // idempotent recovery.
            $schema->drop(DocumentIndexer::TABLE);
        }

        $connection = $schema->getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Enhanced Search 1.x requires Oracle MySQL with the built-in ngram parser.');
        }

        $versionRow = $connection->selectOne('SELECT VERSION() AS version');
        $version = is_object($versionRow) ? (string) $versionRow->version : '';

        if (stripos($version, 'mariadb') !== false) {
            throw new RuntimeException('Enhanced Search uses MySQL ngram FULLTEXT indexes, which are not supported by MariaDB.');
        }

        $ngramRow = $connection->selectOne('SELECT @@ngram_token_size AS token_size');
        $ngramTokenSize = is_object($ngramRow) ? (int) $ngramRow->token_size : 0;

        if ($ngramTokenSize !== SearchOptions::NGRAM_TOKEN_SIZE) {
            throw new RuntimeException(
                'Enhanced Search requires MySQL ngram_token_size='.
                SearchOptions::NGRAM_TOKEN_SIZE.
                "; the server currently reports {$ngramTokenSize}."
            );
        }

        try {
            $schema->create(DocumentIndexer::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('model_type', 16);
                $table->unsignedBigInteger('model_id');
                $table->unsignedBigInteger('discussion_id')->nullable();
                $table->mediumText('content');
                $table->boolean('is_normalized')->default(true);
                $table->timestamp('updated_at')->nullable();

                $table->unique(['model_type', 'model_id'], 'enhanced_search_model_unique');
                $table->index(['model_type', 'discussion_id'], 'enhanced_search_discussion_lookup');
            });

            $indexer = new DocumentIndexer($connection, new UnicodeText());

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

            $grammar = $connection->getQueryGrammar();
            $table = $grammar->wrapTable(DocumentIndexer::TABLE);
            $content = $grammar->wrap('content');
            $index = $grammar->wrap('enhanced_search_content_ngram');

            $connection->statement("ALTER TABLE {$table} ADD FULLTEXT INDEX {$index} ({$content}) WITH PARSER ngram");
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
                'Unable to build the normalized MySQL ngram index required by Enhanced Search.',
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
