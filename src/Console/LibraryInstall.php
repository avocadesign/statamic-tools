<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Library\Installer;
use Avocadesign\StatamicTools\Library\Library;
use Avocadesign\StatamicTools\Permissions\EditorAccess;
use Illuminate\Console\Command;

/**
 * Installs a library item without asking anything: its files, its page builder or text editor entry in the declared
 * group, its control panel screenshot and permissions. Then it records the version, gives the editor role the
 * permissions for any collection, taxonomy, navigation, global set or asset container the item adds, unless it is
 * opted out, and regenerates the catalogue.
 */
class LibraryInstall extends Command
{
    protected $signature = 'avoca:library:install
        {handle : The library item to install}
        {--dry-run : Show what would change without writing anything}
        {--force : Replace files and fieldset entries that already exist and differ}
        {--no-catalogue : Leave the catalogue to regenerate later}';

    protected $description = "Install an item from Avoca's library into this site.";

    public function handle(): int
    {
        $library = app()->bound(Library::class) ? app(Library::class) : Library::make();
        $handle = (string) $this->argument('handle');
        if (! $item = $library->find($handle)) {
            $this->error("The library has no item called {$handle}. Run php please avoca:library to see what it has.");

            return self::FAILURE;
        }

        $installer = Installer::make($library);
        $this->info("{$item->name()} {$item->version()}, {$item->category}: ".$library->status($item));
        $marks = ['new' => '<fg=green>+</>', 'same' => '<fg=gray>=</>', 'conflict' => '<fg=yellow>!</>', 'error' => '<fg=red>✗</>'];
        foreach ($installer->plan($item) as $step) {
            $note = match ($step['status']) {
                'conflict' => ' <fg=yellow>'.($step['detail'] !== '' ? $step['detail'] : 'exists and differs').', --force replaces it</>',
                'error' => " <fg=red>{$step['detail']}</>",
                default => $step['detail'] !== '' ? " <fg=gray>{$step['detail']}</>" : '',
            };
            $this->line("  {$marks[$step['status']]} {$step['action']} {$step['target']}{$note}");
        }

        // Copied files fire none of Statamic's created events, so the editor role gets the permissions for the structures
        // the item adds here, the way avoca:site:permissions adds them.
        $access = EditorAccess::resolve($library->root());
        $structures = EditorAccess::structuresIn(array_keys($item->files()));

        if ($this->option('dry-run')) {
            $this->editorAccess($access, $structures, dry: true, pending: $item->permissions()[$access->role()] ?? []);
            $this->line('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        try {
            $installer->install($item, (bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error('Nothing was installed.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Installed {$item->name()} {$item->version()} and recorded it in {$library->installedPath()}.");
        $this->editorAccess($access, $structures, dry: false);
        // This process loaded Statamic's collections before the new files existed, and anything that reads them here
        // can cache a broken index. So the Stache refresh, and then the catalogue, both run in fresh PHP processes.
        $failure = \Avocadesign\StatamicTools\Site\StacheRefresh::run();
        $failure === null ? $this->line('Refreshed the Stache.') : $this->warn("Could not refresh the Stache. Run php please stache:refresh.\n{$failure}");
        if (! $this->option('no-catalogue')) {
            [$ok, $output] = \Avocadesign\StatamicTools\Site\StacheRefresh::artisan(['avoca:site:catalogue']);
            $ok ? $this->line($output) : $this->warn("Could not regenerate the catalogue. Run php please avoca:site:catalogue.\n{$output}");
        }
        if ($item->notes() !== '') {
            $this->newLine();
            $this->line($item->notes());
        }
        $this->line('Check its guidance in resources/site, then run php please avoca:site:check --strict.');

        return self::SUCCESS;
    }

    /**
     * Lists, and unless it is a dry run adds, the editor role's permissions for the structures the item adds. A dry run
     * counts the permissions the item itself gives the role as already there, since the install adds those first.
     *
     * @param  array<int, array{0: string, 1: string}>  $structures  [type, handle]
     * @param  array<int, string>  $pending
     */
    private function editorAccess(EditorAccess $access, array $structures, bool $dry, array $pending = []): void
    {
        if ($structures === []) {
            return;
        }
        $role = $access->role();
        if (($problems = $access->problems()) !== []) {
            $this->warn($dry ? "Could not plan the {$role} role's permissions, because:" : "The {$role} role's permissions were not updated, because:");
            foreach ($problems as $problem) {
                $this->line("  {$problem}");
            }
            $this->line('Fix it, then run php please avoca:site:permissions.');

            return;
        }
        try {
            $plan = $dry ? $access->plan($structures, $pending) : $access->sync($structures);
        } catch (\Throwable $e) {
            $this->warn("Could not update the {$role} role. Run php please avoca:site:permissions.\n{$e->getMessage()}");

            return;
        }
        foreach ($plan as $step) {
            $mark = match ($step['status']) {
                'add' => '<fg=green>+</>',
                'complete' => '<fg=gray>=</>',
                default => '<fg=gray>-</>',
            };
            $this->line("  {$mark} {$role} role for ".EditorAccess::describe($step));
        }
    }
}
