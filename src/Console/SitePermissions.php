<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Permissions\EditorAccess;
use Avocadesign\StatamicTools\Permissions\PermissionSets;
use Avocadesign\StatamicTools\Site\Conditions;
use Illuminate\Console\Command;

/**
 * Gives the editor role any permissions it is missing for the site's collections, taxonomies, navigations, global sets
 * and asset containers, leaving opted-out structures alone. A structure created in the control panel or through
 * Statamic's PHP API gets its permissions as it is created. This catches the rest, such as settings files written by
 * hand or pulled in from git.
 */
class SitePermissions extends Command
{
    protected $signature = 'avoca:site:permissions
        {--dry-run : List the permissions it would add, and write nothing}';

    protected $description = 'Give the editor role the permissions for every collection, taxonomy, navigation, global set and asset container that is not opted out.';

    public function handle(): int
    {
        $access = EditorAccess::resolve();
        $role = $access->role();
        $dry = (bool) $this->option('dry-run');

        if (($problems = $access->problems()) !== []) {
            $this->error('Nothing was added, because:');
            foreach ($problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        }
        if (! $access->roleExists()) {
            $this->error("The site has no {$role} role, so nothing was added. Add it to resources/users/roles.yaml, or set the role's handle in statamic-tools.site.editor_role.");

            return self::FAILURE;
        }

        $structures = EditorAccess::structures();
        $plan = $access->plan($structures);
        $count = count(EditorAccess::adding($plan));
        $this->info("The {$role} role's permissions for the site's ".count($plan).' structures:');
        foreach ($plan as $step) {
            $mark = match ($step['status']) {
                'add' => '<fg=green>+</>',
                'opted_out' => '<fg=gray>-</>',
                default => '<fg=gray>=</>',
            };
            $this->line("  {$mark} ".EditorAccess::describe($step));
        }
        foreach ($access->strays($structures) as $stray) {
            $this->line("  <fg=yellow>!</> {$access->optOutsPath()} opts out {$stray}, which the site does not have");
        }
        $this->newLine();

        $permissions = $count === 1 ? 'permission' : 'permissions';
        if ($count === 0) {
            $this->info(($dry ? 'Dry run: nothing was written. ' : '')."The {$role} role already has every permission it needs.");

            return self::SUCCESS;
        }
        if (! $dry) {
            $access->sync($structures);
            $this->info("Added {$count} {$permissions} to the {$role} role. Commit resources/users/roles.yaml with the change.");

            return self::SUCCESS;
        }
        $this->line("Dry run: nothing was written. The real run would add {$count} {$permissions} to the {$role} role.");
        $this->line("To keep a structure away from editors, list its handle in {$access->optOutsPath()} under "
            .Conditions::list(array_keys(PermissionSets::PERMISSIONS), 'or').'. Opting out never removes a permission the role already has.');

        return self::SUCCESS;
    }
}
