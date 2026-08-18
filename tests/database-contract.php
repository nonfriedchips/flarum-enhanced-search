<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use NonFriedChips\EnhancedSearch\Index\Backend\EncodedNgrams;
use NonFriedChips\EnhancedSearch\Index\Backend\FulltextQuery;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackend;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackendFactory;
use NonFriedChips\EnhancedSearch\Search\QueryPlanner;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

require __DIR__.'/bootstrap.php';

set_exception_handler(static function (Throwable $exception): void {
    $message = preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? 'unknown database error';
    fwrite(STDERR, 'FAIL: '.get_class($exception).': '.substr($message, 0, 500).PHP_EOL);
    exit(1);
});

/** @return string */
function requiredEnvironment(string $name)
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        fwrite(STDERR, "FAIL: {$name} is required for the destructive, isolated database contract test.\n");
        exit(2);
    }

    return $value;
}

/** @param mixed $actual @param mixed $expected */
function requireSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message.'; expected '.var_export($expected, true).', got '.var_export($actual, true)
        );
    }
}

function requireTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return mixed */
function rowValue($row, string $key)
{
    if (is_object($row)) {
        return $row->{$key} ?? null;
    }

    return is_array($row) ? ($row[$key] ?? null) : null;
}

/** @return int[] */
function matchedIds(
    ConnectionInterface $connection,
    string $table,
    SearchIndexBackend $backend,
    FulltextQuery $query,
    bool $transpositionsOnly = false
): array {
    $builder = $connection->table($table);
    $column = $builder->getGrammar()->wrap($backend->fulltextColumn());
    $candidateMode = $query->candidateUsesBooleanMode()
        ? 'IN BOOLEAN MODE'
        : 'IN NATURAL LANGUAGE MODE';
    $candidateMatch = "MATCH ({$column}) AGAINST (? {$candidateMode})";
    $swapMatch = "MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)";

    $builder->select('id');

    if ($transpositionsOnly) {
        if ($query->transpositionText() === '') {
            return [];
        }

        $builder->whereRaw($swapMatch, [$query->transpositionText()]);
    } else {
        $builder->where(function (Builder $where) use ($candidateMatch, $swapMatch, $query): void {
            $where->whereRaw($candidateMatch, [$query->candidateText()]);

            if ($query->transpositionText() !== '') {
                $where->orWhereRaw($swapMatch, [$query->transpositionText()]);
            }
        });
    }

    return array_map(static function ($row): int {
        return (int) rowValue($row, 'id');
    }, $builder->orderBy('id')->get()->all());
}

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => requiredEnvironment('ENHANCED_SEARCH_DB_HOST'),
    'port' => (int) requiredEnvironment('ENHANCED_SEARCH_DB_PORT'),
    'database' => requiredEnvironment('ENHANCED_SEARCH_DB_DATABASE'),
    'username' => requiredEnvironment('ENHANCED_SEARCH_DB_USERNAME'),
    'password' => requiredEnvironment('ENHANCED_SEARCH_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
]);

/** @var ConnectionInterface $connection */
$connection = $capsule->getConnection();
$text = new UnicodeText();
$ngrams = new EncodedNgrams($text);
$backend = (new SearchIndexBackendFactory($connection, $ngrams))->make();
$backend->assertCompatible();
$expectedDatabase = strtolower(requiredEnvironment('ENHANCED_SEARCH_EXPECTED_DATABASE'));
$actualDatabase = str_starts_with(strtolower($backend->name()), 'mariadb') ? 'mariadb' : 'mysql';
requireSame($expectedDatabase, $actualDatabase, 'database family detection is incorrect');

$table = 'enhanced_search_contract_'.bin2hex(random_bytes(6));
$schema = $connection->getSchemaBuilder();
$planner = new QueryPlanner($text);
$options = new SearchOptions(true, true, 4, 8, 15, 200, 2);

try {
    $schema->create($table, function (Blueprint $definition) use ($backend): void {
        $definition->engine = 'InnoDB';
        $definition->increments('id');
        $definition->mediumText('content');
        $backend->configureTable($definition);
    });

    $documents = [
        1 => '公共 模糊搜索',
        2 => '公共 模胡搜索',
        3 => '公共 论坛甲丙乙丁实践',
        4 => '公共 论坛甲丙丁乙实践',
    ];

    foreach ($documents as $id => $content) {
        $normalized = $text->normalize($content);
        $connection->table($table)->insert(array_merge([
            'id' => $id,
            'content' => $normalized,
        ], $backend->indexPayload($normalized)));
    }

    $backend->createFulltextIndex($table);
    requireTrue($schema->hasColumn($table, $backend->fulltextColumn()), 'backend FULLTEXT column is missing');

    $exactPlan = $planner->plan('模糊搜索', $options);
    $exactQuery = $backend->query($exactPlan, []);
    requireTrue(in_array(1, matchedIds($connection, $table, $backend, $exactQuery), true), 'exact CJK text was not recalled');

    $typoPlan = $planner->plan('模胡搜索', $options);
    $typoQuery = $backend->query($typoPlan, []);
    requireTrue(in_array(1, matchedIds($connection, $table, $backend, $typoQuery), true), 'shared-bigram typo candidate was not recalled');

    $commonPlan = $planner->plan('公共', $options);
    $commonQuery = $backend->query($commonPlan, []);
    requireSame([1, 2, 3, 4], matchedIds($connection, $table, $backend, $commonQuery), 'a token present in every InnoDB row was dropped');

    $transpositionPlan = $planner->plan('甲乙丙丁', $options);
    $variants = $text->adjacentTranspositions('甲乙丙丁');
    $transpositionQuery = $backend->query($transpositionPlan, $variants);
    $phraseMatches = matchedIds($connection, $table, $backend, $transpositionQuery, true);
    requireTrue(in_array(3, $phraseMatches, true), 'exact adjacent-transposition phrase was not recalled');
    requireTrue(! in_array(4, $phraseMatches, true), 'Boolean phrase matched tokens in the wrong order');

    $replacement = $text->normalize('公共 全新检索');
    $connection->table($table)->where('id', 2)->update(array_merge([
        'content' => $replacement,
    ], $backend->indexPayload($replacement)));
    requireTrue(
        ! in_array(2, matchedIds($connection, $table, $backend, $typoQuery), true),
        'FULLTEXT update retained stale encoded terms'
    );
    $replacementQuery = $backend->query($planner->plan('全新检索', $options), []);
    requireTrue(
        in_array(2, matchedIds($connection, $table, $backend, $replacementQuery), true),
        'FULLTEXT update did not index replacement terms'
    );

    $connection->table($table)->where('id', 4)->delete();
    requireSame(
        [1, 2, 3],
        matchedIds($connection, $table, $backend, $commonQuery),
        'FULLTEXT delete retained a removed document'
    );

    $grammar = $connection->getQueryGrammar();
    $wrappedTable = $grammar->wrapTable($table);
    $wrappedColumn = $grammar->wrap($backend->fulltextColumn());
    $mode = $commonQuery->candidateUsesBooleanMode()
        ? 'IN BOOLEAN MODE'
        : 'IN NATURAL LANGUAGE MODE';
    $explain = $connection->selectOne(
        "EXPLAIN SELECT id FROM {$wrappedTable} ".
        "WHERE MATCH ({$wrappedColumn}) AGAINST (? {$mode})",
        [$commonQuery->candidateText()]
    );
    requireSame('fulltext', strtolower((string) rowValue($explain, 'type')), 'candidate query did not use FULLTEXT access');
    requireSame($backend->fulltextIndexName(), (string) rowValue($explain, 'key'), 'candidate query used the wrong FULLTEXT index');

    fwrite(STDOUT, 'Enhanced Search '.$backend->name()." database contract tests passed.\n");
} finally {
    $schema->dropIfExists($table);
}
