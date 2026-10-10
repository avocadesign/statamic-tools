<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * The site's CSS as one stylesheet, for Tailwind's browser build to compile in the prototype's frames: every relative
 * @import inlined, in its layer when the import names one, and what only the site's own build understands left out,
 * which is the import of Tailwind itself (the browser build brings it), @config, @plugin and @source. Compiled in a
 * frame, it lays pages out exactly as the site does.
 */
final class SiteCss
{
    public static function flatten(string $entry): string
    {
        return self::read(realpath($entry) ?: $entry, []);
    }

    /** @param  array<int, string>  $seen */
    private static function read(string $file, array $seen): string
    {
        if (in_array($file, $seen, true) || ! is_file($file)) {
            return '';
        }
        $seen[] = $file;
        $css = (string) file_get_contents($file);

        $css = (string) preg_replace_callback('/@import\s+["\']([^"\']+)["\']\s*([^;]*);/', function (array $m) use ($file, $seen) {
            if (! str_starts_with($m[1], '.')) {
                return '';
            }
            $inner = self::read(dirname($file).'/'.$m[1], $seen);
            if (preg_match('/layer\((\w[\w-]*)\)/', $m[2], $layer)) {
                return "@layer {$layer[1]} {\n{$inner}\n}\n";
            }

            return $inner;
        }, $css);

        return (string) preg_replace('/^\s*@(config|plugin|source)\b[^;]*;\s*$/m', '', $css);
    }
}
