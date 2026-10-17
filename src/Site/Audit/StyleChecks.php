<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Where the styling lives: BEM classes, classes only one template uses, files that only import another, rules that
 * override Peak's, an entry without source(none), and colours and type sizes written into templates.
 */
final class StyleChecks
{
    /** Stylesheets whose classes are the kit's or Peak's base rules, not a block's. */
    private const BASE = [
        'resources/css/theme.css', 'resources/css/colours.css', 'resources/css/typography.css', 'resources/css/peak.css',
        'resources/css/fonts.css', 'resources/css/components/buttons.css', 'resources/css/components/caption.css',
        'resources/css/components/clickable-parent.css',
    ];

    /** Libraries whose markup has to carry their own BEM classes. */
    private const LIBRARY_BLOCKS = ['glide', 'splide', 'swiper', 'flatpickr', 'choices', 'tippy', 'plyr', 'lg', 'fslightbox', 'pswp'];

    private const BEM = '/^([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?:__[a-z0-9]+(?:-[a-z0-9]+)*|--[a-z0-9]+(?:-[a-z0-9]+)*)+$/i';

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        $this->bem();
        $this->singleUse();
        $this->importOnly();
        $this->peakOverrides();
        $this->sourceNone();
        $this->templateValues();
        $this->applyCandidates();
    }

    /**
     * How much of a stylesheet utilities could say, written with @apply: a stylesheet that has to exist, such as an
     * animated illustration's, then keeps only what they can't. One finding for each stylesheet, counting the rules
     * that could be @apply in whole and in part, with the largest as examples. A rule in a breakpoint's or a motion
     * preference's media query takes its variant; one in any other at-rule, or a keyframe, is left as it is.
     */
    private function applyCandidates(): void
    {
        foreach ($this->files->stylesheets() as $path => $css) {
            if (in_array($path, ['resources/css/theme.css', 'resources/css/colours.css', 'resources/css/typography.css', 'resources/css/peak.css', 'resources/css/fonts.css'], true)
                || preg_match('~^resources/css/critical[^/]*\.css$~', $path)) {
                continue;
            }
            $whole = [];
            $part = [];
            $mapped = 0;
            $total = 0;
            foreach (Css::parse($css)['rules'] as $rule) {
                if (str_starts_with($rule['prelude'], '@') || $rule['declarations'] === []) {
                    continue;
                }
                $variant = '';
                foreach ($rule['parents'] as $parent) {
                    if (str_starts_with($parent, '@keyframes') || (str_starts_with($parent, '@') && ! str_starts_with($parent, '@layer') && ($v = Utilities::variant($parent)) === null)) {
                        continue 2;
                    }
                    $variant .= str_starts_with($parent, '@media') ? Utilities::variant($parent) : '';
                }
                $declarations = array_filter($rule['declarations'], fn (array $d) => ! str_starts_with($d['property'], '--'));
                $utilities = [];
                foreach ($declarations as $d) {
                    if (($utility = Utilities::for($d['property'], $d['value'])) !== null) {
                        $utilities[] = implode(' ', array_map(fn ($u) => $variant.$u, explode(' ', $utility)));
                    }
                }
                $total += count($declarations);
                $mapped += count($utilities);
                $selector = $rule['prelude'];
                foreach (array_reverse($rule['parents']) as $parent) {
                    if (! str_starts_with($parent, '@')) {
                        $selector = str_contains($selector, '&') ? str_replace('&', $parent, $selector) : "{$parent} {$selector}";
                        break;
                    }
                }
                $example = ['selector' => $selector, 'line' => Files::line($css, $rule['offset']), 'apply' => implode(' ', $utilities), 'count' => count($utilities)];
                if ($utilities !== [] && count($utilities) === count($declarations)) {
                    $whole[] = $example;
                } elseif (count($utilities) >= 2) {
                    $part[] = $example;
                }
            }
            if ($whole === [] && $part === []) {
                continue;
            }
            $examples = [...$whole, ...$part];
            usort($examples, fn (array $a, array $b) => $b['count'] <=> $a['count']);
            $rules = implode(' and ', array_filter([
                $whole !== [] ? Findings::count(count($whole), 'rule').' in whole' : null,
                $part !== [] ? ($whole !== [] ? count($part) : Findings::count(count($part), 'rule')).' in part' : null,
            ]));
            $this->out->add('apply-candidates', $path, min(array_column($examples, 'line')),
                sprintf('%s could be @apply, %d of its %d declarations: ', $rules, $mapped, $total)
                .implode('; ', array_map(fn (array $e) => "{$e['selector']} → {$e['apply']}", array_slice($examples, 0, 3))));
        }
    }

    /**
     * BEM class names, one finding for each block: where a stylesheet defines it, naming the templates that use it,
     * or, for a block no stylesheet defines, in each template that writes it.
     */
    private function bem(): void
    {
        $defined = [];
        foreach ($this->files->stylesheets() as $path => $css) {
            foreach (Css::parse($css)['rules'] as $rule) {
                if (str_starts_with($rule['prelude'], '@')) {
                    continue;
                }
                foreach (Css::classes($rule['prelude']) as $class) {
                    if (($block = self::bemBlock($class)) !== null) {
                        $defined[$block] ??= ['path' => $path, 'line' => Files::line($css, $rule['offset']), 'classes' => []];
                        $defined[$block]['classes'][] = '.'.$class;
                    }
                }
            }
        }

        $written = [];
        foreach ($this->files->templates() as $path => $html) {
            foreach (Markup::classTokens($html) as $token) {
                $class = Markup::className($token['token']);
                if ($class !== null && ($block = self::bemBlock($class)) !== null) {
                    $written[$block][$path] ??= ['line' => Files::line($html, $token['offset']), 'classes' => []];
                    $written[$block][$path]['classes'][] = $class;
                }
            }
        }

        foreach ($defined as $block => $use) {
            $templates = array_keys($written[$block] ?? []);
            $where = $templates === [] ? '' : ', written in '.Findings::list(array_map(fn (string $t) => basename($t), $templates), 3);
            $this->out->add('bem-class', $use['path'], $use['line'], "{$block}: ".Findings::count($use['classes'], 'BEM class').', '.Findings::list($use['classes']).$where);
        }
        foreach ($written as $block => $templates) {
            if (isset($defined[$block])) {
                continue;
            }
            foreach ($templates as $path => $use) {
                $this->out->add('bem-class', $path, $use['line'], "{$block}: ".Findings::count($use['classes'], 'BEM class').', '.Findings::list($use['classes']).', which no stylesheet defines');
            }
        }
    }

    private static function bemBlock(string $class): ?string
    {
        if (! preg_match(self::BEM, $class, $m) || in_array(strtolower($m[1]), self::LIBRARY_BLOCKS, true)) {
            return null;
        }

        return $m[1];
    }

    /**
     * A class a stylesheet defines that only one template uses and nothing else names: no script, no content, no
     * fieldset and none of Peak's views. One finding for each stylesheet and template.
     */
    private function singleUse(): void
    {
        $defined = [];
        foreach ($this->files->stylesheets() as $path => $css) {
            if (in_array($path, self::BASE, true)) {
                continue;
            }
            foreach (Css::parse($css)['rules'] as $rule) {
                if (str_starts_with($rule['prelude'], '@') || preg_grep('/^@(utility|custom-variant|keyframes)\b/', $rule['parents'])) {
                    continue;
                }
                foreach (Css::classes($rule['prelude']) as $class) {
                    $defined[$class] ??= ['path' => $path, 'line' => Files::line($css, $rule['offset'])];
                }
            }
        }
        if ($defined === []) {
            return;
        }

        $used = [];
        foreach ($this->files->templates() as $path => $html) {
            foreach (Markup::classTokens($html) as $token) {
                if (($class = Markup::className($token['token'])) !== null && isset($defined[$class])) {
                    $used[$class][$path] = true;
                }
            }
        }

        $scripts = implode("\n", $this->files->scripts());
        foreach ($this->files->templates() as $html) {
            $scripts .= "\n".implode("\n", array_column(Markup::inlineScripts($html), 0));
        }
        $elsewhere = implode("\n", $this->files->elsewhere());
        $applied = '';
        foreach ($this->files->stylesheets() as $css) {
            preg_match_all('/@apply\s+([^;]+);/', Css::withoutComments($css), $m);
            $applied .= ' '.implode(' ', $m[1]).' ';
        }

        $groups = [];
        foreach ($used as $class => $templates) {
            if (count($templates) !== 1 || self::bemBlock($class) !== null) {
                continue;   // a BEM class is reported as one already
            }
            $word = '/(?<![\w-])'.preg_quote($class, '/').'(?![\w-])/';
            if (preg_match($word, $scripts) || preg_match($word, $elsewhere) || preg_match($word, $applied)) {
                continue;
            }
            $template = array_key_first($templates);
            $groups[$defined[$class]['path']][$template][] = $class;
        }

        foreach ($groups as $stylesheet => $templates) {
            foreach ($templates as $template => $classes) {
                $line = min(array_map(fn (string $class) => $defined[$class]['line'], $classes));
                $this->out->add('single-use-class', $stylesheet, $line,
                    Findings::count($classes, 'class').' only '.$template.' uses: '.Findings::list(array_map(fn ($c) => '.'.$c, $classes)));
            }
        }
    }

    /** A stylesheet that holds nothing but an @import of another. */
    private function importOnly(): void
    {
        foreach ($this->files->stylesheets() as $path => $css) {
            $parsed = Css::parse($css);
            if ($parsed['rules'] === [] && count($parsed['statements']) === 1 && preg_match('/^@import\s+["\']([^"\']+)["\']/', $parsed['statements'][0]['text'], $m)) {
                $this->out->add('import-only', $path, Files::line($css, $parsed['statements'][0]['offset']), "only imports {$m[1]}");
            }
        }
    }

    /** A rule outside peak.css that sets a property Peak's rule for the same selector sets, or a utility Peak defines. */
    private function peakOverrides(): void
    {
        $peak = $this->files->get('resources/css/peak.css');
        if ($peak === null) {
            return;
        }
        $peakRules = self::bySelector($peak);

        foreach ($this->files->stylesheets() as $path => $css) {
            if ($path === 'resources/css/peak.css') {
                continue;
            }
            foreach (self::bySelector($css) as $selector => $rule) {
                if (! isset($peakRules[$selector])) {
                    continue;
                }
                if (str_starts_with($selector, '@utility')) {
                    if (! str_contains(substr($selector, 9), ' ')) {
                        $this->out->add('peak-override', $path, $rule['line'], "defines {$selector} again, which peak.css defines");
                    }

                    continue;
                }
                $same = array_values(array_intersect($rule['properties'], $peakRules[$selector]['properties']));
                if ($same !== []) {
                    $this->out->add('peak-override', $path, $rule['line'], "{$selector} sets ".Findings::list($same).', which peak.css sets for it');
                }
            }
        }
    }

    /**
     * Each selector with the properties set on it, with a nested & resolved against its parent.
     *
     * @return array<string, array{line: int, properties: array<int, string>}>
     */
    private static function bySelector(string $css): array
    {
        $out = [];
        foreach (Css::parse($css)['rules'] as $rule) {
            $prelude = $rule['prelude'];
            if (str_starts_with($prelude, '@utility')) {
                $out[$prelude] ??= ['line' => Files::line($css, $rule['offset']), 'properties' => []];

                continue;
            }
            if (str_starts_with($prelude, '@')) {
                continue;
            }
            // The nearest selector this rule is nested in, or the utility it is part of.
            $parent = null;
            foreach (array_reverse($rule['parents']) as $candidate) {
                if (! str_starts_with($candidate, '@') || str_starts_with($candidate, '@utility')) {
                    $parent = $candidate;
                    break;
                }
            }
            foreach (Css::selectors($prelude) as $selector) {
                if ($parent !== null && str_starts_with($parent, '@utility')) {
                    $selector = $parent.' '.$selector;
                } elseif ($parent !== null && str_contains($selector, '&')) {
                    $selector = str_replace('&', Css::selectors($parent)[0] ?? '', $selector);
                } elseif ($parent !== null) {
                    $selector = (Css::selectors($parent)[0] ?? '').' '.$selector;
                }
                $properties = array_column($rule['declarations'], 'property');
                $out[$selector] ??= ['line' => Files::line($css, $rule['offset']), 'properties' => []];
                $out[$selector]['properties'] = array_values(array_unique([...$out[$selector]['properties'], ...$properties]));
            }
        }

        return $out;
    }

    /** A stylesheet that brings Tailwind in and lets it scan the whole repository. */
    private function sourceNone(): void
    {
        foreach ($this->files->stylesheets() as $path => $css) {
            foreach (Css::parse($css)['statements'] as $statement) {
                if (preg_match('/^@import\s+["\']tailwindcss["\']/', $statement['text']) && ! str_contains($statement['text'], 'source(none)')) {
                    $this->out->add('source-none', $path, Files::line($css, $statement['offset']), 'imports tailwindcss without source(none), so Tailwind scans the whole repository');
                }
            }
        }
    }

    /** Colours and type sizes written into a template's classes or style attributes. One finding of each for each template. */
    private function templateValues(): void
    {
        $colour = '/\[(?:color:|background-color:)?(?:#[0-9a-f]{3,8}\b|(?:rgba?|hsla?|oklch|oklab|lab|lch|hwb)\()/i';
        $size = '/(?:^|:)text-\[(?:length:)?(?:\d*\.?\d+(?:px|rem|em)|clamp\()/';

        foreach ($this->files->templates() as $path => $html) {
            if (Markup::isEmail($path)) {
                continue;
            }
            $colours = [];
            $sizes = [];
            foreach (Markup::classTokens($html) as $token) {
                $word = trim($token['token'], "'\",{}");
                if (preg_match($colour, $word)) {
                    $colours[] = [$word, $token['offset']];
                } elseif (preg_match($size, $word)) {
                    $sizes[] = [$word, $token['offset']];
                }
            }
            $code = Markup::withoutComments($html);
            preg_match_all('~<svg\b.*?</svg>~is', $code, $svgs, PREG_OFFSET_CAPTURE);
            preg_match_all('/(?<![\w:-])style\s*=\s*(["\'])(.*?)\1/s', $code, $styles, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($styles as $style) {
                foreach ($svgs[0] as [$svg, $at]) {
                    if ($style[0][1] > $at && $style[0][1] < $at + strlen($svg)) {
                        continue 2;   // an inline SVG is artwork, like the SVG file it could be
                    }
                }
                if (preg_match_all('/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?|oklch|oklab)\([^)]*\)/i', $style[2][0], $values, PREG_OFFSET_CAPTURE)) {
                    foreach ($values[0] as [$value, $offset]) {
                        $colours[] = ["style {$value}", $style[2][1] + $offset];
                    }
                }
            }

            if ($colours !== []) {
                $this->out->add('arbitrary-colour', $path, Files::line($html, $colours[0][1]), Findings::count(count($colours), 'colour').' written in: '.Findings::list(array_column($colours, 0)));
            }
            if ($sizes !== []) {
                $this->out->add('arbitrary-text-size', $path, Files::line($html, $sizes[0][1]), Findings::count(count($sizes), 'type size').' written in: '.Findings::list(array_column($sizes, 0)));
            }
        }
    }
}
