<?php

declare(strict_types=1);

use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\User\Search\UserSearcher;
use NonFriedChips\EnhancedSearch\Console\ReindexCommand;
use NonFriedChips\EnhancedSearch\Search\DiscussionGambit;
use NonFriedChips\EnhancedSearch\Search\UserGambit;

set_exception_handler(static function (Throwable $exception): void {
    $message = preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? 'unknown boot error';
    fwrite(STDERR, 'FAIL: '.get_class($exception).': '.substr($message, 0, 500).PHP_EOL);
    exit(1);
});

$root = getenv('FLARUM_ROOT') ?: dirname(__DIR__, 3);
$site = require $root.'/site.php';

// Enabled extensions are loaded by Flarum automatically. Use --manual only
// when checking an installed-but-disabled source tree; loading the same
// extenders twice would correctly reject duplicate immutable settings.
if (in_array('--manual', $argv, true)) {
    $site->extendWith(require dirname(__DIR__).'/extend.php');
}

$app = $site->bootApp();
$container = $app->getContainer();

$fulltext = $container->make('flarum.simple_search.fulltext_gambits');
$commands = $container->make('flarum.console.commands');

if (($fulltext[DiscussionSearcher::class] ?? null) !== DiscussionGambit::class) {
    fwrite(STDERR, "FAIL: discussion full-text gambit was not registered.\n");
    exit(1);
}

if (($fulltext[UserSearcher::class] ?? null) !== UserGambit::class) {
    fwrite(STDERR, "FAIL: user full-text gambit was not registered.\n");
    exit(1);
}

if (! in_array(ReindexCommand::class, $commands, true)) {
    fwrite(STDERR, "FAIL: reindex command was not registered.\n");
    exit(1);
}

$container->make(DiscussionGambit::class);
$container->make(UserGambit::class);
$container->make(ReindexCommand::class);

fwrite(STDOUT, "Enhanced Search boot smoke test passed.\n");
