<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Just enough of a CSS reader for the audit: rules with their selectors and declarations, and statements such as
 * @import and @source, each with its offset in the file so a finding can name its line.
 */
final class Css
{
    /**
     * Every comment outside strings, so the glob in an @source path, which holds a slash and two stars, is not one.
     *
     * @return array<int, array{text: string, offset: int}>
     */
    public static function comments(string $css): array
    {
        $comments = [];
        $quote = null;
        for ($i = 0, $n = strlen($css); $i < $n; $i++) {
            $c = $css[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
                $comments[] = ['text' => substr($css, $i, $end - $i), 'offset' => $i];
                $i = $end - 1;
            }
        }

        return $comments;
    }

    /** The CSS with its comments blanked to spaces, so offsets and line numbers still match the file. */
    public static function withoutComments(string $css): string
    {
        foreach (self::comments($css) as $comment) {
            $css = substr_replace($css, (string) preg_replace('/[^\n]/', ' ', $comment['text']), $comment['offset'], strlen($comment['text']));
        }

        return $css;
    }

    /**
     * Every block in the file, flattened, and every statement that ends in a semicolon rather than a block.
     *
     * A rule's declarations are its own, not those of the blocks nested in it. A nested selector keeps its &, and
     * `parents` holds the preludes it sits in, outermost first.
     *
     * @return array{rules: array<int, array{prelude: string, parents: array<int, string>, offset: int, declarations: array<int, array{property: string, value: string, offset: int}>}>, statements: array<int, array{text: string, offset: int, parents: array<int, string>}>}
     */
    public static function parse(string $css): array
    {
        $css = self::withoutComments($css);
        $rules = [];
        $statements = [];
        $stack = [['prelude' => '', 'parents' => [], 'offset' => 0, 'declarations' => []]];
        $buffer = '';
        $start = 0;
        $quote = null;
        $parens = 0;

        $flush = function () use (&$buffer, &$start, &$stack, &$statements): void {
            $text = trim($buffer);
            if ($text !== '') {
                $offset = $start + strlen($buffer) - strlen(ltrim($buffer));
                $frame = &$stack[count($stack) - 1];
                if ($text[0] === '@') {
                    $statements[] = ['text' => (string) preg_replace('/\s+/', ' ', $text), 'offset' => $offset, 'parents' => self::preludes($stack)];
                } elseif (preg_match('/^(-{0,2}[A-Za-z_*][\w*-]*)\s*:(.*)$/s', $text, $m)) {
                    $frame['declarations'][] = ['property' => $m[1], 'value' => trim((string) preg_replace('/\s+/', ' ', $m[2])), 'offset' => $offset];
                }
            }
            $buffer = '';
        };

        for ($i = 0, $n = strlen($css); $i < $n; $i++) {
            $c = $css[$i];
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $buffer .= $css[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $parens++;
            } elseif ($c === ')') {
                $parens = max(0, $parens - 1);
            }
            if ($parens > 0 || ! in_array($c, ['{', '}', ';'], true)) {
                $buffer .= $c;

                continue;
            }
            if ($c === ';') {
                $flush();
                $start = $i + 1;
            } elseif ($c === '{') {
                $prelude = trim((string) preg_replace('/\s+/', ' ', $buffer));
                $offset = $start + strlen($buffer) - strlen(ltrim($buffer));
                $stack[] = ['prelude' => $prelude, 'parents' => self::preludes($stack), 'offset' => $offset, 'declarations' => []];
                $buffer = '';
                $start = $i + 1;
            } else {
                $flush();
                if (count($stack) > 1) {
                    $rules[] = array_pop($stack);
                }
                $start = $i + 1;
            }
        }

        usort($rules, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return ['rules' => $rules, 'statements' => $statements];
    }

    /**
     * A selector list split at its top-level commas, each with its whitespace collapsed.
     *
     * @return array<int, string>
     */
    public static function selectors(string $prelude): array
    {
        $out = [];
        $depth = 0;
        $current = '';
        foreach (str_split($prelude) as $c) {
            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $c;
        }
        $out[] = trim($current);

        return array_values(array_filter($out, fn (string $s) => $s !== ''));
    }

    /**
     * The class names a selector names, leaving out what sits inside attribute selectors and Tailwind's escaped
     * utilities, such as .md\:flex.
     *
     * @return array<int, string>
     */
    public static function classes(string $selector): array
    {
        $selector = (string) preg_replace('/\[[^\]]*\]/', '', $selector);
        preg_match_all('/\.(-?[A-Za-z_][\w-]*+)(?!\\\\)/', $selector, $m);

        return array_values(array_unique($m[1]));
    }

    /** True when the block is a theme block: @theme, @theme static, @theme inline and the like. */
    public static function isTheme(string $prelude): bool
    {
        return (bool) preg_match('/^@theme\b/', $prelude);
    }

    /** True when the block is :root or html, where a stylesheet declares tokens outside @theme. */
    public static function isRoot(string $prelude): bool
    {
        foreach (self::selectors($prelude) as $selector) {
            if (! in_array($selector, [':root', 'html', ':host'], true)) {
                return false;
            }
        }

        return $prelude !== '';
    }

    /** @return array<int, string> */
    private static function preludes(array $stack): array
    {
        return array_values(array_filter(array_column(array_slice($stack, 1), 'prelude')));
    }
}
