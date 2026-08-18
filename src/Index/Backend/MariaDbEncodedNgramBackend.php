<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use NonFriedChips\EnhancedSearch\Search\QueryPlan;
use RuntimeException;

final class MariaDbEncodedNgramBackend implements SearchIndexBackend
{
    public const MINIMUM_VERSION = '10.0.5';
    public const TOKEN_COLUMN = 'ngram_terms';

    private ConnectionInterface $connection;
    private EncodedNgrams $ngrams;
    private string $serverVersion;

    public function __construct(
        ConnectionInterface $connection,
        EncodedNgrams $ngrams,
        string $serverVersion
    ) {
        $this->connection = $connection;
        $this->ngrams = $ngrams;
        $this->serverVersion = $serverVersion;
    }

    public function name(): string
    {
        return 'MariaDB encoded ngram';
    }

    public function assertCompatible(): void
    {
        $version = SearchIndexBackendFactory::mariaDbVersion($this->serverVersion);

        if ($version === null || version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new RuntimeException(
                'Enhanced Search requires MariaDB '.self::MINIMUM_VERSION.
                ' or newer with InnoDB FULLTEXT support; the server reports '.
                ($this->serverVersion !== '' ? $this->serverVersion : 'an unknown version').'.'
            );
        }

        $row = $this->connection->selectOne(
            'SELECT @@innodb_ft_min_token_size AS min_token_size, '.
            '@@innodb_ft_max_token_size AS max_token_size'
        );
        $minimum = is_object($row)
            ? (int) ($row->min_token_size ?? 0)
            : (is_array($row) ? (int) ($row['min_token_size'] ?? 0) : 0);
        $maximum = is_object($row)
            ? (int) ($row->max_token_size ?? 0)
            : (is_array($row) ? (int) ($row['max_token_size'] ?? 0) : 0);

        if ($minimum > EncodedNgrams::TOKEN_LENGTH || $maximum < EncodedNgrams::TOKEN_LENGTH) {
            throw new RuntimeException(
                'Enhanced Search requires MariaDB InnoDB FULLTEXT token bounds to include '.
                EncodedNgrams::TOKEN_LENGTH.
                " characters; the server reports min={$minimum}, max={$maximum}."
            );
        }
    }

    public function configureTable(Blueprint $table): void
    {
        $table->mediumText(self::TOKEN_COLUMN);
    }

    public function indexPayload(string $normalized): array
    {
        return [self::TOKEN_COLUMN => $this->ngrams->document($normalized)];
    }

    public function fulltextColumn(): string
    {
        return self::TOKEN_COLUMN;
    }

    public function fulltextIndexName(): string
    {
        return 'enhanced_search_terms_fulltext';
    }

    public function query(QueryPlan $plan, array $transpositionVariants): FulltextQuery
    {
        $query = $this->ngrams->query($plan->normalized());

        return new FulltextQuery(
            $query,
            $query,
            true,
            $this->ngrams->phraseQuery($transpositionVariants)
        );
    }

    public function createFulltextIndex(string $tableName): void
    {
        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($tableName);
        $tokens = $grammar->wrap($this->fulltextColumn());
        $index = $grammar->wrap($this->fulltextIndexName());

        $this->connection->statement(
            "ALTER TABLE {$table} ADD FULLTEXT INDEX {$index} ({$tokens})"
        );
    }
}
