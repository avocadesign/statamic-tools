<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The site's own templates, stylesheets and scripts, read once for every check, with paths relative to the site.
 */
final class Files
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array<string, string> resources/views, by path */
    public function templates(): array
    {
        return $this->cache['templates'] ??= $this->read('resources/views', ['html']);
    }

    /** @return array<string, string> resources/css, by path */
    public function stylesheets(): array
    {
        return $this->cache['stylesheets'] ??= $this->read('resources/css', ['css']);
    }

    /** @return array<string, string> resources/js, by path, without Tailwind's config or the control panel's script */
    public function scripts(): array
    {
        return $this->cache['scripts'] ??= array_filter(
            $this->read('resources/js', ['js', 'mjs', 'ts']),
            fn (string $path) => ! preg_match('~/(cp\.js|cp/|tailwind[^/]*\.js$)~', $path),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * What else can use a class or a plugin: the content, the fieldsets and blueprints, and Peak's own views.
     *
     * @return array<string, string>
     */
    public function elsewhere(): array
    {
        if (! isset($this->cache['elsewhere'])) {
            $files = [
                ...$this->read('content', ['md', 'yaml']),
                ...$this->read('resources/fieldsets', ['yaml']),
                ...$this->read('resources/blueprints', ['yaml']),
            ];
            foreach (glob($this->root.'/vendor/studio1902/*/resources/views', GLOB_ONLYDIR) ?: [] as $dir) {
                $files = [...$files, ...$this->read(substr($dir, strlen($this->root) + 1), ['html'])];
            }
            $this->cache['elsewhere'] = $files;
        }

        return $this->cache['elsewhere'];
    }

    public function get(string $path): ?string
    {
        return $this->stylesheets()[$path] ?? $this->templates()[$path] ?? $this->scripts()[$path]
            ?? (is_file($this->root.'/'.$path) ? (string) file_get_contents($this->root.'/'.$path) : null);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->root.'/'.$path);
    }

    /** The line an offset in a file falls on, counting from 1. */
    public static function line(string $contents, int $offset): int
    {
        return substr_count($contents, "\n", 0, max(0, min($offset, strlen($contents)))) + 1;
    }

    /** @return array<string, string> */
    private function read(string $dir, array $extensions): array
    {
        $base = $this->root.'/'.$dir;
        if (! is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[$dir.'/'.substr($file->getPathname(), strlen($base) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
