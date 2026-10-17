<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The scripts: elements found by class, values read from a stylesheet, Alpine plugins nothing uses, and a
 * third-party script the layout prints for every page.
 */
final class ScriptChecks
{
    /** What each Alpine plugin adds, so a template or script that uses it can be found. */
    public const PLUGINS = [
        '@alpinejs/anchor' => ['x-anchor'],
        '@alpinejs/collapse' => ['x-collapse'],
        '@alpinejs/focus' => ['x-trap', '$focus'],
        '@alpinejs/intersect' => ['x-intersect'],
        '@alpinejs/mask' => ['x-mask', '$money'],
        '@alpinejs/morph' => ['Alpine.morph', 'x-morph'],
        '@alpinejs/persist' => ['$persist'],
        '@alpinejs/resize' => ['x-resize'],
        '@alpinejs/sort' => ['x-sort'],
        '@alpinejs/ui' => ['x-menu', 'x-listbox', 'x-combobox', 'x-dialog', 'x-disclosure', 'x-popover', 'x-radio', 'x-switch', 'x-tabs'],
        'laravel-precognition-alpine' => ['$form', 'Alpine.form'],
    ];

    /** Scripts only the pages with a form, a map, a video or a booking need. */
    private const PAGE_SCRIPTS = [
        'google.com/recaptcha', 'recaptcha.net', 'hcaptcha.com', 'challenges.cloudflare.com/turnstile', 'maps.googleapis.com',
        'api.mapbox.com', 'youtube.com/iframe_api', 'player.vimeo.com/api', 'js.stripe.com', 'assets.calendly.com',
        'js.hsforms.net', 'embed.typeform.com',
    ];

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        $this->classSelectors();
        $this->cssReads();
        $this->plugins();
        $this->layoutScripts();
    }

    /** querySelector, closest and the like given a class, in a script or in a template's Alpine. */
    private function classSelectors(): void
    {
        $sources = [];
        foreach ($this->files->scripts() as $path => $js) {
            $sources[$path] = [$js, Js::withoutComments($js)];
        }
        foreach ($this->files->templates() as $path => $html) {
            $sources[$path] = [$html, Markup::withoutComments($html)];
        }

        foreach ($sources as $path => [$original, $code]) {
            preg_match_all('/\b(querySelectorAll|querySelector|closest|matches)\(\s*([\'"`])((?:(?!\2).)*)\2|\bgetElementsByClassName\(/s', $code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                $selector = $call[3][0] ?? null;
                if ($selector === null) {
                    $this->out->add('script-class-selector', $path, Files::line($original, $call[0][1]), 'getElementsByClassName finds elements by class');

                    continue;
                }
                $bare = (string) preg_replace(['/\$\{[^}]*\}/', '/\[[^\]]*\]/', '/(["\']).*?\1/'], '', $selector);
                if (preg_match('/(?<![\w\\\\])\.[A-Za-z_-][\w-]*/', $bare)) {
                    $this->out->add('script-class-selector', $path, Files::line($original, $call[0][1]), "{$call[1][0]}('{$selector}') finds an element by class");
                }
            }
        }
    }

    /**
     * A custom property a script reads with getPropertyValue that only a stylesheet sets: not the template, in its
     * style attribute or an arbitrary property class, not a script, and not an @theme static token, which the
     * recipe keeps for exactly this.
     */
    private function cssReads(): void
    {
        $set = [];
        foreach ($this->files->templates() as $html) {
            preg_match_all('/(?<![\w-])(?::|x-bind:)?style\s*=\s*(["\'])(.*?)\1/s', $html, $styles);
            foreach ($styles[2] as $style) {
                preg_match_all('/--[\w-]+/', $style, $names);
                $set += array_fill_keys($names[0], true);
            }
            preg_match_all('/\[(--[\w-]+):/', $html, $names);
            $set += array_fill_keys($names[1], true);
        }
        foreach ($this->files->scripts() as $js) {
            preg_match_all('/(?:setProperty|removeProperty)\(\s*[\'"`](--[\w-]+)/', $js, $names);
            $set += array_fill_keys($names[1], true);
        }

        $static = [];
        $declared = [];
        foreach ($this->files->stylesheets() as $path => $css) {
            foreach (Css::parse($css)['rules'] as $rule) {
                foreach ($rule['declarations'] as $d) {
                    if (str_starts_with($d['property'], '--')) {
                        $declared[$d['property']][$path] = true;
                        if (preg_match('/^@theme\b.*\bstatic\b/', $rule['prelude'])) {
                            $static[$d['property']] = true;
                        }
                    }
                }
            }
        }

        foreach ($this->files->scripts() as $path => $js) {
            $code = Js::withoutComments($js);
            if (! str_contains($code, 'getPropertyValue(')) {
                continue;
            }
            // A helper that reads a name it is given, such as readMs(styles, '--duration'), reads every name written in the file.
            $pattern = preg_match('/getPropertyValue\(\s*[A-Za-z_$]/', $code)
                ? '/(?<!setProperty\(|removeProperty\()([\'"`])(--[\w-]+)\1/'
                : '/getPropertyValue\(\s*([\'"`])(--[\w-]+)\1/';
            preg_match_all($pattern, $code, $reads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($reads as $read) {
                $name = $read[2][0];
                if (isset($set[$name]) || isset($static[$name])) {
                    continue;
                }
                $from = isset($declared[$name]) ? 'which only '.Findings::list(array_map('basename', array_keys($declared[$name]))).' sets' : 'which no template sets';
                $this->out->add('script-reads-css', $path, Files::line($js, $read[0][1]), "reads {$name}, {$from}: pass it in x-data, or set it in the element's style");
            }
        }
    }

    /** An Alpine plugin registered that no template, script or Peak view uses. */
    private function plugins(): void
    {
        $haystack = '';
        foreach ($this->files->templates() as $html) {
            $haystack .= Markup::withoutComments($html)."\n";
        }
        foreach ($this->files->scripts() as $js) {
            $haystack .= Js::withoutComments($js)."\n";
        }
        foreach ($this->files->elsewhere() as $path => $contents) {
            if (str_starts_with($path, 'vendor/')) {
                $haystack .= $contents."\n";
            }
        }

        foreach ($this->files->scripts() as $path => $js) {
            $code = Js::withoutComments($js);
            preg_match_all('/import\s+([\w$]+)\s+from\s+[\'"]([^\'"]+)[\'"]/', $code, $imports, PREG_SET_ORDER);
            $packages = array_column($imports, 2, 1);
            // Alpine.plugin(collapse) or Alpine.plugin([collapse, focus])
            preg_match_all('/Alpine\.plugin\(\s*(\[[^\]]*\]|[\w$]+)/', $code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                preg_match_all('/[\w$]+/', $call[1][0], $names);
                foreach ($names[0] as $name) {
                    $package = $packages[$name] ?? null;
                    $markers = self::PLUGINS[$package] ?? null;
                    if ($markers === null) {
                        continue;
                    }
                    foreach ($markers as $marker) {
                        if (preg_match('/(?<![\w-])'.preg_quote($marker, '/').'(?![\w-])/', $haystack)) {
                            continue 2;
                        }
                    }
                    $this->out->add('unused-alpine-plugin', $path, Files::line($js, $call[0][1]), "registers {$package}, and nothing uses ".implode(' or ', $markers));
                }
            }
        }
    }

    /** A captcha, map, video or booking script printed by the layout, so every page loads it. */
    private function layoutScripts(): void
    {
        foreach ($this->files->templates() as $path => $html) {
            if (! preg_match('~^resources/views/(layout\.antlers\.html|layout/|snippets/)~', $path)) {
                continue;
            }
            $code = Markup::withoutComments($html);
            preg_match_all('/\{\{\s*((?:re|h)?captcha|turnstile)[:\s][^}]*\}\}/', $code, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                $this->out->add('layout-third-party', $path, Files::line($html, $tag[0][1]), trim($tag[0][0]).' loads on every page: push it from the block that needs it');
            }
            preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $code, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($scripts as $script) {
                foreach (self::PAGE_SCRIPTS as $host) {
                    if (str_contains($script[1][0], $host)) {
                        $this->out->add('layout-third-party', $path, Files::line($html, $script[0][1]), "{$host} loads on every page: push it from the block that needs it");
                    }
                }
            }
        }
    }
}
