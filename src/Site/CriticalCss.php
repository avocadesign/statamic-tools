<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * The critical CSS entry, resources/css/critical.css, and whether it still covers what paints above the fold.
 *
 * Nothing here generates anything: Tailwind does that on every build from the @source list in the file. What cannot
 * be automatic is the list itself, so this is what checks it. A partial added to the header or the page header and
 * not added to @source has its utilities missing at first paint and arriving when the full sheet lands, which shows
 * as the top of the page moving a moment after it appears — the one way this method goes wrong quietly.
 */
final class CriticalCss
{
    /**
     * The templates that paint above the fold, and how far to follow each.
     *
     * WALK are followed into the partials they render, because splitting a long header into further partials is what
     * the recipe tells a site to do, and those are above the fold with it. SELF are counted for their own markup only:
     * the layout renders the footer too, and following it would warn about a partial nobody can see at first paint.
     */
    private const WALK = [
        'resources/views/layout/_header.antlers.html',
        'resources/views/layout/_page_header.antlers.html',
    ];

    private const SELF = [
        'resources/views/layout.antlers.html',
        'resources/views/default.antlers.html',
    ];

    public function __construct(private string $root, private string $entry = 'resources/css/critical.css')
    {
    }

    public static function make(): self
    {
        return new self(base_path(), (string) config('statamic-tools.site.critical_css', 'resources/css/critical.css'));
    }

    public function path(): string
    {
        return $this->entry;
    }

    public function file(): string
    {
        return rtrim($this->root, '/').'/'.$this->entry;
    }

    /** A site without one is not a site with a problem: the recipe says when to add it. */
    public function exists(): bool
    {
        return is_file($this->file());
    }

    public function inViteInput(): bool
    {
        $config = rtrim($this->root, '/').'/vite.config.js';

        return is_file($config) && str_contains((string) file_get_contents($config), $this->entry);
    }

    public function inlinedByLayout(): bool
    {
        $layout = rtrim($this->root, '/').'/resources/views/layout.antlers.html';
        if (! is_file($layout)) {
            return false;
        }
        $contents = (string) file_get_contents($layout);

        return str_contains($contents, 'vite:content') && str_contains($contents, $this->entry);
    }

    /**
     * The partials rendered above the fold that no @source line covers.
     *
     * @return array<int, string> view paths, relative to the site
     */
    public function uncovered(): array
    {
        $sources = $this->sources();
        if ($sources === []) {
            return [];
        }

        $missing = [];
        foreach ($this->abovetheFold() as $view) {
            foreach ($sources as $source) {
                if ($view === $source || str_starts_with($view, rtrim($source, '/').'/')) {
                    continue 2;
                }
            }
            $missing[] = $view;
        }

        return array_values(array_unique($missing));
    }

    /**
     * The @source paths, resolved against the stylesheet and made relative to the site.
     *
     * @return array<int, string>
     */
    private function sources(): array
    {
        if (! $this->exists()) {
            return [];
        }

        preg_match_all('/@source\s+["\']([^"\']+)["\']/', (string) file_get_contents($this->file()), $matches);

        $base = dirname($this->file());
        $sources = [];
        foreach ($matches[1] ?? [] as $source) {
            $resolved = realpath($base.'/'.$source);
            if ($resolved !== false) {
                $sources[] = $this->relative($resolved);
            }
        }

        return $sources;
    }

    /**
     * Every partial the layout, the header and the page header render, one level down from each.
     *
     * A partial named by a variable, such as the text editor's {{ partial src="components/{type}" }}, is not followed:
     * what it renders is not above the fold, and a guess here would be a warning nobody could act on.
     *
     * @return array<int, string>
     */
    private function abovetheFold(): array
    {
        $views = [];
        foreach (self::SELF as $view) {
            if (is_file(rtrim($this->root, '/').'/'.$view)) {
                $views[] = $view;
            }
        }

        foreach (self::WALK as $root) {
            if (! is_file(rtrim($this->root, '/').'/'.$root)) {
                continue;
            }
            $views[] = $root;
            foreach ($this->partialsIn($root) as $partial) {
                $views[] = $partial;
                foreach ($this->partialsIn($partial) as $nested) {
                    $views[] = $nested;
                }
            }
        }

        return array_values(array_unique($views));
    }

    /** @return array<int, string> */
    private function partialsIn(string $view): array
    {
        $file = rtrim($this->root, '/').'/'.$view;
        if (! is_file($file)) {
            return [];
        }

        preg_match_all('/\{\{\s*partial:([a-z0-9_\/-]+)/i', (string) file_get_contents($file), $matches);

        $partials = [];
        foreach ($matches[1] ?? [] as $name) {
            if (str_contains($name, '::')) {
                continue;   // an add-on's own partial, which the site does not hold
            }
            $parts = explode('/', $name);
            $last = array_pop($parts);
            $candidate = 'resources/views/'.implode('/', [...$parts, '_'.$last]).'.antlers.html';
            if (is_file(rtrim($this->root, '/').'/'.$candidate)) {
                $partials[] = $candidate;
            }
        }

        return $partials;
    }

    private function relative(string $absolute): string
    {
        $root = rtrim(realpath($this->root) ?: $this->root, '/').'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
