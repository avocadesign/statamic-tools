<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Comments that run long: a template comment over two lines or about 160 characters, an @desc over one line, a
 * CSS or JS comment over four lines, and a file whose comments outweigh its code. A docblock, the kit's
 * <!-- /path --> markers and commented-out code are left out, and so are the site's copies of vendor views, whose
 * comments say what was changed from upstream.
 */
final class CommentChecks
{
    public const TEMPLATE_LINES = 2;

    public const TEMPLATE_CHARACTERS = 160;

    public const BLOCK_LINES = 4;

    /** The share of a file's characters, outside whitespace, that its comments may take. */
    public const SHARE = 0.4;

    /** Files smaller than this, outside whitespace, are not weighed: one good comment outweighs a short partial. */
    public const SMALLEST = 600;

    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        foreach ($this->files->templates() as $path => $html) {
            if (str_starts_with($path, 'resources/views/vendor/')) {
                continue;
            }
            $comments = array_values(array_filter(Markup::comments($html), fn (array $c) => ! self::isMarker($c)));
            foreach ($comments as $comment) {
                $this->template($path, $html, $comment);
            }
            $this->weigh($path, $html, array_column(array_filter($comments, fn (array $c) => $c['kind'] !== 'docblock' && ! self::isCode($c['text'])), 'text'));
        }

        foreach ([...$this->files->stylesheets(), ...$this->files->scripts()] as $path => $code) {
            $comments = array_values(array_filter(
                str_ends_with($path, '.css') ? Css::comments($code) : Js::comments($code),
                fn (array $c) => ! self::isCode($c['text']),
            ));
            foreach ($comments as $comment) {
                $lines = self::textLines($comment['text']);
                if ($lines > self::BLOCK_LINES) {
                    $this->out->add('comment-block', $path, Files::line($code, $comment['offset']), "a comment of {$lines} lines: ".self::opening($comment['text']));
                }
            }
            $this->weigh($path, $code, array_column($comments, 'text'));
        }
    }

    private function template(string $path, string $html, array $comment): void
    {
        $line = Files::line($html, $comment['offset']);
        $text = $comment['text'];

        if ($comment['kind'] === 'docblock') {
            if (preg_match('/@desc\b(.*?)(?=\n\s*@|#\}\})/s', $text, $m) && substr_count(trim($m[1]), "\n") > 0) {
                $this->out->add('desc-long', $path, $line + substr_count(substr($text, 0, (int) strpos($text, '@desc')), "\n"), '@desc runs to '.(substr_count(trim($m[1]), "\n") + 1).' lines');
            }

            return;
        }
        if (self::isCode($text)) {
            return;
        }

        $lines = self::textLines($text);
        $length = strlen(self::words($text));
        if ($lines > self::TEMPLATE_LINES || $length > self::TEMPLATE_CHARACTERS) {
            $this->out->add('comment-long', $path, $line, ($lines > self::TEMPLATE_LINES ? "a comment of {$lines} lines" : "a comment of {$length} characters").': '.self::opening($text));
        }
    }

    /** @param array<int, string> $comments */
    private function weigh(string $path, string $code, array $comments): void
    {
        $total = strlen((string) preg_replace('/\s+/', '', $code));
        if ($total < self::SMALLEST) {
            return;
        }
        $share = strlen((string) preg_replace('/\s+/', '', implode('', $comments))) / $total;
        if ($share > self::SHARE) {
            $this->out->add('comment-heavy', $path, null, sprintf('%d%% of the file is comments', round(100 * $share)));
        }
    }

    /** The kit's <!-- /page_builder/_x.antlers.html --> and <!-- End: … --> lines, which mark where a partial starts and ends. */
    private static function isMarker(array $comment): bool
    {
        return $comment['kind'] === 'html' && (bool) preg_match('~^<!--\s*(End:\s*)?/?[\w/.-]+\.html\s*-->$~', $comment['text']);
    }

    /** Commented-out code, such as upstream's example tokens and font faces: dead code is not a long comment. */
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

    /** How many of the comment's lines hold words, not just its delimiters. */
    private static function textLines(string $text): int
    {
        return count(array_filter(explode("\n", self::strip($text)), fn (string $line) => trim($line, " \t*/") !== ''));
    }

    /** The comment without its delimiters or the * and // that open its lines. */
    private static function strip(string $text): string
    {
        return (string) preg_replace(['~^\s*(\{\{#|<!--|/\*+|//)~', '~(#\}\}|-->|\*+/)\s*$~', '~\n[ \t]*(\*(?!/)|//)~'], ['', '', "\n"], $text);
    }

    private static function words(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::strip($text)));
    }

    /** The comment's first words, to say which one is meant. */
    private static function opening(string $text): string
    {
        $words = self::words($text);

        return strlen($words) > 60 ? rtrim(substr($words, 0, 57)).'…' : $words;
    }
}
