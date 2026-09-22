<?php

namespace Avocadesign\StatamicTools;

use Avocadesign\StatamicTools\Console\CheckName;
use Avocadesign\StatamicTools\Console\SiteCatalogue;
use Avocadesign\StatamicTools\Console\SiteCheck;
use Avocadesign\StatamicTools\Console\LibraryInstall;
use Avocadesign\StatamicTools\Console\LibraryList;
use Avocadesign\StatamicTools\Console\MakeCollection;
use Avocadesign\StatamicTools\Console\SiteInstall;
use Avocadesign\StatamicTools\Console\SitePermissions;
use Avocadesign\StatamicTools\Permissions\GrantEditorAccess;
use Statamic\Events\AssetContainerCreated;
use Statamic\Events\CollectionCreated;
use Statamic\Events\GlobalSetCreated;
use Statamic\Events\NavCreated;
use Statamic\Events\TaxonomyCreated;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $commands = [
        CheckName::class,
        LibraryInstall::class,
        LibraryList::class,
        MakeCollection::class,
        SiteInstall::class,
        SiteCheck::class,
        SiteCatalogue::class,
        SitePermissions::class,
    ];

    // A structure created in the control panel or through Statamic's PHP API gives the editor role its permissions.
    protected $listen = [
        CollectionCreated::class => [GrantEditorAccess::class],
        TaxonomyCreated::class => [GrantEditorAccess::class],
        NavCreated::class => [GrantEditorAccess::class],
        GlobalSetCreated::class => [GrantEditorAccess::class],
        AssetContainerCreated::class => [GrantEditorAccess::class],
    ];

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/statamic-tools.php', 'statamic-tools');

        // The /site pages must never be served from the static cache.
        $prefix = config('statamic-tools.site.prefix', 'site');
        $urls = config('statamic.static_caching.exclude.urls', []);
        if (is_array($urls)) {
            config()->set('statamic.static_caching.exclude.urls', array_values(array_unique([...$urls, "/{$prefix}", "/{$prefix}/*"])));
        }
    }
}
