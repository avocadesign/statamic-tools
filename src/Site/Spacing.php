<?php

namespace Avocadesign\StatamicTools\Site;

use Illuminate\Support\Str;

/**
 * The spacing a site is built with, read from its CSS and its views: the stack values the templates use, the
 * section stack on the page template's outer element, and sizes in rem and px. The style page draws these with the
 * site's own classes and measures them in the browser, so the numbers here label the drawing rather than replace it.
 */
final class Spacing
{
    /** Tailwind's default breakpoints, used where the site's CSS declares none of its own. */
    private const BREAKPOINTS = ['sm' => '40rem', 'md' => '48rem', 'lg' => '64rem', 'xl' => '80rem', '2xl' => '96rem'];

    private const STACK = '/(?<![\w:-])(?:([a-z0-9]+):)?stack-(\d+(?:\.\d+)?)(?![\w.-])/';

    /** @return array<string, string> breakpoint => min width: the site's --breakpoint-* tokens over Tailwind's defaults */
    public static function breakpoints(CssTokens $tokens): array
    {
        $out = self::BREAKPOINTS;
        foreach ($tokens->prefixed('breakpoint-') as $name => $value) {
            $out[substr($name, 11)] = $value;
        }

        return $out;
    }

    /** The unit behind stack-N, gap-N and py-N: the site's --spacing token, or Tailwind's 0.25rem. */
    public static function unit(CssTokens $tokens): string
    {
        return $tokens->all()['spacing']['value'] ?? '0.25rem';
    }

    /** @return array{rem: string, px: string} steps of the unit, such as 12 × 0.25rem = 3rem and 48px */
    public static function size(float $steps, string $unit): array
    {
        $value = (float) $unit;
        $rem = (str_ends_with(trim($unit), 'px') ? $value / 16 : $value) * $steps;
        $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');

        return ['rem' => $fmt($rem).'rem', 'px' => $fmt($rem * 16).'px'];
    }

    /** @return array<string, float> breakpoint prefix ('' for every width) => steps, for one utility such as stack or pb */
    public static function responsive(string $classes, string $utility): array
    {
        preg_match_all('/(?<![\w:-])(?:([a-z0-9]+):)?'.preg_quote($utility, '/').'-(\d+(?:\.\d+)?)(?![\w.-])/', $classes, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $prefix, $steps]) {
            $out[$prefix] = (float) $steps;
        }

        return $out;
    }

    /**
     * The section stack: the classes on the page template's outer element, which space the blocks on every page.
     *
     * @return array{file: string, classes: string, stack_classes: string, stack: array<string, float>, padding_top: array<string, float>, padding_bottom: array<string, float>}|null
     */
    public static function section(string $templatePath, ?CssTokens $tokens = null): ?array
    {
        if (! is_file($templatePath) || ! preg_match('/class="([^"]*(?<![\w:-])(?:[a-z0-9]+:)?stack-[\w-]+[^"]*)"/', (string) file_get_contents($templatePath), $m)) {
            return null;
        }
        $classes = (string) preg_replace('/\s+/', ' ', trim($m[1]));
        preg_match_all(self::STACK, $classes, $stacks);
        $py = self::responsive($classes, 'py');
        $stack = self::responsive($classes, 'stack');
        $stackClasses = implode(' ', $stacks[0]);

        // A template that names a rhythm rather than numbers, such as stack-block, keeps them in tokens.
        if ($stack === [] && $tokens !== null && preg_match('/(?<![\w:-])(stack-[a-z][\w-]*)/', $classes, $named)) {
            $fromTokens = self::blockSpace($tokens);
            if ($fromTokens !== []) {
                $stack = $fromTokens;
                $stackClasses = $named[1];
            }
        }

        if ($stack === []) {
            return null;
        }

        return [
            'file' => basename($templatePath),
            'classes' => $classes,
            'stack_classes' => $stackClasses,
            'stack' => $stack,
            'padding_top' => self::responsive($classes, 'pt') ?: $py,
            'padding_bottom' => self::responsive($classes, 'pb') ?: $py,
        ];
    }

    /**
     * The block rhythm from --block-space tokens: the plain one for every width, then one per breakpoint,
     * such as --block-space-md. In steps of the spacing unit, so it reads the same as stack-12 did.
     *
     * @return array<string, float> breakpoint prefix ('' for every width) => steps
     */
    private static function blockSpace(CssTokens $tokens): array
    {
        $unit = self::unit($tokens);
        $out = [];
        foreach ($tokens->all() as $name => $token) {
            if ($name !== 'block-space' && ! str_starts_with($name, 'block-space-')) {
                continue;
            }
            $steps = self::steps((string) ($token['value'] ?? ''), $unit);
            if ($steps !== null) {
                $out[$name === 'block-space' ? '' : substr($name, 12)] = $steps;
            }
        }

        return $out;
    }

    /** Steps behind a token's value: calc(var(--spacing) * 12), or a plain length such as 3rem. */
    private static function steps(string $value, string $unit): ?float
    {
        if (preg_match('/calc\(\s*var\(--spacing\)\s*\*\s*([\d.]+)\s*\)/', $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^([\d.]+)rem$/', trim($value), $m)) {
            $size = (float) $unit;
            $size = str_ends_with(trim($unit), 'px') ? $size / 16 : $size;

            return $size > 0 ? (float) $m[1] / $size : null;
        }

        return null;
    }

    /**
     * Every stack value the site's views use, smallest first, with the views that use it. The section stack's own
     * classes are left out, because the style page shows them as section spacing.
     *
     * @param  array<string, string>  $names  view path without its extension (page_builder/_text) => the name to show
     * @return array<int, array{steps: float, class: string, used_in: array<int, string>}>
     */
    public static function stacks(string $viewsPath, array $names = [], ?array $section = null, ?string $sectionFile = null): array
    {
        $found = [];
        $root = rtrim(str_replace('\\', '/', $viewsPath), '/');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsPath, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! preg_match('/\.(antlers\.html|blade\.php)$/', $path)) {
                continue;
            }
            $key = (string) preg_replace('/\.(antlers\.html|blade\.php)$/', '', ltrim(substr($path, strlen($root)), '/'));
            $html = (string) file_get_contents($path);
            if ($section && $sectionFile && realpath($path) === realpath($sectionFile)) {
                $html = str_replace($section['classes'], '', (string) preg_replace('/\s+/', ' ', $html));
            }
            preg_match_all(self::STACK, $html, $m, PREG_SET_ORDER);
            foreach ($m as [, $prefix, $steps]) {
                $found[$steps][($names[$key] ?? self::humanise($key)).($prefix !== '' ? " (from {$prefix})" : '')] = true;
            }
        }
        ksort($found, SORT_NUMERIC);

        $out = [];
        foreach ($found as $steps => $where) {
            $where = array_keys($where);
            sort($where);
            $out[] = ['steps' => (float) $steps, 'class' => "stack-{$steps}", 'used_in' => $where];
        }

        return $out;
    }

    /** "default" becomes "Default template"; "components/_notification" becomes "Notification (components)". */
    private static function humanise(string $key): string
    {
        $base = Str::ucfirst(str_replace(['_', '-'], ' ', ltrim(basename($key), '_')));
        $dir = dirname($key);

        return $dir === '.' ? "{$base} template" : "{$base} ({$dir})";
    }
}
