<?php

namespace Avocadesign\StatamicTools\Site;

use Illuminate\Support\Facades\Process;

/**
 * Runs the Stache refresh, and other artisan commands, in a separate PHP process. A command that has just written collections or entries can't do it
 * in its own process: Statamic loaded the collections there before the new files existed, so clearing or warming in
 * place leaves the new entries without their collection until a fresh process rebuilds the cache.
 */
final class StacheRefresh
{
    /** Null when it worked, otherwise the end of the process output, to show why. */
    public static function run(): ?string
    {
        if (app()->runningUnitTests()) {
            return null;
        }
        // With the file watcher on, clearing reads the new entry files before their new collection exists in the
        // cache, and fails, so the watcher is off. It runs twice: the first refresh adds a new collection to the cache,
        // but still rebuilds the entry stores of the collections it started with, so the second finds the new entries.
        foreach ([1, 2] as $pass) {
            [$ok, $output] = self::artisan(['statamic:stache:refresh', '--no-interaction'], ['STATAMIC_STACHE_WATCHER' => 'false']);
            if (! $ok) {
                return $output;
            }
        }

        return null;
    }

    /**
     * An artisan command in a fresh PHP process, so it sees files this process wrote after Statamic loaded. Unit tests
     * run it in-process instead.
     *
     * @param  array<int, string>  $arguments  the command name, then flags such as --no-interaction
     * @param  array<string, string>  $env  environment variables for the process
     * @return array{0: bool, 1: string} whether it worked, and the end of its output
     */
    public static function artisan(array $arguments, array $env = []): array
    {
        $command = array_shift($arguments);
        if (app()->runningUnitTests()) {
            $code = \Illuminate\Support\Facades\Artisan::call($command, array_fill_keys($arguments, true));

            return [$code === 0, trim(\Illuminate\Support\Facades\Artisan::output())];
        }
        $result = Process::path(base_path())->env($env)->timeout(300)->run([PHP_BINARY, 'artisan', $command, ...$arguments]);

        return [$result->successful(), trim(substr($result->output()."\n".$result->errorOutput(), -1200))];
    }
}
