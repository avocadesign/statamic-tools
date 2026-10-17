<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Just enough of a JavaScript reader for the audit: its comments, told apart from strings and template literals so
 * that a URL's // is not taken for one. A regular expression holding a quote can still mislead it, which costs at
 * most a comment missed.
 */
final class Js
{
    /**
     * Every comment, with consecutive // lines joined into one.
     *
     * @return array<int, array{text: string, offset: int}>
     */
    public static function comments(string $js): array
    {
        $comments = [];
        $quote = null;
        for ($i = 0, $n = strlen($js); $i < $n; $i++) {
            $c = $js[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } elseif ($c === '/' && ($js[$i + 1] ?? '') === '*') {
                $end = strpos($js, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
                $comments[] = ['text' => substr($js, $i, $end - $i), 'offset' => $i];
                $i = $end - 1;
            } elseif ($c === '/' && ($js[$i + 1] ?? '') === '/') {
                $end = strpos($js, "\n", $i);
                $end = $end === false ? $n : $end;
                $last = array_key_last($comments);
                $gap = $last === null ? '' : substr($js, $comments[$last]['offset'] + strlen($comments[$last]['text']), $i - $comments[$last]['offset'] - strlen($comments[$last]['text']));
                // A // line straight under another joins it: a run of them is one comment.
                if ($last !== null && str_starts_with($comments[$last]['text'], '//') && trim($gap) === '' && substr_count($gap, "\n") === 1) {
                    $comments[$last]['text'] = substr($js, $comments[$last]['offset'], $end - $comments[$last]['offset']);
                } else {
                    $comments[] = ['text' => substr($js, $i, $end - $i), 'offset' => $i];
                }
                $i = $end - 1;
            }
        }

        return $comments;
    }

    /** The script with its comments blanked to spaces, so offsets and line numbers still match the file. */
    public static function withoutComments(string $js): string
    {
        foreach (self::comments($js) as $comment) {
            $js = substr_replace($js, (string) preg_replace('/[^\n]/', ' ', $comment['text']), $comment['offset'], strlen($comment['text']));
        }

        return $js;
    }
}
