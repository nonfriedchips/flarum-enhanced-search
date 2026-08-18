<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use NonFriedChips\EnhancedSearch\Search\QueryPlan;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use RuntimeException;

final class MySqlNgramBackend implements SearchIndexBackend
{
    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function name(): string
    {
        return 'Oracle MySQL ngram';
    }

    public function assertCompatible(): void
    {
        $row = $this->connection->selectOne('SELECT @@ngram_token_size AS token_size');
        $tokenSize = is_object($row)
            ? (int) ($row->token_size ?? 0)
            : (is_array($row) ? (int) ($row['token_size'] ?? 0) : 0);

        if ($tokenSize !== SearchOptions::NGRAM_TOKEN_SIZE) {
            throw new RuntimeException(
                'Enhanced Search requires MySQL ngram_token_size='.
                SearchOptions::NGRAM_TOKEN_SIZE.
                "; the server currently reports {$tokenSize}."
            );
        }
    }

    public function configureTable(Blueprint $table): void
    {
        // The normalized content column is indexed directly by MySQL's ngram parser.
    }

    public function indexPayload(string $normalized): array
    {
        return [];
    }

    public function fulltextColumn(): string
    {
        return 'content';
    }

    public function fulltextIndexName(): string
    {
        return 'enhanced_search_content_ngram';
    }

    public function query(QueryPlan $plan, array $transpositionVariants): FulltextQuery
    {
        $phrases = array_map(static function (string $variant): string {
            return '"'.$variant.'"';
        }, $transpositionVariants);

        return new FulltextQuery(
            $plan->normalized(),
            $plan->normalized(),
            false,
            implode(' ', $phrases)
        );
    }

    public function createFulltextIndex(string $tableName): void
    {
        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($tableName);
        $content = $grammar->wrap($this->fulltextColumn());
        $index = $grammar->wrap($this->fulltextIndexName());

        $this->connection->statement(
            "ALTER TABLE {$table} ADD FULLTEXT INDEX {$index} ({$content}) WITH PARSER ngram"
        );
    }
}
