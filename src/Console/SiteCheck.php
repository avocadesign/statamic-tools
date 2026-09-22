<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Library\Names;
use Avocadesign\StatamicTools\Permissions\EditorAccess;
use Avocadesign\StatamicTools\Permissions\PermissionSets;
use Avocadesign\StatamicTools\Site\Blocks;
use Avocadesign\StatamicTools\Site\Catalogue;
use Avocadesign\StatamicTools\Site\Docs;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Proves the /site pages and the site's AI block catalogue still reflect the fieldsets: every block and set
 * renders, and with --strict every one has guidance and the committed catalogue is current. Meant for CI. It also
 * warns, and never fails, when the editor role is missing permissions for a structure that isn't opted out, or when a
 * library item the site hasn't installed uses a handle the site already has.
 */
class SiteCheck extends Command
{
    protected $signature = 'avoca:site:check
        {--strict : Fail on missing guidance or an out-of-date AI block catalogue}
        {--stubs : Write a guidance stub for every block or set that has none}';

    protected $description = 'Check the /site pages, guidance files and AI block catalogue against the fieldsets, and the editor role against the structures.';

    public function handle(): int
    {
        $prefix = config('statamic-tools.site.prefix', 'site');
        $failures = 0;
        $warnings = 0;

        $blocks = Blocks::pageBuilder();
        $sets = Blocks::article();

        foreach ([['blocks', $blocks], ['sets', $sets]] as [$kind, $items]) {
            foreach ($items as $handle => $set) {
                if (($set['partial']['origin'] ?? null) === null) {
                    $this->line("  <fg=red>✗</> {$kind}/{$handle}: no partial renders it");
                    $failures++;
                }
                $docs = Docs::for($kind, $handle);
                if (! $docs['exists']) {
                    if ($this->option('stubs')) {
                        $path = Docs::path($kind, $handle);
                        if (! is_dir(dirname($path))) {
                            mkdir(dirname($path), 0755, true);
                        }
                        file_put_contents($path, Docs::stub($kind, $handle, $set['display'], $set['instructions']));
                        $this->line("  <fg=yellow>+</> wrote {$docs['path']}");
                    } elseif ($this->option('strict')) {
                        $this->line("  <fg=red>✗</> {$kind}/{$handle}: no guidance at {$docs['path']}");
                        $failures++;
                    } else {
                        $this->line("  <fg=yellow>!</> {$kind}/{$handle}: no guidance at {$docs['path']}");
                        $warnings++;
                    }
                }
            }
        }

        // Site-wide design guidance: the rules every block shares, such as colour schemes.
        $design = Docs::design();
        if (! $design['exists']) {
            if ($this->option('stubs')) {
                if (! is_dir(dirname(Docs::designPath()))) {
                    mkdir(dirname(Docs::designPath()), 0755, true);
                }
                file_put_contents(Docs::designPath(), Docs::designStub());
                $this->line("  <fg=yellow>+</> wrote {$design['path']}");
            } elseif ($this->option('strict')) {
                $this->line("  <fg=red>✗</> no design guidance at {$design['path']}");
                $failures++;
            } else {
                $this->line("  <fg=yellow>!</> no design guidance at {$design['path']}");
                $warnings++;
            }
        }

        // Orphaned guidance: a file for something that no longer exists.
        foreach ([['blocks', $blocks], ['sets', $sets]] as [$kind, $items]) {
            foreach (glob(dirname(Docs::path($kind, 'x')).'/*.md') ?: [] as $file) {
                $handle = basename($file, '.md');
                if (! isset($items[$handle])) {
                    $this->line("  <fg=red>✗</> {$kind}/{$handle}.md describes a {$kind} that no longer exists");
                    $failures++;
                }
            }
        }

        // A library item the site hasn't installed that uses a handle the site already has would collide when installed.
        // Always a warning: the site works as it is.
        foreach (Names::make()->siteClashes() as $clash) {
            $this->line('  <fg=yellow>!</> '.Names::describeSiteClash($clash));
            $warnings++;
        }

        // The AI block catalogue the site commits must match what its fieldsets and guidance produce now.
        $catalogue = Catalogue::relativePath();
        $problem = match (true) {
            ! is_file(Catalogue::path()) => "no AI block catalogue at {$catalogue}",
            (string) file_get_contents(Catalogue::path()) !== Catalogue::build() => "{$catalogue} is out of date",
            default => null,
        };
        if ($problem === null) {
            $this->line("  <fg=green>✓</> {$catalogue} is up to date");
        } elseif ($this->option('strict')) {
            $this->line("  <fg=red>✗</> {$problem}: run php please avoca:site:catalogue");
            $failures++;
        } else {
            $this->line("  <fg=yellow>!</> {$problem}: run php please avoca:site:catalogue");
            $warnings++;
        }

        // The editor role holds the permissions for every structure that isn't opted out, so the site is ready for editors
        // when it moves to Statamic Pro. Always a warning: roles do nothing on Core, so --strict never fails on it.
        $warnings += $this->checkEditorAccess();

        // Render the pages the way a browser would and make sure everything appears.
        foreach (['style', 'content'] as $page) {
            $response = app()->handle(Request::create("/{$prefix}/{$page}", 'GET'));
            if ($response->getStatusCode() !== 200) {
                $this->line("  <fg=red>✗</> /{$prefix}/{$page} returned HTTP {$response->getStatusCode()}");
                $failures++;
                continue;
            }
            $html = (string) $response->getContent();
            if ($page === 'content') {
                foreach ([['block', $blocks], ['set', $sets]] as [$attr, $items]) {
                    foreach (array_keys($items) as $handle) {
                        if (! str_contains($html, "data-sk-{$attr}=\"{$handle}\"")) {
                            $this->line("  <fg=red>✗</> /{$prefix}/content does not render {$attr} {$handle}");
                            $failures++;
                        }
                    }
                }
                $options = substr_count($html, 'data-sk-option=');
                $this->line("  <fg=green>✓</> /{$prefix}/content: ".count($blocks).' blocks, '.count($sets)." sets, {$options} options");
            } else {
                $this->line("  <fg=green>✓</> /{$prefix}/style: ".substr_count($html, 'class="sk-swatch"').' colours, '.substr_count($html, 'data-sk-scheme=').' schemes, '.substr_count($html, 'data-sk-stack=').' stack values');
            }
        }

        $this->newLine();
        if ($failures) {
            $this->error("{$failures} problem(s)".($warnings ? ", {$warnings} warning(s)" : ''));

            return self::FAILURE;
        }
        $this->info('Site pages are in step with the fieldsets'.($warnings ? " ({$warnings} warning(s))" : '.'));

        return self::SUCCESS;
    }

    /** Writes the lines for the editor role's permissions, and returns how many of them are warnings. */
    private function checkEditorAccess(): int
    {
        $access = EditorAccess::resolve();
        $role = $access->role();
        $warnings = [];
        if (($problems = $access->problems()) !== []) {
            $warnings = array_map(fn (string $problem) => "editor permissions were not checked, because {$problem}", $problems);
        } elseif (! $access->roleExists()) {
            $warnings[] = "the site has no {$role} role, so editor permissions were not checked";
        } else {
            $structures = EditorAccess::structures();
            $plan = $access->plan($structures);
            $missing = array_filter($plan, fn (array $step) => $step['status'] === 'add');
            foreach ($missing as $step) {
                $count = count($step['add']);
                $warnings[] = "the {$role} role is missing {$count} ".($count === 1 ? 'permission' : 'permissions').' for '.PermissionSets::name($step['type'], $step['handle'])
                    .": run php please avoca:site:permissions, or opt it out in {$access->optOutsPath()}";
            }
            if ($missing === []) {
                $optedOut = count(array_filter($plan, fn (array $step) => $step['status'] === 'opted_out'));
                $covered = count($plan) - $optedOut;
                $this->line("  <fg=green>✓</> the {$role} role has the permissions for {$covered} ".($covered === 1 ? 'structure' : 'structures').($optedOut ? ", with {$optedOut} opted out" : ''));
            }
            foreach ($access->strays($structures) as $stray) {
                $warnings[] = "{$access->optOutsPath()} opts out {$stray}, which the site does not have";
            }
        }
        foreach ($warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        return count($warnings);
    }
}
