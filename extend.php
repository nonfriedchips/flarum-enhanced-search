<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch;

use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\User\Search\UserSearcher;
use NonFriedChips\EnhancedSearch\Console\ReindexCommand;
use NonFriedChips\EnhancedSearch\Index\IndexLifecycle;
use NonFriedChips\EnhancedSearch\Listener\UpdateSearchIndex;
use NonFriedChips\EnhancedSearch\Search\DiscussionGambit;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use NonFriedChips\EnhancedSearch\Search\UserGambit;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(EnhancedSearchServiceProvider::class),

    new IndexLifecycle(),

    (new Extend\Settings())
        ->default(SearchOptions::PREFIX.'typo_tolerance', true)
        ->default(SearchOptions::PREFIX.'search_post_content', true)
        ->default(SearchOptions::PREFIX.'one_typo_length', 4)
        ->default(SearchOptions::PREFIX.'two_typo_length', 8)
        ->default(SearchOptions::PREFIX.'native_result_threshold', 15)
        ->default(SearchOptions::PREFIX.'candidate_limit', 200)
        ->default(SearchOptions::PREFIX.'suggestion_min_length', 2)
        ->default(SearchOptions::PREFIX.'index_dirty', false),

    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(DiscussionGambit::class),

    (new Extend\SimpleFlarumSearch(UserSearcher::class))
        ->setFullTextGambit(UserGambit::class),

    (new Extend\Event())
        ->subscribe(UpdateSearchIndex::class),

    (new Extend\Console())
        ->command(ReindexCommand::class),
];
