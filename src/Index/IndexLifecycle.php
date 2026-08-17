<?php

declare(strict_types=1);

namespace NonFriedChips\EnhancedSearch\Index;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use NonFriedChips\EnhancedSearch\Search\SearchOptions;

final class IndexLifecycle implements ExtenderInterface, LifecycleInterface
{
    public function extend(Container $container, ?Extension $extension = null)
    {
        // Lifecycle-only extender.
    }

    public function onEnable(Container $container, Extension $extension)
    {
        // The initial migration creates a complete index and clears the dirty
        // marker. Re-enabling without a migration intentionally preserves a
        // marker set by onDisable, forcing native-only search until reindex.
    }

    public function onDisable(Container $container, Extension $extension)
    {
        $container->make(SettingsRepositoryInterface::class)
            ->set(SearchOptions::PREFIX.'index_dirty', '1');
    }
}
