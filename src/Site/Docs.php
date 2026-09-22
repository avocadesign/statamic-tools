<?php

namespace Avocadesign\StatamicTools\Site;

use Illuminate\Support\Str;
use Statamic\Facades\Markdown;
use Statamic\Facades\YAML;

/**
 * Guidance for a block or set: a markdown file the kit ships next to the block, at
 * resources/site/blocks/<handle>.md or resources/site/sets/<handle>.md.
 */
final class Docs
{
    private const AI_PLACEHOLDER = 'Anything an AI editor should know that a client does not need to read. The content reference page hides this section.';

    private const DESIGN_PLACEHOLDERS = [
        'Say when to use each colour scheme, and any rules for putting schemes next to each other.',
        'Say when the block margins should change from Default.',
        'Say how headings are used across a page.',
    ];

    /** Text a stub starts with. Left as it is, it counts as not written, so it never reaches the catalogue. */
    private const PLACEHOLDERS = [
        'Describe the situations this block is for.',
        'Describe the situations this set is for.',
        'Name the block to reach for instead, and why.',
        'Name the set to reach for instead, and why.',
        'Anything worth knowing about the display settings.',
        self::AI_PLACEHOLDER,
        ...self::DESIGN_PLACEHOLDERS,
    ];

    public static function path(string $kind, string $handle): string
    {
        return base_path(trim(config('statamic-tools.site.docs_path', 'resources/site'), '/')."/{$kind}/{$handle}.md");
    }

    /** @return array{exists: bool, path: string, title: ?string, description: ?string, html: string, sections: array<string, string>} */
    public static function for(string $kind, string $handle): array
    {
        $path = self::path($kind, $handle);
        $relative = str_replace(base_path().'/', '', $path);

        if (! is_file($path)) {
            return ['exists' => false, 'path' => $relative, 'title' => null, 'description' => null, 'html' => '', 'sections' => []];
        }

        [$front, $body] = self::split((string) file_get_contents($path));

        return [
            'exists' => true,
            'path' => $relative,
            'title' => $front['title'] ?? null,
            'description' => $front['description'] ?? null,
            'html' => trim($body) === '' ? '' : self::externalLinksInNewTab(Markdown::parse($body)),
            'sections' => self::sections($body),
        ];
    }

    /**
     * The body split at its second-level headings and keyed by them ("When to use" => when_to_use).
     * Text before the first heading, and any heading the page has no slot for, lands in `other`.
     *
     * @return array<string, string>
     */
    private static function sections(string $body): array
    {
        $known = ['when_to_use', 'when_not_to_use', 'variants', 'notes_for_ai']; // variants is no longer shown; notes for AI only reach the catalogue
        $parts = preg_split('/^##\s+(.+?)\s*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $intro = trim((string) array_shift($parts));
        $other = $intro === '' ? '' : Markdown::parse($intro);
        $out = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $heading = trim($parts[$i]);
            $text = trim($parts[$i + 1]);
            $key = self::sectionKey($heading);
            if (in_array($key, $known, true)) {
                $out[$key] = $text === '' ? '' : Markdown::parse($text);
            } else {
                $other .= Markdown::parse("## {$heading}\n\n{$text}");
            }
        }
        $out['other'] = $other;

        return array_map(self::externalLinksInNewTab(...), $out);
    }

    /**
     * The guidance file as markdown, for the AI catalogue. Placeholder text left from a stub counts as not written.
     *
     * @return array{exists: bool, description: ?string, when_to_use: string, when_not_to_use: string, notes_for_ai: string, other: array<int, array{0: string, 1: string}>}
     */
    public static function markdown(string $kind, string $handle): array
    {
        $path = self::path($kind, $handle);
        if (! is_file($path)) {
            return ['exists' => false, 'description' => null, ...self::markdownSections('')];
        }
        [$front, $body] = self::split((string) file_get_contents($path));

        return ['exists' => true, 'description' => isset($front['description']) ? trim((string) $front['description']) : null, ...self::markdownSections($body)];
    }

    /** @return array{when_to_use: string, when_not_to_use: string, notes_for_ai: string, other: array<int, array{0: string, 1: string}>} */
    public static function markdownSections(string $body): array
    {
        $out = ['when_to_use' => '', 'when_not_to_use' => '', 'notes_for_ai' => '', 'other' => []];
        $parts = preg_split('/^##\s+(.+?)\s*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $intro = trim((string) array_shift($parts));
        if ($intro !== '' && ! self::isPlaceholder($intro)) {
            $out['other'][] = ['About', $intro];
        }
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $heading = trim($parts[$i]);
            $text = trim($parts[$i + 1]);
            $key = self::sectionKey($heading);
            if ($text === '' || self::isPlaceholder($text) || $key === 'variants') {
                continue;
            }
            if (in_array($key, ['when_to_use', 'when_not_to_use', 'notes_for_ai'], true)) {
                $out[$key] = $text;
            } else {
                $out['other'][] = [$heading, $text];
            }
        }

        return $out;
    }

    public static function designPath(): string
    {
        return base_path(trim(config('statamic-tools.site.docs_path', 'resources/site'), '/').'/design.md');
    }

    /**
     * The site-wide design guidance: rules every block shares, such as colour schemes, spacing and headings.
     *
     * @return array{exists: bool, path: string, sections: array<int, array{0: string, 1: string}>, notes_for_ai: string}
     */
    public static function design(): array
    {
        $path = self::designPath();
        $relative = str_replace(base_path().'/', '', $path);
        if (! is_file($path)) {
            return ['exists' => false, 'path' => $relative, 'sections' => [], 'notes_for_ai' => ''];
        }
        [, $body] = self::split((string) file_get_contents($path));

        return ['exists' => true, 'path' => $relative, ...self::designSections($body)];
    }

    /**
     * Design guidance split at its headings, in file order, with Notes for AI kept apart. Placeholder text counts as not written.
     *
     * @return array{sections: array<int, array{0: string, 1: string}>, notes_for_ai: string}
     */
    public static function designSections(string $body): array
    {
        $parts = preg_split('/^##\s+(.+?)\s*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $intro = trim((string) array_shift($parts));
        $sections = $intro !== '' && ! self::isPlaceholder($intro) ? [['Overview', $intro]] : [];
        $notes = '';
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $heading = trim($parts[$i]);
            $text = trim($parts[$i + 1]);
            if ($text === '' || self::isPlaceholder($text)) {
                continue;
            }
            if (self::sectionKey($heading) === 'notes_for_ai') {
                $notes = $text;
            } else {
                $sections[] = [$heading, $text];
            }
        }

        return ['sections' => $sections, 'notes_for_ai' => $notes];
    }

    public static function designStub(): string
    {
        [$schemes, $spacing, $headings] = self::DESIGN_PLACEHOLDERS;
        $ai = self::AI_PLACEHOLDER;

        return <<<MD
        ---
        title: Design
        ---
        ## Colour schemes

        {$schemes}

        ## Spacing

        {$spacing}

        ## Headings

        {$headings}

        ## Notes for AI

        {$ai}

        MD;
    }

    /** @return array{0: array<string, mixed>, 1: string} the front matter and the body */
    private static function split(string $raw): array
    {
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m)) {
            return [YAML::parse($m[1]) ?: [], $m[2]];
        }

        return [[], $raw];
    }

    /** "When to use" => when_to_use; "Notes for AI" and "AI notes" both mean notes_for_ai. */
    private static function sectionKey(string $heading): string
    {
        $key = Str::slug($heading, '_');

        return in_array($key, ['notes_for_ai', 'ai_notes', 'notes_for_the_ai'], true) ? 'notes_for_ai' : $key;
    }

    private static function isPlaceholder(string $text): bool
    {
        return in_array(trim((string) preg_replace('/\s+/', ' ', $text)), self::PLACEHOLDERS, true);
    }

    /** Guidance links leave the reference page, so they open in a new tab like the help links do. */
    public static function externalLinksInNewTab(string $html): string
    {
        return (string) preg_replace('/<a\s+href="(https?:\/\/[^"]*)"(?![^>]*\btarget=)/i', '<a href="$1" target="_blank" rel="noopener"', $html);
    }

    public static function stub(string $kind, string $handle, string $display, string $instructions = ''): string
    {
        $what = $kind === 'sets' ? 'set' : 'block';
        $ai = self::AI_PLACEHOLDER;
        // Dumped rather than interpolated, so a # or a colon in the instructions cannot break the front matter.
        $front = rtrim(YAML::dump(['title' => $display, 'description' => $instructions]));

        return <<<MD
        ---
        {$front}
        ---
        ## When to use

        Describe the situations this {$what} is for.

        ## When not to use

        Name the {$what} to reach for instead, and why.

        ## Notes for AI

        {$ai}

        MD;
    }
}
