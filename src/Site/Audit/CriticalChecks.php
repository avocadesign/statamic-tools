<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The critical sheets: an @source line in a file critical.css shares, too many page-specific sheets, and a
 * page-specific sheet that saves too little to be worth one, measured from the last build.
 */
final class CriticalChecks
{
    /** How much more a page-specific sheet has to carry than critical.css, gzipped, to be worth one. */
    public const SAVING = 2048;

    /** How many page-specific sheets is already a lot. */
    public const SHEETS = 2;

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        if ($this->files->get('resources/css/critical.css') === null) {
            return;
        }

        $this->sharedSources();

        $sheets = array_values(array_filter(array_keys($this->files->stylesheets()), fn (string $path) => preg_match('~^resources/css/critical-[^/]+\.css$~', $path)));
        if (count($sheets) > self::SHEETS) {
            $this->out->add('critical-sheets', $sheets[0], 1, count($sheets).' page-specific critical sheets: '.Findings::list(array_map('basename', $sheets)));
        }
        if ($sheets !== []) {
            $this->sizes($sheets);
        }
    }

    /** Every file critical.css imports, at any depth, that names a source: it scans for critical.css as well. */
    private function sharedSources(): void
    {
        $seen = [];
        $queue = ['resources/css/critical.css'];
        while ($queue !== []) {
            $path = array_shift($queue);
            $css = $this->files->get($path);
            if ($css === null || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            foreach (Css::parse($css)['statements'] as $statement) {
                if (preg_match('/^@import\s+["\'](\.[^"\']+)["\']/', $statement['text'], $m)) {
                    $queue[] = self::resolve(dirname($path), $m[1]);
                } elseif ($path !== 'resources/css/critical.css' && str_starts_with($statement['text'], '@source ')) {
                    $this->out->add('critical-shared-source', $path, Files::line($css, $statement['offset']), "critical.css imports this file, so its {$statement['text']} scans for the critical sheet too");
                }
            }
        }
    }

    /** @param array<int, string> $sheets */
    private function sizes(array $sheets): void
    {
        [$manifest, $base, $built] = $this->manifest();
        if ($manifest === null) {
            $this->out->note('no build in public/ to measure, so the critical sheets were not compared: run npm run build');

            return;
        }

        $critical = $this->gzipped($manifest, $base, 'resources/css/critical.css');
        if ($critical === null) {
            $this->out->note("the build in {$base} has no critical.css, so the page-specific sheets were not compared");

            return;
        }

        $newest = 0;
        foreach (array_keys($this->files->stylesheets()) as $path) {
            $newest = max($newest, (int) filemtime($this->files->root().'/'.$path));
        }
        if ($newest > (int) filemtime($built)) {
            $this->out->note("the build in {$base} is older than resources/css, so the critical sheets were measured as last built");
        }

        foreach ($sheets as $sheet) {
            $size = $this->gzipped($manifest, $base, $sheet);
            if ($size !== null && $size - $critical < self::SAVING) {
                $this->out->add('critical-small', $sheet, 1, sprintf('adds %.1f KB gzipped to critical.css (%.1f KB against %.1f KB)', ($size - $critical) / 1024, $size / 1024, $critical / 1024));
            }
        }
    }

    /** @return array{0: array<string, mixed>|null, 1: string|null, 2: string|null} the Vite manifest, the build folder it describes and its file */
    private function manifest(): array
    {
        $root = $this->files->root();
        foreach ([...glob($root.'/public/*/manifest.json') ?: [], ...glob($root.'/public/*/.vite/manifest.json') ?: []] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            if (is_array($manifest) && isset($manifest['resources/css/critical.css'])) {
                $dir = dirname($file);
                if (basename($dir) === '.vite') {
                    $dir = dirname($dir);
                }

                return [$manifest, substr($dir, strlen($root) + 1), $file];
            }
        }

        return [null, null, null];
    }

    private function gzipped(array $manifest, string $base, string $source): ?int
    {
        $file = $manifest[$source]['file'] ?? null;
        $css = is_string($file) ? $this->files->get($base.'/'.$file) : null;

        return $css === null ? null : strlen((string) gzencode($css, 9));
    }

    private static function resolve(string $dir, string $relative): string
    {
        $parts = [];
        foreach (explode('/', $dir.'/'.$relative) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
