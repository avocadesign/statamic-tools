<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The critical sheets: an @source line in a file critical.css shares, and a page-specific sheet that saves the
 * other pages too little to be worth one, measured from the last build.
 */
final class CriticalChecks
{
    /**
     * What a page-specific sheet has to save every other page, as a share of the critical CSS they would inline if it
     * were folded back into critical.css, gzipped. Between the two, it is on the line.
     */
    public const SAVING = 0.10;

    public const CLEAR_SAVING = 0.15;

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

        // Every page without the sheet's blocks inlines critical.css; folded back, they would inline the sheet's size.
        foreach ($sheets as $sheet) {
            $size = $this->gzipped($manifest, $base, $sheet);
            if ($size === null || $size === 0) {
                continue;
            }
            $saving = ($size - $critical) / $size;
            $said = sprintf('saves the other pages %d%% of their critical CSS (%.1f KB of %.1f KB gzipped)', round(100 * $saving), ($size - $critical) / 1024, $size / 1024);
            if ($saving < self::SAVING) {
                $this->out->add('critical-saving', $sheet, 1, $said);
            } elseif ($saving < self::CLEAR_SAVING) {
                $this->out->note(basename($sheet)." {$said}: on the line for a sheet of its own");
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
