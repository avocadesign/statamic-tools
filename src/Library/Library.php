<?php

namespace Avocadesign\StatamicTools\Library;

use Statamic\Facades\YAML;

/**
 * Avoca's library of ready-made blocks, sets and presets. Each item is a folder, <path>/<category>/<handle>/, with an
 * item.yaml, a files/ tree mirroring the site root and optional screenshots/ for the control panel. The addon's own
 * library comes first, then any paths in config('statamic-tools.library.paths'). A site records what it installed, and
 * which version, in resources/site/installed.yaml.
 */
final class Library
{
    /** @param  array<int, string>  $paths */
    public function __construct(
        private array $paths,
        private string $root,
        private string $installedPath = 'resources/site/installed.yaml',
    ) {
    }

    public static function make(): self
    {
        return new self(
            [dirname(__DIR__, 2).'/library', ...(array) config('statamic-tools.library.paths', [])],
            base_path(),
            (string) config('statamic-tools.library.installed_path', 'resources/site/installed.yaml'),
        );
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    /** @return array<string, Item> handle => item; an item in a later path replaces one with the same handle */
    public function items(): array
    {
        $items = [];
        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/').'/*/*/item.yaml') ?: [] as $file) {
                $dir = dirname($file);
                $items[basename($dir)] = new Item(basename($dir), basename(dirname($dir)), $dir, (array) YAML::parse((string) file_get_contents($file)));
            }
        }
        ksort($items);

        return $items;
    }

    public function find(string $handle): ?Item
    {
        return $this->items()[$handle] ?? null;
    }

    /** @return array<string, array{version: string, installed: string, category: string, checksum?: string}> */
    public function installed(): array
    {
        $file = $this->installedFile();

        return is_file($file) ? (array) YAML::parse((string) file_get_contents($file)) : [];
    }

    /** 'not installed', 'installed' or 'update available' */
    public function status(Item $item): string
    {
        $version = $this->installed()[$item->handle]['version'] ?? null;

        return match (true) {
            $version === null => 'not installed',
            version_compare($item->version(), (string) $version, '>') => 'update available',
            default => 'installed',
        };
    }

    public function record(Item $item, string $date): void
    {
        $this->write($item->handle, ['version' => $item->version(), 'installed' => $date, 'category' => $item->category]);
    }

    /**
     * Records something else the add-on put in the site, under its own key. The server scripts use it: they are not
     * library items, but a site wants one list of what the add-on gave it and which version it came from.
     *
     * @param  array<string, string>  $entry
     */
    public function write(string $handle, array $entry): void
    {
        $installed = $this->installed();
        $installed[$handle] = $entry;
        ksort($installed);
        $file = $this->installedFile();
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, YAML::dump($installed));
    }

    public function installedPath(): string
    {
        return $this->installedPath;
    }

    private function installedFile(): string
    {
        return $this->root().'/'.$this->installedPath;
    }
}
