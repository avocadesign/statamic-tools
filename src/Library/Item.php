<?php

namespace Avocadesign\StatamicTools\Library;

use Illuminate\Support\Str;

/** One library item: its item.yaml, the files it copies into a site, and what it adds to the fieldsets. */
final class Item
{
    private const IMAGES = ['jpeg', 'jpg', 'png', 'webp'];

    public function __construct(
        public readonly string $handle,
        public readonly string $category,
        public readonly string $path,
        public readonly array $config,
    ) {
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? Str::headline($this->handle));
    }

    public function version(): string
    {
        return (string) ($this->config['version'] ?? '1.0.0');
    }

    public function description(): string
    {
        return (string) ($this->config['description'] ?? '');
    }

    public function notes(): string
    {
        return trim((string) ($this->config['notes'] ?? ''));
    }

    /** @return array<string, string> site-relative path => source file, for everything under files/ */
    public function files(): array
    {
        $base = $this->path.'/files';
        if (! is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $out[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/')] = $file->getPathname();
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<int, array{handle: string, display: string, group: string, instructions: string, icon: string, import: string}> */
    public function blocks(): array
    {
        return $this->entries('page_builder');
    }

    /** @return array<int, array{handle: string, display: string, group: string, instructions: string, icon: string, import: string}> */
    public function sets(): array
    {
        return $this->entries('article_sets');
    }

    /** @return array<string, string> set handle => image, from screenshots/<handle>.<jpeg|jpg|png|webp> */
    public function screenshots(): array
    {
        $out = [];
        foreach (glob($this->path.'/screenshots/*') ?: [] as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::IMAGES, true)) {
                $out[pathinfo($file, PATHINFO_FILENAME)] = $file;
            }
        }

        return $out;
    }

    /** @return array<string, array<int, string>> role => permissions */
    public function permissions(): array
    {
        return array_map(fn ($permissions) => array_values((array) $permissions), (array) ($this->config['permissions'] ?? []));
    }

    /** One entry or a list of them, each with every key filled in. */
    private function entries(string $key): array
    {
        $raw = (array) ($this->config[$key] ?? []);
        $list = isset($raw['handle']) ? [$raw] : array_values($raw);

        return array_map(fn (array $entry) => [
            'handle' => (string) $entry['handle'],
            'display' => (string) ($entry['display'] ?? Str::headline((string) $entry['handle'])),
            'group' => (string) ($entry['group'] ?? ''),
            'instructions' => (string) ($entry['instructions'] ?? ''),
            'icon' => (string) ($entry['icon'] ?? ''),
            'import' => (string) ($entry['import'] ?? $entry['handle']),
        ], $list);
    }
}
