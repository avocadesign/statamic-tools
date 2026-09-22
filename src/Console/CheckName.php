<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Collections\CollectionSpec;
use Avocadesign\StatamicTools\Library\Names;
use Illuminate\Console\Command;

/**
 * Says whether a handle is free for a new block, set or collection. It is taken when Avoca's library has an item the site
 * hasn't installed with that handle, which the recipe installs rather than rebuilding, or when the site already uses it.
 * Similar names are listed but never make a handle taken.
 */
class CheckName extends Command
{
    protected $signature = 'avoca:check-name
        {handle : The handle for a new block, set or collection}
        {--display= : The name editors will see, to find similar names}
        {--json : Report as JSON}';

    protected $description = "Check a handle for a new block, set or collection against Avoca's library and this site.";

    public function handle(): int
    {
        $handle = (string) $this->argument('handle');
        $display = $this->option('display') === null ? null : trim((string) $this->option('display'));
        $json = (bool) $this->option('json');

        if (($problem = CollectionSpec::handleProblem($handle)) !== null) {
            $json
                ? $this->line((string) json_encode(['handle' => $handle, 'available' => false, 'library' => [], 'site' => [], 'errors' => [$problem]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->error($problem);

            return self::FAILURE;
        }

        $names = app()->bound(Names::class) ? app(Names::class) : Names::make();
        $library = $names->check($handle, $display);
        $site = $names->inSite($handle, $display);
        $taken = Names::blocking($library) !== [] || in_array(Names::CLASH, array_column($site, 'level'), true);

        if ($json) {
            $this->line((string) json_encode(['handle' => $handle, 'available' => ! $taken, 'library' => $library, 'site' => $site, 'errors' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $taken ? self::FAILURE : self::SUCCESS;
        }

        foreach ($library as $match) {
            $this->line('  '.($match['level'] === Names::CLASH ? '<fg=red>✗</>' : '<fg=yellow>!</>').' '.Names::describe($match));
        }
        foreach ($site as $match) {
            $this->line('  '.($match['level'] === Names::CLASH ? '<fg=red>✗</>' : '<fg=yellow>!</>')." This site already has a {$match['kind']} called {$match['handle']}".($match['level'] === Names::SIMILAR ? ', a similar name.' : '.'));
        }

        if ($taken) {
            $this->error("{$handle} is taken.");

            return self::FAILURE;
        }
        $this->info($library === [] && $site === []
            ? "{$handle} is free: nothing in Avoca's library or this site uses it or a similar name."
            : "{$handle} is free, but look at the similar names above first.");

        return self::SUCCESS;
    }
}
