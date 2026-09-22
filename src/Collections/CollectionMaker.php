<?php

namespace Avocadesign\StatamicTools\Collections;

use Avocadesign\StatamicTools\Library\Installer;
use Avocadesign\StatamicTools\Library\Item;
use Avocadesign\StatamicTools\Library\Library;
use Avocadesign\StatamicTools\Permissions\EditorAccess;
use Statamic\Facades\YAML;

/**
 * Writes a new collection into a site: its settings, blueprint and record, an optional listing block and, unless the
 * collection is opted out, the editor role's permissions. plan() reports every change. write() stops before writing anything when a file or the block's page
 * builder entry already exists, or when a step cannot be carried out.
 *
 * The block is planned by the library Installer, so it is matched the way a library install matches it, and written
 * here because Installer::install() also records a library item in installed.yaml. The editor role's permissions are
 * planned and added by EditorAccess, the way avoca:site:permissions adds them.
 */
final class CollectionMaker
{
    /** The page builder group for blocks that show content from elsewhere, such as a form, collection entries or contact details. */
    public const GROUP = 'dynamic';

    public function __construct(
        private string $root,
        private array $config = [],
    ) {
    }

    public static function make(): self
    {
        return new self(base_path(), (array) config('statamic-tools.site', []));
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    /** What the generated files need to know about the site, read from its config and files. */
    public function site(): array
    {
        $root = $this->root();
        $scheme = (string) ($this->config['scheme_fieldset'] ?? 'colour_scheme');
        $margins = (string) ($this->config['margins_fieldset'] ?? 'block_margins');
        $class = (string) ($this->config['class_fieldset'] ?? 'custom_class');
        $containers = [];
        foreach (glob("{$root}/content/assets/*.yaml") ?: [] as $file) {
            $containers[basename($file, '.yaml')] = (string) (((array) YAML::parse((string) file_get_contents($file)))['title'] ?? basename($file, '.yaml'));
        }

        return [
            'page_builder_fieldset' => (string) ($this->config['page_builder_fieldset'] ?? 'page_builder'),
            'blocks_view_path' => trim((string) ($this->config['blocks_view_path'] ?? 'page_builder'), '/'),
            'docs_path' => trim((string) ($this->config['docs_path'] ?? 'resources/site'), '/'),
            'catalogue_path' => trim((string) ($this->config['catalogue_path'] ?? 'resources/site/catalogue.md'), '/'),
            'scheme_fieldset' => is_file("{$root}/resources/fieldsets/{$scheme}.yaml") ? $scheme : null,
            'margins_fieldset' => is_file("{$root}/resources/fieldsets/{$margins}.yaml") ? $margins : null,
            'class_fieldset' => is_file("{$root}/resources/fieldsets/{$class}.yaml") ? $class : null,
            'hero_fieldset' => is_file("{$root}/resources/fieldsets/block_hero.yaml") ? 'block_hero' : null,
            'common_fieldset' => is_file("{$root}/resources/fieldsets/common.yaml"),
            'containers' => $containers,
        ];
    }

    /** @return array<string, string> what each file is => its path from the site root */
    public function paths(CollectionSpec $spec): array
    {
        return CollectionFiles::paths($spec, $this->site());
    }

    /** @return array<int, string> the files an AI agent may change to finish the collection: all but its settings */
    public function editable(CollectionSpec $spec): array
    {
        return array_values(array_diff_key($this->paths($spec), ['collection' => true]));
    }

    /** @return array<string, string> path from the site root => contents */
    public function files(CollectionSpec $spec, string $date): array
    {
        $site = $this->site();
        $paths = CollectionFiles::paths($spec, $site);
        $out = [];
        foreach ($paths as $key => $path) {
            $out[$path] = match ($key) {
                'collection' => YAML::dump(CollectionFiles::collection($spec)),
                'blueprint' => YAML::dump(CollectionFiles::blueprint($spec, $site)),
                'show' => CollectionFiles::showView($spec, $paths['blueprint']),
                'block_fieldset' => YAML::dump(CollectionFiles::blockFieldset($spec, $site)),
                'block_view' => CollectionFiles::blockView($spec, $site, $paths['blueprint']),
                'guidance' => CollectionFiles::guidance($spec),
                'record' => CollectionRecord::render($spec, $paths, $site, $date),
            };
        }

        return $out;
    }

    /** @return array<int, array{action: string, target: string, status: string, detail: string}> status: new, same, skipped, exists, conflict or error */
    public function plan(CollectionSpec $spec): array
    {
        $root = $this->root();
        $site = $this->site();
        $steps = [];
        foreach (CollectionFiles::paths($spec, $site) as $path) {
            $steps[] = ['action' => 'create', 'target' => $path, 'status' => file_exists("{$root}/{$path}") ? 'exists' : 'new', 'detail' => ''];
        }
        $pageBuilder = "resources/fieldsets/{$site['page_builder_fieldset']}.yaml";
        if (! $spec->custom() && ! is_file("{$root}/{$pageBuilder}")) {
            $steps[] = ['action' => 'import', 'target' => $pageBuilder, 'status' => 'error', 'detail' => 'the page builder blueprint imports it, and it does not exist'];
        }
        if (($item = $this->item($spec)) !== null) {
            array_push($steps, ...$this->installer()->plan($item));
        }
        if ($spec->editorAccess) {
            $steps[] = $this->editorAccessStep($spec->handle);
        }

        return $steps;
    }

    /** @return array<int, array{action: string, target: string, status: string, detail: string}> the steps that stop a make */
    public static function blocking(array $plan): array
    {
        return array_values(array_filter($plan, fn (array $step) => in_array($step['status'], ['exists', 'conflict', 'error'], true)
            || ($step['action'] === 'block' && $step['status'] === 'same')));
    }

    public static function problem(array $step): string
    {
        return match (true) {
            $step['status'] === 'exists' => "{$step['target']} already exists",
            $step['action'] === 'block' && $step['status'] !== 'error' => "the page builder already has a block called {$step['target']}",
            default => "{$step['action']} {$step['target']}: ".($step['detail'] !== '' ? $step['detail'] : $step['status']),
        };
    }

    /** @return array<int, array{action: string, target: string, status: string, detail: string}> the plan that was carried out */
    public function write(CollectionSpec $spec, string $date): array
    {
        $plan = $this->plan($spec);
        if ($blocking = self::blocking($plan)) {
            throw new \RuntimeException(implode("\n", array_map(self::problem(...), $blocking)));
        }

        $root = $this->root();
        foreach ($this->files($spec, $date) as $path => $contents) {
            if (! is_dir(dirname("{$root}/{$path}"))) {
                mkdir(dirname("{$root}/{$path}"), 0755, true);
            }
            file_put_contents("{$root}/{$path}", $contents);
        }
        if ($spec->block) {
            $this->addBlock($spec);
        }
        if ($spec->editorAccess) {
            $this->editorAccess()->sync([['collections', $spec->handle]]);
        }

        return $plan;
    }

    /** Plans and adds the editor role's permissions for this site, as avoca:site:permissions does. */
    public function editorAccess(): EditorAccess
    {
        return EditorAccess::make($this->root(), $this->config);
    }

    private function item(CollectionSpec $spec): ?Item
    {
        $config = ['name' => $spec->title];
        if ($spec->block) {
            $config['page_builder'] = [['handle' => $spec->handle, 'display' => $spec->title, 'group' => self::GROUP, 'instructions' => CollectionFiles::blockInstructions($spec), 'icon' => 'file-content-list']];
        }

        // The item's folder is this file, which can hold no files/ or screenshots/, so it adds only the block.
        return count($config) > 1 ? new Item($spec->handle, 'presets', __FILE__, $config) : null;
    }

    private function installer(): Installer
    {
        $handle = (string) ($this->config['page_builder_fieldset'] ?? 'page_builder');

        return new Installer(new Library([], $this->root()), null, [
            'block' => [$handle, $handle],
            'set' => [(string) ($this->config['article_fieldset'] ?? 'article'), (string) ($this->config['article_field'] ?? 'article')],
        ]);
    }

    /**
     * Adds the block to the Dynamic group, which holds the blocks that show content from elsewhere. The group is matched by
     * key or display name, as the Installer matches it, and created if the site has none.
     */
    private function addBlock(CollectionSpec $spec): void
    {
        $handle = (string) ($this->config['page_builder_fieldset'] ?? 'page_builder');
        $file = $this->root()."/resources/fieldsets/{$handle}.yaml";
        $fieldset = (array) YAML::parse((string) file_get_contents($file));
        foreach ((array) ($fieldset['fields'] ?? []) as $index => $row) {
            if (($row['handle'] ?? null) !== $handle) {
                continue;
            }
            $groups = (array) ($row['field']['sets'] ?? []);
            $key = self::groupKey($groups, self::GROUP) ?? self::GROUP;
            $groups[$key] ??= ['display' => 'Dynamic', 'sets' => []];
            $groups[$key]['sets'] = [...(array) ($groups[$key]['sets'] ?? []), $spec->handle => [
                'display' => $spec->title,
                'instructions' => CollectionFiles::blockInstructions($spec),
                'icon' => 'file-content-list',
                'fields' => [['import' => $spec->handle]],
            ]];
            $fieldset['fields'][$index]['field']['sets'] = $groups;
            file_put_contents($file, YAML::dump($fieldset));

            return;
        }
    }

    /**
     * The editor role's permissions for the new collection. A site with no editor role, or one that opts the collection
     * out, gets a skipped step and still gets its collection. A problem with the opt-outs stops the make.
     *
     * @return array{action: string, target: string, status: string, detail: string}
     */
    private function editorAccessStep(string $handle): array
    {
        $access = $this->editorAccess();
        $step = ['action' => 'permissions', 'target' => $access->role(), 'status' => 'new', 'detail' => ''];
        if (($problems = $access->problems()) !== []) {
            return [...$step, 'status' => 'error', 'detail' => implode(' ', $problems)];
        }
        [$planned] = $access->plan([['collections', $handle]]);

        return match ($planned['status']) {
            'add' => [...$step, 'detail' => implode(', ', $planned['add'])],
            'complete' => [...$step, 'status' => 'same'],
            'opted_out' => [...$step, 'status' => 'skipped', 'detail' => "the collection is opted out in {$planned['opted_out']}"],
            default => [...$step, 'status' => 'skipped', 'detail' => 'the site has no such role'],
        };
    }

    private static function groupKey(array $groups, string $group): ?string
    {
        foreach ($groups as $key => $config) {
            if (strcasecmp((string) $key, $group) === 0 || strcasecmp((string) ($config['display'] ?? ''), $group) === 0) {
                return (string) $key;
            }
        }

        return null;
    }
}
