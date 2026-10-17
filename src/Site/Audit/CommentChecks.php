<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Comments that don't help: one that restates what the code beside it already says, such as its values, colours and
 * classes, one that tells the history of the code, and an @desc over one line. A comment's length is no measure: a
 * long one that gives key context is fine, and a short one that repeats the code is not.
 */
final class CommentChecks
{
    /** How many of the code's own values a comment has to repeat to be restating it. */
    public const RESTATED = 2;

    /** How many lines of code after a comment it is read against. */
    public const NEARBY_LINES = 8;

    private const HISTORY = '/\b(?:used to be|previously|formerly|originally|was (?:moved|removed|changed|replaced|renamed)|(?:has|have) been (?:moved|removed|replaced|renamed)|(?:was|were) measured|measured (?:in|on|from|against) (?:the )?(?:paper|figma|reference|design|source|original|old|astro)|in the (?:old|original|previous) (?:\w+ )?(?:site|build|design|version|source)|before (?:the|this) (?:change|clean-?up|conversion|refactor|port)|ported from|commit [0-9a-f]{7,40})\b|\b20\d\d-\d\d-\d\d\b|\b\d{1,2} (?:January|February|March|April|May|June|July|August|September|October|November|December)\b/i';

    /** Colour words, which restate the code when the code beside them sets a colour. */
    private const COLOUR_WORDS = '/\b(red|orange|yellow|green|blue|purple|violet|pink|magenta|lilac|lavender|plum|white|black|gr[ae]y|brown|teal|cyan|navy|cream|beige|gold)\b/i';

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        foreach ($this->files->templates() as $path => $html) {
            $code = Markup::withoutComments($html);
            foreach (Markup::comments($html) as $comment) {
                if ($comment['kind'] === 'docblock') {
                    $this->desc($path, $html, $comment);
                } elseif (! self::isMarker($comment) && ! self::isCode($comment['text'])) {
                    $nearby = self::nearby($code, $comment);
                    $classes = [];
                    foreach (Markup::classTokens($nearby) as $token) {
                        $classes[] = trim($token['token'], "'\",{}");
                        $classes[] = (string) Markup::className($token['token']);
                    }
                    $this->judge($path, $html, $comment, $nearby, array_values(array_filter($classes)));
                }
            }
        }

        foreach ([...$this->files->stylesheets(), ...$this->files->scripts()] as $path => $source) {
            $css = str_ends_with($path, '.css');
            $code = $css ? Css::withoutComments($source) : Js::withoutComments($source);
            foreach ($css ? Css::comments($source) : Js::comments($source) as $comment) {
                if (self::isCode($comment['text'])) {
                    continue;
                }
                $nearby = self::nearby($code, $comment);
                $classes = [];
                if ($css) {
                    preg_match_all('/([^{};]+)\{/', $nearby, $preludes);
                    foreach ($preludes[1] as $prelude) {
                        $classes = [...$classes, ...Css::classes($prelude)];
                    }
                }
                $this->judge($path, $source, $comment, $nearby, $classes);
            }
        }
    }

    /** @param array<int, string> $classes the classes the nearby code uses */
    private function judge(string $path, string $source, array $comment, string $nearby, array $classes): void
    {
        $line = Files::line($source, $comment['offset']);
        $text = self::strip($comment['text']);

        if (preg_match(self::HISTORY, $text, $m)) {
            $this->out->add('comment-history', $path, $line, "\"{$m[0]}\": ".self::opening($text));

            return;
        }

        $restated = self::restated($text, $nearby, $classes);
        if (count($restated) >= self::RESTATED) {
            $this->out->add('comment-restates', $path, $line, 'repeats '.Findings::list($restated, 3).' from the code beside it: '.self::opening($text));
        }
    }

    private function desc(string $path, string $html, array $comment): void
    {
        $text = $comment['text'];
        if (preg_match('/@desc\b(.*?)(?=\n\s*@|#\}\})/s', $text, $m) && substr_count(trim($m[1]), "\n") > 0) {
            $line = Files::line($html, $comment['offset']) + substr_count(substr($text, 0, (int) strpos($text, '@desc')), "\n");
            $this->out->add('desc-long', $path, $line, '@desc runs to '.(substr_count(trim($m[1]), "\n") + 1).' lines');
        }
    }

    /**
     * The values the comment repeats from the code beside it: lengths, durations and shares, colours written out,
     * utilities with a value in them, and a colour named in words beside code that sets one. A length in px or rem
     * also counts when a class beside it is that step, as 32px is size-8. Naming a token, a class or an attribute to
     * say what reads it is context, so names alone don't count.
     *
     * @param  array<int, string>  $classes
     * @return array<int, string>
     */
    public static function restated(string $comment, string $nearby, array $classes): array
    {
        $found = [];
        $has = fn (string $fact) => (bool) preg_match('/(?<![\w.#-])'.preg_quote($fact, '/').'(?![\w%-])/i', $nearby);

        preg_match_all('/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?|oklch|oklab)\([^)]*\)/i', $comment, $m);
        foreach ($m[0] as $fact) {
            if ($has($fact)) {
                $found[] = $fact;
            }
        }

        preg_match_all('/(?<![\w.#-])(-?\d*\.?\d+)(px|rem|em|ms|s|vw|vh|dvh|svh|%|deg|fr|ch)(?![\w%])/', $comment, $m, PREG_SET_ORDER);
        foreach ($m as [$fact, $number, $unit]) {
            if ($has($fact) || $has((string) preg_replace('/^(-?)0\./', '$1.', $fact))) {
                $found[] = $fact;
            } elseif (in_array($unit, ['px', 'rem'], true)) {
                $step = rtrim(rtrim(sprintf('%.2f', (float) $number / ($unit === 'px' ? 4 : 0.25)), '0'), '.');
                if (preg_grep('/(^|:)-?[a-z][\w-]*-'.preg_quote($step, '/').'$/', $classes)) {
                    $found[] = $fact;
                }
            }
        }

        foreach (preg_split('/[\s,;()"\'`]+/', $comment) as $word) {
            $word = ltrim(rtrim($word, '.:'), '.');
            if (preg_match('/[:\[\d]/', $word) && preg_match('/^[a-z\[]/i', $word) && in_array($word, $classes, true)) {
                $found[] = $word;
            }
        }

        $setsColour = preg_match('/--color-|color-mix\(|#[0-9a-f]{3,8}\b|oklch\(|rgba?\(|(?<![\w-])(?:bg|text|fill|stroke|border|ring|outline|decoration|from|via|to)-(?!\[?\d)[a-z]/i', $nearby);
        if ($setsColour && preg_match(self::COLOUR_WORDS, $comment, $colour)) {
            $found[] = strtolower($colour[0]);
        }

        return array_values(array_unique($found));
    }

    /** The comment's own line and the lines of code after it. */
    private static function nearby(string $code, array $comment): string
    {
        $start = strrpos(substr($code, 0, $comment['offset']), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = $comment['offset'] + strlen($comment['text']);
        for ($i = 0; $i <= self::NEARBY_LINES && $end !== false && $end < strlen($code); $i++) {
            $end = strpos($code, "\n", $end + 1);
        }

        return substr($code, $start, ($end === false ? strlen($code) : $end) - $start);
    }

    /** The kit's <!-- /page_builder/_x.antlers.html --> and <!-- End: … --> lines, which mark where a partial starts and ends. */
    private static function isMarker(array $comment): bool
    {
        return $comment['kind'] === 'html' && (bool) preg_match('~^<!--\s*(End:\s*)?/?[\w/.-]+\.html\s*-->$~', $comment['text']);
    }

    /** Commented-out code, such as upstream's example tokens and font faces, which repeats no code because it is code. */
    private static function isCode(string $text): bool
    {
        $body = self::strip($text);
        if (preg_match('/\{\{|(^|\n)\s*<[a-z][\w-]*[\s>\/]|[\w\])-]\s*\{\s*-{0,2}[\w-]+\s*:/i', $body)) {
            return true;
        }
        $lines = array_filter(array_map('trim', explode("\n", $body)), fn (string $l) => $l !== '');
        $code = array_filter($lines, fn (string $l) => preg_match('/^(-{0,2}[\w-]+\s*:\s*[^;]+;|@[\w-]+\b[^;]*;|(const|let|var|import|export)\s.*)$/', $l));

        return $lines !== [] && count($code) * 2 >= count($lines);
    }

    /** The comment without its delimiters or the * and // that open its lines. */
    private static function strip(string $text): string
    {
        return trim((string) preg_replace(['~^\s*(\{\{#|<!--|/\*+|//)~', '~(#\}\}|-->|\*+/)\s*$~', '~\n[ \t]*(\*(?!/)|//)~'], ['', '', "\n"], $text));
    }

    /** The comment's first words, to say which one is meant. */
    private static function opening(string $text): string
    {
        $words = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($words) > 60 ? rtrim(substr($words, 0, 57)).'…' : $words;
    }
}
