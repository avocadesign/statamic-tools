<?php

namespace Avocadesign\StatamicTools\Permissions;

use Statamic\Events\AssetContainerCreated;
use Statamic\Events\CollectionCreated;
use Statamic\Events\GlobalSetCreated;
use Statamic\Events\NavCreated;
use Statamic\Events\TaxonomyCreated;

/**
 * Gives the editor role a structure's permissions as soon as Statamic creates it, in the control panel or through its
 * PHP API. It hears only the created events, so saving a structure that already exists never puts back a permission
 * someone took away. It is silent: with no editor role, an opted-out structure or a problem with the opt-outs it adds
 * nothing, and an error goes to the log rather than stopping the structure being created. avoca:site:check warns about
 * anything left missing.
 */
final class GrantEditorAccess
{
    public function handle(CollectionCreated|TaxonomyCreated|NavCreated|GlobalSetCreated|AssetContainerCreated $event): void
    {
        $structure = match (true) {
            $event instanceof CollectionCreated => ['collections', (string) $event->collection->handle()],
            $event instanceof TaxonomyCreated => ['taxonomies', (string) $event->taxonomy->handle()],
            $event instanceof NavCreated => ['navigation', (string) $event->nav->handle()],
            $event instanceof GlobalSetCreated => ['globals', (string) $event->globals->handle()],
            $event instanceof AssetContainerCreated => ['assets', (string) $event->container->handle()],
        };

        try {
            $access = EditorAccess::resolve();
            if ($access->problems() === []) {
                $access->sync([$structure]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
