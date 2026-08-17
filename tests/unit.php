<?php

declare(strict_types=1);

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Extension\Extension;
use Illuminate\Container\Container;
use NonFriedChips\EnhancedSearch\Index\IndexLifecycle;
use NonFriedChips\EnhancedSearch\Search\DamerauLevenshtein;
use NonFriedChips\EnhancedSearch\Search\FuzzyScorer;
use NonFriedChips\EnhancedSearch\Search\QueryPlanner;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Support\LikePattern;
use NonFriedChips\EnhancedSearch\Support\UnicodeText;

require __DIR__.'/bootstrap.php';

final class ArraySettings implements SettingsRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get($key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    public function set($key, $value)
    {
        $this->values[$key] = $value;
    }

    public function delete($keyLike)
    {
        unset($this->values[$keyLike]);
    }
}

/** @param mixed $actual @param mixed $expected */
function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
        exit(1);
    }
}

function assertNear(float $expected, float $actual, float $delta, string $message): void
{
    if (abs($expected - $actual) > $delta) {
        fwrite(STDERR, "FAIL: {$message}\nExpected near: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

function assertGreater(float $left, float $right, string $message): void
{
    if ($left <= $right) {
        fwrite(STDERR, "FAIL: {$message}\n{$left} is not greater than {$right}\n");
        exit(1);
    }
}

$text = new UnicodeText();
$distance = new DamerauLevenshtein($text);
$planner = new QueryPlanner($text);
$scorer = new FuzzyScorer($text, $distance);
$options = new SearchOptions(true, true, 4, 8, 15, 200, 2);

assertSameValue('flarum 搜索', $text->normalize('  Ｆｌａｒｕｍ，搜索！ '), 'NFKC, case folding, punctuation, or spaces were not normalized');
assertSameValue('a b_c', $text->normalize('<b>A</b> &amp; B_C'), 'HTML entities or tags were not normalized safely');
assertSameValue(['乙甲丙丁', '甲丙乙丁', '甲乙丁丙'], $text->adjacentTranspositions('甲乙丙丁'), 'adjacent transposition variants are incorrect');
assertSameValue('%!%!!!_%', LikePattern::contains('%!_'), 'LIKE wildcard escaping is incorrect');

assertSameValue(1, $distance->distance('flarmu', 'flarum'), 'adjacent transposition should cost one edit');
assertSameValue(1, $distance->distance('模胡搜索', '模糊搜索'), 'a Chinese substitution should cost one edit');
assertSameValue(2, $distance->distance('kitten', 'sitting', 1), 'bounded distance should return maximum + 1 when exceeded');
assertSameValue(1, $distance->distance('', 'bb', 0), 'bounded empty-string distance should return maximum + 1');

$exactPlan = $planner->plan('模糊搜索', $options);
$typoPlan = $planner->plan('模胡搜索', $options);
$latinPlan = $planner->plan('flarmu', $options);
$numericPlan = $planner->plan('2024', $options);
$shortPlan = $planner->plan('cat', $options);
$multiPlan = $planner->plan('search engine', $options);
$transposedPlan = $planner->plan('甲乙丙丁', $options);

assertNear(1.0, $scorer->score($exactPlan, '模糊搜索'), 0.0001, 'exact title score should be one');
assertNear(0.98, $scorer->score($exactPlan, '模糊搜索实践'), 0.0001, 'title prefix score is incorrect');
assertNear(0.95, $scorer->score($exactPlan, 'Flarum 的模糊搜索实践'), 0.0001, 'title substring score is incorrect');
assertNear(0.82, $scorer->score($typoPlan, 'Flarum 模糊搜索实践'), 0.0001, 'one Chinese typo was not recovered');
assertNear(0.82, $scorer->score($latinPlan, 'the flarum extension'), 0.0001, 'Latin transposition was not recovered');
assertNear(0.82, $scorer->score($transposedPlan, '论坛甲丙乙丁实践'), 0.0001, 'a no-common-bigram transposition was not recovered');
assertSameValue(0.0, $scorer->score($numericPlan, 'release 2025'), 'numeric-only terms must not use typo tolerance');
assertSameValue(0.0, $scorer->score($shortPlan, 'cut'), 'short terms must not use typo tolerance');
assertGreater(
    $scorer->score($multiPlan, 'A practical search ranking engine for forums'),
    0.90,
    'separated multi-term matches should be accepted'
);

$exactScore = $scorer->score($exactPlan, '模糊搜索');
$prefixScore = $scorer->score($exactPlan, '模糊搜索插件');
$typoScore = $scorer->score($typoPlan, '模糊搜索插件');
assertGreater($exactScore, $prefixScore, 'exact matches must rank above prefixes');
assertGreater($prefixScore, $typoScore, 'prefix matches must rank above typo matches');

$boundedSettings = new ArraySettings([
    SearchOptions::PREFIX.'candidate_limit' => '99999',
    SearchOptions::PREFIX.'one_typo_length' => '1',
    SearchOptions::PREFIX.'two_typo_length' => '2',
    SearchOptions::PREFIX.'suggestion_min_length' => '0',
    SearchOptions::PREFIX.'index_dirty' => '1',
]);
$boundedOptions = SearchOptions::fromSettings($boundedSettings);
assertSameValue(SearchOptions::MAX_CANDIDATES, $boundedOptions->candidateLimit, 'candidate limit was not safely capped');
assertSameValue(3, $boundedOptions->oneTypoLength, 'one-typo threshold was not safely capped');
assertSameValue(5, $boundedOptions->twoTypoLength, 'two-typo threshold was not safely capped');
assertSameValue(2, $boundedOptions->suggestionMinLength, 'suggestion length was not safely capped');
assertSameValue(true, $boundedOptions->indexDirty, 'dirty index setting was not parsed');
$generationOptions = SearchOptions::fromSettings(new ArraySettings([
    SearchOptions::PREFIX.'index_dirty' => 'reindex:test-generation',
]));
assertSameValue(true, $generationOptions->indexDirty, 'an in-progress generation token must be treated as dirty');

$lifecycleSettings = new ArraySettings([]);
$container = new Container();
$container->instance(SettingsRepositoryInterface::class, $lifecycleSettings);
(new IndexLifecycle())->onDisable(
    $container,
    new Extension(dirname(__DIR__), ['name' => 'nonfriedchips/flarum-enhanced-search'])
);
assertSameValue('1', $lifecycleSettings->get(SearchOptions::PREFIX.'index_dirty'), 'disabling did not mark the index dirty');

$longQuery = str_repeat('搜索', 80);
$longPlan = $planner->plan($longQuery, $options);
assertSameValue(SearchOptions::MAX_QUERY_CHARACTERS, $text->length($longPlan->normalized()), 'query length cap was not applied');
assertSameValue(0, $longPlan->allowedEdits($longPlan->terms()[0]), 'very long terms must not trigger expensive fuzzy expansion');

fwrite(STDOUT, "Enhanced Search unit tests passed.\n");
