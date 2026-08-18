<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index\Backend;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class SearchIndexBackendFactory
{
    private ConnectionInterface $connection;
    private EncodedNgrams $ngrams;

    public function __construct(ConnectionInterface $connection, EncodedNgrams $ngrams)
    {
        $this->connection = $connection;
        $this->ngrams = $ngrams;
    }

    public function make(): SearchIndexBackend
    {
        if ($this->connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Enhanced Search 1.x requires Oracle MySQL or MariaDB.');
        }

        $row = $this->connection->selectOne('SELECT VERSION() AS server_version');
        $serverVersion = is_object($row)
            ? (string) ($row->server_version ?? '')
            : (is_array($row) ? (string) ($row['server_version'] ?? '') : '');

        if (self::isMariaDb($serverVersion)) {
            return new MariaDbEncodedNgramBackend($this->connection, $this->ngrams, $serverVersion);
        }

        return new MySqlNgramBackend($this->connection);
    }

    public static function isMariaDb(string $serverVersion): bool
    {
        return stripos($serverVersion, 'mariadb') !== false;
    }

    public static function mariaDbVersion(string $serverVersion): ?string
    {
        $mariaDbPosition = stripos($serverVersion, 'mariadb');

        if ($mariaDbPosition === false) {
            return null;
        }

        $prefix = substr($serverVersion, 0, $mariaDbPosition);

        if (preg_match_all('/\d+\.\d+(?:\.\d+)?/', $prefix, $matches) > 0) {
            return $matches[0][count($matches[0]) - 1];
        }

        if (preg_match('/\d+\.\d+(?:\.\d+)?/', substr($serverVersion, $mariaDbPosition), $matches) === 1) {
            return $matches[0];
        }

        return null;
    }
}
