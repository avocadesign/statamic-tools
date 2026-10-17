<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The token layer: aliases of Tailwind's tokens, two tokens holding one value, a spacing scale of the site's own,
 * tokens declared outside the files the recipe keeps them in, and palette colours kept outside colours.css.
 */
final class TokenChecks
{
    /** Tailwind's theme namespaces, the longer names first so --font-weight-bold isn't read as a font. */
    public const NAMESPACES = [
        'font-weight', 'inset-shadow', 'drop-shadow', 'text-shadow',
        'color', 'font', 'text', 'tracking', 'leading', 'breakpoint', 'container', 'spacing', 'radius', 'shadow',
        'blur', 'perspective', 'aspect', 'ease', 'animate',
    ];

    /**
     * Names the kit and Peak read, which stay as var() of the token they repeat: the scheme hooks, the prose and
     * heading wiring, the grid's own, and the grey scale the kit maps onto slate. An entry covers the names under it.
     */
    public const KIT_NAMES = [
        '--btn', '--typography', '--prose', '--focus', '--table', '--color-gray', '--block-space', '--stack', '--default',
        '--body-color', '--headings-color', '--color-primary', '--color-light', '--color-dark', '--form-error',
        '--divider-colour', '--border-colour', '--quote-mark-colour', '--scheme-block-padding',
        '--spacing-fluid-grid-gap', '--spacing-safe', '--content-max-width', '--col-gap', '--col-width', '--side-width',
        '--padding-left', '--padding-right',
    ];

    /** Where the recipe keeps tokens: theme.css and colours.css, Peak's in typography.css and its own in peak.css. */
    private const TOKEN_FILES = ['resources/css/theme.css', 'resources/css/colours.css', 'resources/css/typography.css', 'resources/css/peak.css'];

    /** How far a colour may be from one of the palette's, in OKLab, and still be a variant of it. */
    public const CLOSE = 0.05;

    /** @var array<string, array{value: string, site: bool, path: string, line: int|null}>|null */
    private ?array $defaults = null;

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        $declared = [];
        $hooks = [];
        foreach ($this->files->stylesheets() as $path => $css) {
            foreach (Css::parse($css)['rules'] as $rule) {
                $theme = Css::isTheme($rule['prelude']);
                if (! $theme && ! Css::isRoot($rule['prelude'])) {
                    foreach ($rule['declarations'] as $d) {
                        $hooks[$d['property']] = true;
                    }

                    continue;
                }
                $tokens = array_values(array_filter($rule['declarations'], fn (array $d) => str_starts_with($d['property'], '--')));
                foreach ($tokens as $d) {
                    $declared[] = $d + ['path' => $path, 'line' => Files::line($css, $d['offset']), 'theme' => $theme];
                }
                // A component's own stylesheet may hold its parameters on :root; a theme block anywhere else makes utilities.
                $component = str_starts_with($path, 'resources/css/components/') && ! $theme;
                if ($tokens !== [] && ! $component && ! in_array($path, self::TOKEN_FILES, true)) {
                    $names = array_column($tokens, 'property');
                    $this->out->add('token-elsewhere', $path, Files::line($css, $rule['offset']),
                        'declares '.Findings::count($names, 'token').' of its own in '.($theme ? $rule['prelude'] : $rule['prelude'].' { }').': '.Findings::list($names));
                }
            }
        }

        $this->aliases($declared, $hooks);
        $this->duplicates(array_values(array_filter($declared, fn (array $d) => $d['theme'])));
        $this->scales($declared);
        $this->componentColours();
    }

    /**
     * A colour outside colours.css that varies the palette, such as a color-mix() of its colours, or that matches
     * or comes close to one of them: the palette lives in one file. One finding for each file's mixes, and one for
     * each colour written out.
     */
    private function componentColours(): void
    {
        $all = [];
        $palette = [];
        foreach ($this->files->stylesheets() as $path => $css) {
            foreach (Css::parse($css)['rules'] as $rule) {
                foreach ($rule['declarations'] as $d) {
                    if (str_starts_with($d['property'], '--')) {
                        $all[$d['property']] ??= $d['value'];
                        if ($path === 'resources/css/colours.css' && Css::isTheme($rule['prelude'])) {
                            $palette[$d['property']] = $d['value'];
                        }
                    }
                }
            }
        }
        $known = $palette + array_map(fn (array $t) => $t['value'], $this->tailwindDefaults()) + $all;
        $isPalette = fn (string $name) => isset($palette[$name]) || str_starts_with($name, '--color-');

        $colours = [];
        foreach (array_keys($palette) as $name) {
            if (($lab = Colour::oklab($this->resolve($name, $known))) !== null) {
                $colours[$name] = $lab;
            }
        }

        foreach ($this->files->stylesheets() as $path => $css) {
            if (in_array($path, self::TOKEN_FILES, true)) {
                continue;
            }
            $mixes = [];
            foreach (Css::parse($css)['rules'] as $rule) {
                foreach ($rule['declarations'] as $d) {
                    if (! str_starts_with($d['property'], '--') || preg_match('/^var\([^()]*\)$/', $d['value'])) {
                        continue;   // not a token, or an alias, which token-alias reports
                    }
                    if (preg_match('/color-mix\(|\bfrom\s+var\(/', $d['value']) && $this->reaches($d['value'], $isPalette, $all)) {
                        $mixes[] = ['name' => $d['property'], 'line' => Files::line($css, $d['offset'])];

                        continue;
                    }
                    if (($lab = Colour::oklab($d['value'])) === null || $colours === []) {
                        continue;
                    }
                    $distances = array_map(fn (array $c) => Colour::distance($lab, $c), $colours);
                    asort($distances);
                    $nearest = array_key_first($distances);
                    if ($distances[$nearest] < self::CLOSE) {
                        $this->out->add('component-colour', $path, Files::line($css, $d['offset']),
                            "{$d['property']}: {$d['value']} is ".($distances[$nearest] < 0.02 ? 'the same as' : 'close to')." {$nearest}");
                    }
                }
            }
            if ($mixes !== []) {
                $names = array_values(array_unique(array_column($mixes, 'name')));
                $this->out->add('component-colour', $path, $mixes[0]['line'], Findings::count($names, 'colour').(count($names) === 1 ? ' varies' : ' vary').' the palette: '.Findings::list($names));
            }
        }
    }

    /** Whether a value reads a palette colour, directly or through the custom properties it reads. */
    private function reaches(string $value, callable $isPalette, array $all, int $depth = 0): bool
    {
        preg_match_all('/var\(\s*(--[\w-]+)/', $value, $m);
        foreach ($m[1] as $name) {
            if ($isPalette($name) || ($depth < 4 && isset($all[$name]) && $this->reaches($all[$name], $isPalette, $all, $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /** A token's value with var() followed to the value it ends at. */
    private function resolve(string $name, array $known): string
    {
        $value = $known[$name] ?? '';
        for ($i = 0; $i < 5 && preg_match('/^var\(\s*(--[\w-]+)\s*\)$/', $value, $m); $i++) {
            $value = $known[$m[1]] ?? '';
        }

        return $value;
    }

    /**
     * A token whose whole value is var() of one in Tailwind's namespaces, under a name the kit doesn't read and no
     * rule sets again, as a colour scheme or a variant would: one set again is a hook, not a second name.
     *
     * @param  array<string, true>  $hooks  the custom properties set by rules other than :root and @theme
     */
    private function aliases(array $declared, array $hooks): void
    {
        foreach ($declared as $d) {
            if (! preg_match('/^var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)$/', $d['value'], $m)) {
                continue;
            }
            if (self::namespace($m[1]) === null || self::isKitName($d['property']) || self::isCompanion($d['property']) || isset($hooks[$d['property']])) {
                continue;
            }
            $this->out->add('token-alias', $d['path'], $d['line'], "{$d['property']} only points at {$m[1]}: use {$m[1]}");
        }
    }

    /**
     * Two tokens in one of Tailwind's namespaces with the same value, Tailwind's own defaults among them when the
     * site has node_modules, so a radius of the site's own that repeats one of Tailwind's steps is found too.
     */
    private function duplicates(array $declared): void
    {
        $tokens = $this->tailwindDefaults();
        // A reset such as --radius-*: initial takes Tailwind's own steps out of the namespace, and --*: initial all of them.
        foreach ($declared as $d) {
            if ($d['value'] === 'initial' && preg_match('/^(--[\w-]*?)-?\*$/', $d['property'], $m)) {
                $tokens = array_filter($tokens, fn (array $t, string $name) => $t['site'] || ! str_starts_with($name, $m[1] === '--' ? '--' : $m[1].'-'), ARRAY_FILTER_USE_BOTH);
            }
        }
        foreach ($declared as $d) {
            if (! str_contains($d['property'], '*')) {
                $tokens[$d['property']] = ['value' => $d['value'], 'site' => true, 'path' => $d['path'], 'line' => $d['line']];
            }
        }

        $groups = [];
        foreach ($tokens as $name => $token) {
            $namespace = self::namespace($name);
            if ($namespace === null || self::isCompanion($name) || str_contains($token['value'], 'var(') || in_array($token['value'], ['', 'initial'], true)) {
                continue;
            }
            $groups[$namespace][self::normalise($token['value'])][$name] = $token;
        }

        foreach ($groups as $byValue) {
            foreach ($byValue as $value => $same) {
                $site = array_filter($same, fn (array $t) => $t['site']);
                if (count($same) < 2 || $site === []) {
                    continue;
                }
                $first = reset($site);
                $others = array_map(fn (string $name) => $same[$name]['site'] ? $name : "Tailwind's {$name}", array_keys($same));
                $this->out->add('token-duplicate', $first['path'], $first['line'], self::join($others).(count($others) === 2 ? ' both hold ' : ' all hold ').$value);
            }
        }
    }

    /** A spacing scale of the site's own: three or more stepped names holding lengths, or --spacing-N steps Tailwind already makes. */
    private function scales(array $declared): void
    {
        $own = [];
        $steps = [];
        foreach ($declared as $d) {
            if (! preg_match('/^-?[\d.]+(px|rem|em)$/', $d['value'])) {
                continue;
            }
            if (preg_match('/^--(?:s|sp|space|spacer|spacing|gap|size)-?(?:\d+(?:\.\d+)?|[2-9]?x[sl]|xs|sm|md|lg|xl|[2-9]xl)$/', $d['property'])) {
                if (preg_match('/^--spacing-\d+(?:\.\d+)?$/', $d['property'])) {
                    $steps[$d['path']][] = $d;
                } else {
                    $own[$d['path']][] = $d;
                }
            }
        }

        foreach ($own as $path => $tokens) {
            if (count($tokens) >= 3) {
                $this->out->add('token-scale', $path, $tokens[0]['line'], 'a spacing scale of its own, '.Findings::list(array_column($tokens, 'property')).': write each as a step of --spacing');
            }
        }
        foreach ($steps as $path => $tokens) {
            $this->out->add('token-scale', $path, $tokens[0]['line'], Findings::list(array_column($tokens, 'property')).' repeat what --spacing already gives every step: remove them');
        }
    }

    /** @return array<string, array{value: string, site: bool, path: string, line: int|null}> */
    private function tailwindDefaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }
        $theme = $this->files->get('node_modules/tailwindcss/theme.css');
        if ($theme === null) {
            $this->out->note('node_modules/tailwindcss is not installed, so tokens were compared with each other but not with Tailwind\'s own');

            return $this->defaults = [];
        }

        $tokens = [];
        foreach (Css::parse($theme)['rules'] as $rule) {
            if (Css::isTheme($rule['prelude'])) {
                foreach ($rule['declarations'] as $d) {
                    $tokens[$d['property']] = ['value' => $d['value'], 'site' => false, 'path' => 'node_modules/tailwindcss/theme.css', 'line' => null];
                }
            }
        }

        return $this->defaults = $tokens;
    }

    public static function namespace(string $name): ?string
    {
        foreach (self::NAMESPACES as $namespace) {
            if ($name === "--{$namespace}" || str_starts_with($name, "--{$namespace}-")) {
                return $namespace;
            }
        }

        return null;
    }

    public static function isKitName(string $name): bool
    {
        foreach (self::KIT_NAMES as $kit) {
            if ($name === $kit || str_starts_with($name, $kit.'-')) {
                return true;
            }
        }

        return false;
    }

    /** Tailwind's own pairing of a step with its line height or weight, such as --text-xl--line-height. */
    private static function isCompanion(string $name): bool
    {
        return (bool) preg_match('/^--[\w-]+?[^-]--[\w-]+$/', $name);
    }

    private static function normalise(string $value): string
    {
        $value = strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
        $value = (string) preg_replace('/\s*([,\/()])\s*/', '$1', $value);
        $value = (string) preg_replace('/(?<![\d.])0+(\.\d)/', '$1', $value);

        return (string) preg_replace_callback('/#([0-9a-f])([0-9a-f])([0-9a-f])\b/', fn (array $m) => "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}", $value);
    }

    /** @param array<int, string> $names */
    private static function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
