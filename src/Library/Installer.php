<?php

namespace Avocadesign\StatamicTools\Library;

use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\YAML;

/**
 * Installs a library item into a site without asking anything: what it adds, and where, is declared in item.yaml.
 * plan() reports what would change. install() stops before writing anything when a step has an error, or when a file
 * or fieldset entry already exists and differs, unless it is forced. A file under content/ that marks sample images is
 * planned, compared and written with the image the site has for them (SampleImages).
 */
final class Installer
{
    /** @param  array<string, array{0: string, 1: string}>  $targets  block|set => [fieldset handle, field handle] */
    public function __construct(
        private Library $library,
        private ?string $screenshotsDir = null,
        private array $targets = ['block' => ['page_builder', 'page_builder'], 'set' => ['article', 'article']],
        private SampleImages $sampleImages = new SampleImages(null),
    ) {
    }

    public static function make(Library $library): self
    {
        $cfg = config('statamic-tools.site');
        $preview = config('statamic.assets.set_preview_images');
        $container = is_array($preview) ? AssetContainer::find((string) ($preview['container'] ?? '')) : null;

        return new self($library, $container?->disk()->path((string) ($preview['folder'] ?? '')), [
            'block' => [$cfg['page_builder_fieldset'] ?? 'page_builder', $cfg['page_builder_fieldset'] ?? 'page_builder'],
            'set' => [$cfg['article_fieldset'] ?? 'article', $cfg['article_field'] ?? 'article'],
        ], SampleImages::make());
    }

    /** @return array<int, array{action: string, target: string, status: string, detail: string}> status: new, same, conflict or error */
    public function plan(Item $item): array
    {
        $root = $this->library->root();
        $steps = [];

        foreach ($item->files() as $relative => $source) {
            $steps[] = $this->fileStep($relative, $source);
        }

        foreach (['block' => $item->blocks(), 'set' => $item->sets()] as $kind => $entries) {
            foreach ($entries as $entry) {
                $steps[] = $this->entryStep($kind, $entry);
            }
        }

        foreach ($item->screenshots() as $handle => $source) {
            $steps[] = $this->screenshotsDir === null
                ? ['action' => 'screenshot', 'target' => basename($source), 'status' => 'error', 'detail' => 'no set preview images container is configured']
                : ['action' => 'screenshot', 'target' => basename($source), 'status' => $this->compare(rtrim($this->screenshotsDir, '/').'/'.basename($source), $source), 'detail' => "preview image for {$handle}"];
        }

        $roles = $this->read("{$root}/resources/users/roles.yaml");
        foreach ($item->permissions() as $role => $permissions) {
            $missing = array_values(array_diff($permissions, (array) ($roles[$role]['permissions'] ?? [])));
            $steps[] = isset($roles[$role])
                ? ['action' => 'permissions', 'target' => $role, 'status' => $missing ? 'new' : 'same', 'detail' => implode(', ', $missing)]
                : ['action' => 'permissions', 'target' => $role, 'status' => 'error', 'detail' => 'role not found in resources/users/roles.yaml'];
        }

        return $steps;
    }

    /** @return array<int, array{action: string, target: string, status: string, detail: string}> the plan that was carried out */
    public function install(Item $item, bool $force = false, ?string $date = null): array
    {
        $plan = $this->plan($item);
        $blocking = array_filter($plan, fn ($step) => $step['status'] === 'error' || ($step['status'] === 'conflict' && ! $force));
        if ($blocking) {
            throw new \RuntimeException(implode("\n", array_map(
                fn ($step) => "{$step['action']} {$step['target']}: ".($step['status'] === 'error' ? $step['detail'] : 'already exists and differs'),
                $blocking,
            )));
        }
        $root = $this->library->root();

        foreach ($item->files() as $relative => $source) {
            $this->copy($source, "{$root}/{$relative}", $this->sample($relative, $source)['contents'] ?? null);
        }

        $images = [];
        foreach ($item->screenshots() as $handle => $source) {
            $this->copy($source, rtrim((string) $this->screenshotsDir, '/').'/'.basename($source));
            $images[$handle] = basename($source);
        }

        foreach (['block' => $item->blocks(), 'set' => $item->sets()] as $kind => $entries) {
            if ($entries) {
                $this->addEntries($kind, $entries, $images);
            }
        }

        $roles = $this->read($file = "{$root}/resources/users/roles.yaml");
        $before = $roles;
        foreach ($item->permissions() as $role => $permissions) {
            $roles[$role]['permissions'] = array_values(array_unique([...(array) ($roles[$role]['permissions'] ?? []), ...$permissions]));
        }
        if ($roles !== $before) {
            file_put_contents($file, YAML::dump($roles));
        }

        $this->library->record($item, $date ?? date('Y-m-d'));

        return $plan;
    }

    /** A file's copy step. A file that marks sample images is compared as it will be written, and says which image they use. */
    private function fileStep(string $relative, string $source): array
    {
        $step = ['action' => 'copy', 'target' => $relative, 'status' => 'new', 'detail' => ''];
        try {
            $sample = $this->sample($relative, $source);
        } catch (\InvalidArgumentException $e) {
            return [...$step, 'status' => 'error', 'detail' => $e->getMessage()];
        }
        $status = $this->compare($this->library->root()."/{$relative}", $source, $sample['contents'] ?? null);
        $detail = $sample['detail'] ?? '';

        return [...$step, 'status' => $status, 'detail' => $status === 'conflict' && $detail !== '' ? "exists and differs, {$detail}" : $detail];
    }

    /** @return array{contents: string, detail: string}|null a file under content/ with its sample images in place, or null to copy the file as it is */
    private function sample(string $relative, string $source): ?array
    {
        return str_starts_with($relative, 'content/') ? $this->sampleImages->apply((string) file_get_contents($source)) : null;
    }

    private function entryStep(string $kind, array $entry): array
    {
        [$fieldsetHandle, $fieldHandle] = $this->targets[$kind];
        $step = ['action' => $kind, 'target' => $entry['handle'], 'status' => 'new', 'detail' => ''];
        $fieldset = $this->read($this->fieldsetFile($fieldsetHandle));
        $index = $this->fieldIndex($fieldset, $fieldHandle);
        if ($index === null) {
            return [...$step, 'status' => 'error', 'detail' => "no {$fieldHandle} field in resources/fieldsets/{$fieldsetHandle}.yaml"];
        }
        if ($entry['group'] === '') {
            return [...$step, 'status' => 'error', 'detail' => 'item.yaml gives it no group'];
        }
        $groups = (array) ($fieldset['fields'][$index]['field']['sets'] ?? []);
        $key = $this->groupKey($groups, $entry['group']);
        $step['detail'] = $key === null ? 'new group '.Str::headline($entry['group']) : 'group '.($groups[$key]['display'] ?? $key);
        foreach ($groups as $groupKey => $group) {
            if (isset($group['sets'][$entry['handle']])) {
                $same = (string) $groupKey === $key && ($group['sets'][$entry['handle']]['fields'] ?? null) == [['import' => $entry['import']]];

                return [...$step, 'status' => $same ? 'same' : 'conflict', 'detail' => $same ? $step['detail'] : 'already in group '.($group['display'] ?? $groupKey)];
            }
        }

        return $step;
    }

    /** Add each entry to its group, creating the group when it doesn't exist; a forced entry replaces the old one. */
    private function addEntries(string $kind, array $entries, array $images): void
    {
        [$fieldsetHandle, $fieldHandle] = $this->targets[$kind];
        $fieldset = $this->read($file = $this->fieldsetFile($fieldsetHandle));
        $index = (int) $this->fieldIndex($fieldset, $fieldHandle);
        $groups = (array) ($fieldset['fields'][$index]['field']['sets'] ?? []);
        $before = $groups;

        foreach ($entries as $entry) {
            $image = $images[$entry['handle']] ?? null;
            foreach ($groups as $groupKey => $group) {
                if (isset($group['sets'][$entry['handle']])) {
                    $image ??= $group['sets'][$entry['handle']]['image'] ?? null;
                    unset($groups[$groupKey]['sets'][$entry['handle']]);
                }
            }
            $key = $this->groupKey($groups, $entry['group']) ?? $entry['group'];
            $groups[$key] ??= ['display' => Str::headline($entry['group']), 'sets' => []];
            $groups[$key]['sets'] = (array) ($groups[$key]['sets'] ?? []);
            $groups[$key]['sets'][$entry['handle']] = array_filter([
                'display' => $entry['display'],
                'instructions' => $entry['instructions'],
                'icon' => $entry['icon'],
                'image' => $image,
            ]) + ['fields' => [['import' => $entry['import']]]];
        }

        if ($groups != $before) {
            $fieldset['fields'][$index]['field']['sets'] = $groups;
            file_put_contents($file, YAML::dump($fieldset));
        }
    }

    /** new, same or conflict: the target against the source file, or against $contents when the file is written changed */
    private function compare(string $target, string $source, ?string $contents = null): string
    {
        if (! is_file($target)) {
            return 'new';
        }

        return sha1_file($target) === ($contents === null ? sha1_file($source) : sha1($contents)) ? 'same' : 'conflict';
    }

    /** Copy the source, or write $contents in its place. */
    private function copy(string $source, string $target, ?string $contents = null): void
    {
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        $contents === null ? copy($source, $target) : file_put_contents($target, $contents);
    }

    private function fieldsetFile(string $handle): string
    {
        return $this->library->root()."/resources/fieldsets/{$handle}.yaml";
    }

    private function fieldIndex(array $fieldset, string $handle): ?int
    {
        foreach ((array) ($fieldset['fields'] ?? []) as $index => $row) {
            if (($row['handle'] ?? null) === $handle) {
                return (int) $index;
            }
        }

        return null;
    }

    /** A group matched by its key or its display name, ignoring case: "content" finds the kit's "Content" group. */
    private function groupKey(array $groups, string $group): ?string
    {
        foreach ($groups as $key => $config) {
            if (strcasecmp((string) $key, $group) === 0 || strcasecmp((string) ($config['display'] ?? ''), $group) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    private function read(string $file): array
    {
        return is_file($file) ? (array) YAML::parse((string) file_get_contents($file)) : [];
    }
}
