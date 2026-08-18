<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Container\Container;
use NonFriedChips\EnhancedSearch\Index\Backend\EncodedNgrams;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackend;
use NonFriedChips\EnhancedSearch\Index\Backend\SearchIndexBackendFactory;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;
use Psr\Log\LoggerInterface;

final class EnhancedSearchServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SearchIndexBackend::class, function (Container $container): SearchIndexBackend {
            return (new SearchIndexBackendFactory(
                $container->make(ConnectionInterface::class),
                $container->make(EncodedNgrams::class)
            ))->make();
        });

        $this->container->resolving('flarum.assets.forum', function (Assets $assets): void {
            $settings = $this->container->make(SettingsRepositoryInterface::class);
            $length = SearchOptions::fromSettings($settings)->suggestionMinLength;

            $assets->js(function (SourceCollector $sources) use ($length): void {
                $sources->addString(static function () use ($length): string {
                    return "app.initializers.add('enhanced-search-min-length',function(){flarum.core.compat['components/Search'].MIN_SEARCH_LEN={$length}});";
                });
            });
        });
    }

    public function boot(ExtensionManager $extensions, LoggerInterface $logger): void
    {
        if ($extensions->isEnabled('clarkwinkelmann-scout')) {
            $logger->warning(
                'Enhanced Search and Scout Search are both enabled. Flarum 1.x has one full-text search slot; disable Scout to avoid duplicate indexing and ambiguous configuration.'
            );
        }

        $settings = $this->container->make(SettingsRepositoryInterface::class);

        if (SearchOptions::fromSettings($settings)->indexDirty) {
            $logger->warning(
                'Enhanced Search index is marked dirty. Fuzzy retrieval is disabled until enhanced-search:reindex completes.'
            );
        }
    }
}
