<?php

namespace Avocadesign\StatamicTools\Permissions;

use Avocadesign\StatamicTools\Site\Conditions;
use Statamic\Auth\RoleRepository as BaseRoleRepository;
use Statamic\Contracts\Auth\Role as RoleContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\YAML;

/**
 * Keeps the editor role holding the permissions for every collection, taxonomy, navigation, global set and asset
 * container, so the site is ready for editors the moment it moves to Statamic Pro. Statamic Core allows one user and
 * removes the roles field, but it still reads and writes resources/users/roles.yaml through the Role facade, so this
 * works on Core too.
 *
 * A structure is opted out when the opt-outs file lists its handle under its type, or, for a collection, when its record
 * in resources/site/collections says editor_access: false. Nothing is added for an opted-out structure, and nothing the
 * role already has is removed, so a deliberate partial set, such as view only, stays as it is.
 */
final class EditorAccess
{
    public function __construct(
        private string $root,
        private string $role = 'editor',
        private string $optOutsPath = 'resources/site/editor-access.yaml',
        private string $docsPath = 'resources/site',
    ) {
    }

    /** @param  array<string, mixed>|null  $site  the statamic-tools.site config, read from config when not given */
    public static function make(?string $root = null, ?array $site = null): self
    {
        $site ??= (array) config('statamic-tools.site', []);

        return new self(
            rtrim($root ?? base_path(), '/'),
            (string) ($site['editor_role'] ?? '') ?: 'editor',
            trim((string) ($site['editor_access_path'] ?? '') ?: 'resources/site/editor-access.yaml', '/'),
            trim((string) ($site['docs_path'] ?? '') ?: 'resources/site', '/'),
        );
    }

    /** The instance bound in the container, as tests bind one for a temporary site, or one for the site at $root. */
    public static function resolve(?string $root = null): self
    {
        return app()->bound(self::class) ? app(self::class) : self::make($root);
    }

    public function role(): string
    {
        return $this->role;
    }

    public function optOutsPath(): string
    {
        return $this->optOutsPath;
    }

    public function roleExists(): bool
    {
        return $this->currentRole() !== null;
    }

    /** @return array<int, array{0: string, 1: string}> [type, handle] for every structure Statamic knows, type by type */
    public static function structures(): array
    {
        $structures = [];
        foreach ([
            'collections' => Collection::handles(),
            'taxonomies' => Taxonomy::handles(),
            'navigation' => Nav::all()->map->handle(),
            'globals' => GlobalSet::all()->map->handle(),
            'assets' => AssetContainer::all()->map->handle(),
        ] as $type => $handles) {
            foreach ($handles->sort()->values() as $handle) {
                $structures[] = [$type, (string) $handle];
            }
        }

        return $structures;
    }

    /**
     * The structures a set of files sets up, such as the files a library item copies: a settings file directly inside
     * content/collections, content/taxonomies, content/navigation, content/globals or content/assets.
     *
     * @param  array<int, string>  $paths  from the site root
     * @return array<int, array{0: string, 1: string}>
     */
    public static function structuresIn(array $paths): array
    {
        $pattern = '#^content/('.implode('|', array_keys(PermissionSets::PERMISSIONS)).')/([^/]+)\.yaml$#';
        $structures = [];
        foreach ($paths as $path) {
            if (preg_match($pattern, $path, $match)) {
                $structures[] = [$match[1], $match[2]];
            }
        }

        return $structures;
    }

    /** @return array<int, string> what is wrong with the opt-outs; while anything is, nothing is added */
    public function problems(): array
    {
        return $this->optOuts()[1];
    }

    /**
     * What the role needs for each structure. The status is add when permissions are missing, complete when the role has
     * them all, opted_out when the structure is kept away from editors, or no_role when the site has no such role.
     *
     * @param  array<int, array{0: string, 1: string}>  $structures  [type, handle]
     * @param  array<int, string>  $pending  permissions to count as the role's already, such as those a library item is about to add
     * @return array<int, array{type: string, handle: string, status: string, add: array<int, string>, has: array<int, string>, of: int, opted_out: ?string}>
     */
    public function plan(array $structures, array $pending = []): array
    {
        return $this->planFor($this->currentRole(), $structures, $pending);
    }

    /**
     * Adds what plan() finds missing, in one save through Statamic's Role facade, which writes resources/users/roles.yaml.
     * A site with no such role is skipped.
     *
     * @param  array<int, array{0: string, 1: string}>  $structures  [type, handle]
     * @return array<int, array{type: string, handle: string, status: string, add: array<int, string>, has: array<int, string>, of: int, opted_out: ?string}> the plan carried out
     *
     * @throws \RuntimeException naming the problems with the opt-outs, before anything is written
     */
    public function sync(array $structures): array
    {
        if (($problems = $this->problems()) !== []) {
            throw new \RuntimeException(implode("\n", $problems));
        }
        $role = $this->currentRole();
        $plan = $this->planFor($role, $structures);
        $add = self::adding($plan);
        if ($role !== null && $add !== []) {
            $role->addPermission($add)->save();
        }

        return $plan;
    }

    /** @return array<int, string> every permission a plan adds, once each */
    public static function adding(array $plan): array
    {
        return array_values(array_unique(array_merge([], ...array_column($plan, 'add'))));
    }

    /** One structure from a plan in plain words, such as "collection news: add view news entries, edit news entries". */
    public static function describe(array $step): string
    {
        $name = PermissionSets::name($step['type'], $step['handle']);
        $has = count($step['has']);

        return match ($step['status']) {
            'add' => "{$name}: add ".implode(', ', $step['add']).($has > 0 ? " (it has {$has} of {$step['of']})" : ''),
            'complete' => "{$name}: has every permission",
            'opted_out' => "{$name}: opted out in {$step['opted_out']}".($has > 0 ? ", keeping the {$has} ".($has === 1 ? 'permission' : 'permissions').' it has' : ''),
            default => "{$name}: no role to add permissions to",
        };
    }

    /**
     * Structures the opt-outs file names that the site does not have. A typo there leaves the structure it meant to keep
     * away from editors without an opt-out.
     *
     * @param  array<int, array{0: string, 1: string}>  $structures  [type, handle], as structures() returns them
     * @return array<int, string> such as "global set seoo"
     */
    public function strays(array $structures): array
    {
        $known = array_map(fn (array $structure) => implode('/', $structure), $structures);
        $strays = [];
        foreach ($this->optOuts()[0] as $type => $handles) {
            foreach ($handles as $handle => $source) {
                if ($source === $this->optOutsPath && ! in_array("{$type}/{$handle}", $known, true)) {
                    $strays[] = PermissionSets::name($type, (string) $handle);
                }
            }
        }

        return $strays;
    }

    private function planFor(?RoleContract $role, array $structures, array $pending = []): array
    {
        $current = [...($role ? $role->permissions()->all() : []), ...$pending];
        [$optOuts] = $this->optOuts();
        $plan = [];
        foreach ($structures as [$type, $handle]) {
            $permissions = PermissionSets::for($type, $handle);
            $missing = array_values(array_diff($permissions, $current));
            $optedOut = $optOuts[$type][$handle] ?? null;
            $status = match (true) {
                $optedOut !== null => 'opted_out',
                $role === null => 'no_role',
                $missing === [] => 'complete',
                default => 'add',
            };
            $plan[] = [
                'type' => $type,
                'handle' => $handle,
                'status' => $status,
                'add' => $status === 'add' ? $missing : [],
                'has' => array_values(array_intersect($permissions, $current)),
                'of' => count($permissions),
                'opted_out' => $optedOut,
            ];
        }

        return $plan;
    }

    /**
     * The role as it is now. Statamic's role repository keeps the roles it has read and has no way to read them again, so
     * a roles.yaml written since, as avoca:library:install writes it, would otherwise be saved back without those changes.
     */
    private function currentRole(): ?RoleContract
    {
        $repository = Role::getFacadeRoot();
        if ($repository instanceof BaseRoleRepository && property_exists(BaseRoleRepository::class, 'roles')) {
            (fn () => $this->roles = null)->call($repository);
        }

        return Role::find($this->role);
    }

    /**
     * Every opt-out, as type => handle => the file that opts it out, and what is wrong with those files. Collection
     * records are read first, so the opt-outs file is the one named when both opt the same collection out.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<int, string>}
     */
    private function optOuts(): array
    {
        $optOuts = [];
        $problems = [];

        foreach (glob("{$this->root}/{$this->docsPath}/collections/*.md") ?: [] as $file) {
            $record = "{$this->docsPath}/collections/".basename($file);
            try {
                $value = preg_match('/\A---\R(.*?)\R---/s', (string) file_get_contents($file), $match)
                    ? (((array) YAML::parse($match[1]))['editor_access'] ?? null)
                    : null;
            } catch (\Throwable $e) {
                $problems[] = "{$record} could not be read: {$e->getMessage()}";

                continue;
            }
            if ($value === false || (is_string($value) && in_array(strtolower(trim($value)), ['false', 'no', 'off'], true))) {
                $optOuts['collections'][basename($file, '.md')] = $record;
            }
        }

        $file = "{$this->root}/{$this->optOutsPath}";
        if (! is_file($file)) {
            return [$optOuts, $problems];
        }
        $types = Conditions::list(array_keys(PermissionSets::PERMISSIONS), 'or');
        try {
            $listed = YAML::parse((string) file_get_contents($file)) ?? [];
        } catch (\Throwable $e) {
            return [$optOuts, [...$problems, "{$this->optOutsPath} could not be read: {$e->getMessage()}"]];
        }
        if (! is_array($listed) || ($listed !== [] && array_is_list($listed))) {
            return [$optOuts, [...$problems, "{$this->optOutsPath} must list handles under {$types}."]];
        }
        foreach ($listed as $type => $handles) {
            $handles ??= [];
            if (! isset(PermissionSets::PERMISSIONS[$type])) {
                $problems[] = "{$this->optOutsPath}: {$type} is not a type of structure. Use {$types}.";
            } elseif (! is_array($handles) || ! array_is_list($handles) || array_filter($handles, fn ($handle) => ! is_scalar($handle)) !== []) {
                $problems[] = "{$this->optOutsPath}: {$type} must be a list of handles.";
            } else {
                foreach ($handles as $handle) {
                    $optOuts[$type][(string) $handle] = $this->optOutsPath;
                }
            }
        }

        return [$optOuts, $problems];
    }
}
