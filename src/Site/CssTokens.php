<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * Every @theme token reachable from the site's CSS entry point, found by following
 * relative @import statements. Values are kept as written: the style page renders them
 * with var(--name) and lets the browser resolve them, so the page cannot disagree
 * with the CSS.
 */
final class CssTokens
{
    /** @var array<string, array{value: string, file: string}> */
    private array $tokens = [];

    /** @var array<int, string> */
    private array $files = [];

    /** @var array<string, string> comment-stripped CSS by file path, for reading rules */
    private array $css = [];

    public static function fromEntry(string $entry): self
    {
        $self = new self;
        $self->read(realpath($entry) ?: $entry);

        return $self;
    }

    private function read(string $file): void
    {
        if (in_array($file, $this->files, true) || ! is_file($file)) {
            return;
        }

        $this->files[] = $file;
        // Comments first: upstream ships commented-out example tokens, which are not declarations.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
        $this->css[$file] = $css;

        foreach ($this->themeBlocks($css) as $block) {
            preg_match_all('/--([A-Za-z0-9_-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, $value]) {
                if (str_contains($name, '*')) {
                    continue; // resets such as --font-weight-*: initial
                }
                $this->tokens[$name] = ['value' => trim($value), 'file' => basename($file)];
            }
        }

        preg_match_all('/@import\s+"([^"]+)"/', $css, $imports);
        foreach ($imports[1] as $import) {
            if (str_starts_with($import, '.')) {
                $this->read(dirname($file).'/'.$import);
            }
        }
    }

    /** @return array<int, string> */
    private function themeBlocks(string $css): array
    {
        $blocks = [];
        $offset = 0;

        while (($at = strpos($css, '@theme', $offset)) !== false) {
            $open = strpos($css, '{', $at);
            if ($open === false) {
                break;
            }
            $depth = 0;
            $close = null;
            for ($i = $open, $n = strlen($css); $i < $n; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}' && --$depth === 0) {
                    $close = $i;
                    break;
                }
            }
            if ($close === null) {
                break;
            }
            $blocks[] = substr($css, $open + 1, $close - $open - 1);
            $offset = $close;
        }

        return $blocks;
    }

    /**
     * One rule from the site's CSS, found by its exact selector (".fluid-grid", "@utility span-full"):
     * its own declarations, its @apply utilities, and the declarations inside each nested @media block.
     *
     * @return array{file: string, declarations: array<string, string>, apply: array<int, string>, media: array<string, array<string, string>>}|null
     */
    public function rule(string $selector): ?array
    {
        foreach ($this->css as $file => $css) {
            if (! preg_match('/(?:^|[\s{};])'.preg_quote($selector, '/').'\s*\{/', $css, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            if (($body = $this->body($css, $open)) === null) {
                continue;
            }
            [$own, $nested] = $this->split($body);
            $media = [];
            foreach ($nested as [$prelude, $inner]) {
                if (str_starts_with($prelude, '@media')) {
                    $media[trim(substr($prelude, 6))] = $this->declarations($this->split($inner)[0]);
                }
            }
            preg_match_all('/@apply\s+([^;]+);/', $own, $apply);

            return [
                'file' => basename($file),
                'declarations' => $this->declarations($own),
                'apply' => preg_split('/\s+/', trim(implode(' ', $apply[1])), -1, PREG_SPLIT_NO_EMPTY),
                'media' => $media,
            ];
        }

        return null;
    }

    /** The text between the brace at $open and its matching closing brace. */
    private function body(string $css, int $open): ?string
    {
        for ($i = $open, $depth = 0, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return substr($css, $open + 1, $i - $open - 1);
            }
        }

        return null;
    }

    /** A block body split into its own text and its nested blocks, each nested block with the prelude before its brace. */
    private function split(string $body): array
    {
        $own = '';
        $nested = [];
        $prelude = '';
        $start = 0;
        for ($i = 0, $depth = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($c === '{') {
                if ($depth === 0) {
                    $cut = strrpos($own, ';');
                    $cut = $cut === false ? -1 : $cut;
                    $prelude = trim(substr($own, $cut + 1));
                    $own = substr($own, 0, $cut + 1);
                    $start = $i + 1;
                }
                $depth++;
            } elseif ($c === '}') {
                if (--$depth === 0) {
                    $nested[] = [$prelude, substr($body, $start, $i - $start)];
                    $own .= ';';
                }
            } elseif ($depth === 0) {
                $own .= $c;
            }
        }

        return [$own, $nested];
    }

    /** @return array<string, string> property => value for plain declarations (not @apply) */
    private function declarations(string $text): array
    {
        preg_match_all('/(?:^|;)\s*(--[A-Za-z0-9_-]+|[a-z-]+)\s*:\s*([^;]+)/', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $prop, $value]) {
            $out[$prop] = trim($value);
        }

        return $out;
    }

    /** @return array<string, array{value: string, file: string}> */
    public function all(): array
    {
        return $this->tokens;
    }

    /** @return array<int, string> */
    public function files(): array
    {
        return array_map('basename', $this->files);
    }

    /** @return array<string, string> name => value for tokens starting with $prefix */
    public function prefixed(string $prefix): array
    {
        $out = [];
        foreach ($this->tokens as $name => $token) {
            if (str_starts_with($name, $prefix)) {
                $out[$name] = $token['value'];
            }
        }

        return $out;
    }

    /**
     * Colour tokens in display order: the semantic colours first (primary, light, dark, then
     * black and white), then the text colours, then each numbered scale in weight order, then the
     * prose tokens. Order on the page does not depend on order in the CSS files.
     *
     * @return array<string, array<int, array{name: string, label: string, value: string, file: string}>>
     */
    public function colourGroups(): array
    {
        $colours = [];
        foreach ($this->tokens as $name => $token) {
            if (str_starts_with($name, 'color-') && ! in_array(substr($name, 6), ['current', 'transparent', 'inherit'], true)) {
                $colours[substr($name, 6)] = ['name' => $name, 'label' => substr($name, 6), 'value' => $token['value'], 'file' => $token['file']];
            }
        }

        $groups = [];
        $semantic = [];
        foreach (['primary', 'light', 'dark', 'black', 'white'] as $key) {
            if (isset($colours[$key])) {
                $semantic[] = $colours[$key];
                unset($colours[$key]);
            }
        }
        if ($semantic) {
            $groups['Semantic'] = $semantic;
        }

        $text = [];
        foreach (['body-color', 'headings-color'] as $name) {
            if (isset($this->tokens[$name])) {
                $text[] = ['name' => $name, 'label' => $name, 'value' => $this->tokens[$name]['value'], 'file' => $this->tokens[$name]['file']];
            }
        }
        if ($text) {
            $groups['Text'] = $text;
        }

        $scales = [];
        foreach ($colours as $key => $token) {
            if (preg_match('/^([a-z]+)(?:-(\d+))?$/', $key, $m)) {
                $scales[$m[1]][(int) ($m[2] ?? 0)] = $token;
                unset($colours[$key]);
            }
        }
        // The site's own neutral scale first, then any other scale (Peak's gray) alphabetically.
        uksort($scales, fn ($a, $b) => ($a === 'neutral' ? 0 : 1) <=> ($b === 'neutral' ? 0 : 1) ?: strcmp($a, $b));
        $aliases = [];
        foreach ($scales as $scale => $steps) {
            ksort($steps);
            // A lone unnumbered token (e.g. --color-neutral: var(--color-gray-800)) is an alias, not a scale.
            if (count($steps) === 1 && array_key_first($steps) === 0) {
                $aliases[] = $steps[0];
                continue;
            }
            $groups[ucfirst($scale).' scale'] = array_values($steps);
        }
        if ($aliases) {
            $groups['Aliases'] = $aliases;
        }
        if ($colours) {
            $groups['Other'] = array_values($colours);
        }

        $prose = [];
        foreach ($this->tokens as $name => $token) {
            if (str_starts_with($name, 'prose-') && ! str_starts_with($name, 'prose-invert') && ! str_ends_with($name, '-modifier') && $this->isColour($token['value'])) {
                $prose[] = ['name' => $name, 'label' => substr($name, 6), 'value' => $token['value'], 'file' => $token['file']];
            }
        }
        if ($prose) {
            $groups['Prose'] = $prose;
        }

        return $groups;
    }

    /**
     * Whether a token's value is a colour, following var() references through the other tokens. The prose group holds
     * the prose colours, so a --prose-* token whose value is a length, such as the space between paragraphs, is left out.
     */
    private function isColour(string $value, int $depth = 0): bool
    {
        $value = trim($value);
        if ($value === '' || $depth > 4) {
            return false;
        }
        if (preg_match('/^(#|rgba?\(|hsla?\(|oklch\(|oklab\(|lab\(|lch\(|color\(|color-mix\()/i', $value)) {
            return true;
        }
        if (preg_match('/^var\(\s*--([A-Za-z0-9_-]+)/', $value, $match)) {
            return str_starts_with($match[1], 'color-')
                || (isset($this->tokens[$match[1]]) && $this->isColour($this->tokens[$match[1]]['value'], $depth + 1));
        }

        // A bare keyword such as white or currentColor, but never a length, a number or a calculation.
        return (bool) preg_match('/^[a-z]+$/i', $value) && ! in_array(strtolower($value), ['inherit', 'initial', 'unset', 'revert', 'none', 'auto'], true);
    }

    /** The fluid type scale: --text-* size tokens (line-height companions excluded). */
    public function typeScale(): array
    {
        $out = [];
        foreach ($this->prefixed('text-') as $name => $value) {
            if (! str_contains($name, '--')) {
                $out[] = ['name' => $name, 'label' => substr($name, 5), 'value' => $value];
            }
        }

        return $out;
    }

    public function typographyTokens(): array
    {
        return $this->list('typography-', 11);
    }

    public function fonts(): array
    {
        return array_values(array_filter($this->list('font-', 5), fn ($t) => ! str_starts_with($t['name'], 'font-weight')));
    }

    public function weights(): array
    {
        return $this->list('font-weight-', 12);
    }

    private function list(string $prefix, int $trim): array
    {
        $out = [];
        foreach ($this->prefixed($prefix) as $name => $value) {
            $out[] = ['name' => $name, 'label' => substr($name, $trim), 'value' => $value];
        }

        return $out;
    }
}
