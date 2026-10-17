<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Just enough of an Antlers template reader for the audit: its class attributes, its comments and its inline scripts,
 * each with its offset in the file.
 */
final class Markup
{
    /** The template with its Antlers and HTML comments blanked to spaces, so offsets still match the file. */
    public static function withoutComments(string $html): string
    {
        return (string) preg_replace_callback('~\{\{#.*?#\}\}|<!--.*?-->~s', fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]), $html);
    }

    /**
     * Every word of every class attribute, Alpine's :class and an Antlers tag's class parameter, as written, with its
     * offset. Antlers inside the attribute comes through as words too, which is harmless: nothing looks for them.
     *
     * @return array<int, array{token: string, offset: int}>
     */
    public static function classTokens(string $html): array
    {
        $html = self::withoutComments($html);
        preg_match_all('/(?<![\w-])(?::|x-bind:)?class\s*=\s*(["\'])(.*?)\1/s', $html, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];
        foreach ($attributes as $attribute) {
            [$value, $start] = $attribute[2];
            preg_match_all('/\S+/', $value, $words, PREG_OFFSET_CAPTURE);
            foreach ($words[0] as [$word, $offset]) {
                $tokens[] = ['token' => $word, 'offset' => $start + $offset];
            }
        }

        return $tokens;
    }

    /** A class word without its quotes, punctuation, important mark and variants: lg:hover:card → card. */
    public static function className(string $token): ?string
    {
        $token = trim($token, "'\",{}()!");
        if ($token === '' || str_contains($token, '[') || str_contains($token, '(') || str_contains($token, '{')) {
            return null;
        }
        $parts = explode(':', $token);
        $class = ltrim(end($parts), '!');

        return preg_match('/^-?[A-Za-z_][\w-]*$/', $class) ? $class : null;
    }

    /**
     * The Antlers and HTML comments, each with its text and offset. A docblock is the {{# @name … #}} at the top.
     *
     * @return array<int, array{text: string, offset: int, kind: string}>
     */
    public static function comments(string $html): array
    {
        preg_match_all('~\{\{#(.*?)#\}\}|<!--(.*?)-->~s', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => [
            'text' => $m[0][0],
            'offset' => $m[0][1],
            'kind' => str_starts_with($m[0][0], '<!--') ? 'html' : (preg_match('/@(name|desc|set)\b/', $m[0][0]) ? 'docblock' : 'antlers'),
        ], $matches);
    }

    /**
     * The scripts written into the template, without a src, as [code, offset].
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function inlineScripts(string $html): array
    {
        preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>~is', self::withoutComments($html), $m, PREG_OFFSET_CAPTURE);

        return $m[1];
    }

    /** True for an email template, which has to carry its styles and images inline. */
    public static function isEmail(string $path): bool
    {
        return (bool) preg_match('~/(emails?|mail)/~', $path);
    }
}
