<?php

namespace Avocadesign\StatamicTools\Collections;

use Avocadesign\StatamicTools\Site\Conditions;
use Statamic\Facades\YAML;

/**
 * The collection's record, resources/site/collections/<handle>.md. Its front matter and Decisions section record what
 * was decided. Its Blueprint brief and Notes for AI are the brief for finishing the collection. It stays in the site as
 * the long-term record of the collection.
 */
final class CollectionRecord
{
    /** The checks a finished collection must pass, exactly as an AI agent is allowed to run them. */
    public const CHECK_COMMANDS = ['php please avoca:site:catalogue', 'php please avoca:site:check --strict'];

    private const LABELS = [
        'collection' => "the collection's settings",
        'blueprint' => 'the fields of an entry',
        'show' => 'the page for one entry',
        'block_fieldset' => "the listing block's fields",
        'block_view' => "the listing block's template",
        'guidance' => "the listing block's guidance",
        'record' => 'this file',
    ];

    /** Field handles Statamic refuses. */
    public const RESERVED = ['content_type', 'elseif', 'endif', 'endunless', 'if', 'length', 'reference', 'resource', 'status', 'unless', 'views'];

    /** @return array<string, mixed> */
    public static function frontMatter(CollectionSpec $spec, ?string $date = null): array
    {
        return array_filter([
            'title' => $spec->title,
            'handle' => $spec->handle,
            'route' => $spec->route ?? 'none',
            'dated' => $spec->dated,
            'ordering' => $spec->ordering(),
            'blueprint' => $spec->blueprint,
            'listing_block' => $spec->block ? $spec->handle : 'none',
            'editor_access' => $spec->editorAccess,
            'created' => $date,
        ], fn ($value) => $value !== null);
    }

    /** @param  array<string, string>  $paths  as CollectionFiles::paths() returns them */
    public static function render(CollectionSpec $spec, array $paths, array $site, string $date): string
    {
        $lines = [
            '---',
            rtrim(YAML::dump(self::frontMatter($spec, $date))),
            '---',
            "# {$spec->title}",
            '',
            'Set up with `php please avoca:make:collection` on '.date('j F Y', (int) strtotime($date)).'. This file records what was decided and is the brief for finishing the collection. Update it when the collection changes.',
            '',
            '## Decisions',
            '',
            ...array_map(fn (string $decision) => "- {$decision}", self::decisions($spec)),
            '',
            '## Files',
            '',
            ...array_map(fn (string $key, string $path) => "- `{$path}`: ".self::LABELS[$key].'.', array_keys($paths), $paths),
            '',
        ];
        if ($spec->custom()) {
            $lines = [...$lines, '## Blueprint brief', '', trim($spec->description), ''];
        }

        return rtrim(implode("\n", [...$lines, '## Notes for AI', '', ...self::notes($spec, $paths, $site)]))."\n";
    }

    /** @return array<int, string> */
    private static function decisions(CollectionSpec $spec): array
    {
        return [
            $spec->routed() ? "Each entry has its own page at `{$spec->route}`." : 'Entries have no pages of their own. They appear only in blocks on other pages.',
            $spec->dated
                ? "Entries are dated. An entry dated in the future shows on the site straight away. To hide entries until their date, add `date_behavior` with `future: private` to the collection's settings."
                : 'Entries are not dated.',
            match ($spec->ordering()) {
                'manual' => 'Editors put entries in order by dragging them in the control panel.',
                'date' => 'Entries are listed newest first.',
                default => 'Entries are listed by title, from A to Z.',
            },
            $spec->custom() ? 'Each entry has its own fields, described under Blueprint brief.' : 'Each entry is built with the page builder, from the same blocks as pages.',
            $spec->block
                ? "The {$spec->title} block (`{$spec->handle}`) lists the entries: all of them, or chosen ones. It is in the page builder's Dynamic group, which holds the blocks that show content from elsewhere, such as a form, collection entries or contact details."
                : 'No block lists the entries.',
            $spec->editorAccess
                ? "Editors have access: the editor role can view, create, edit, reorder, publish and delete entries, including other people's."
                : 'Editors have no access. `editor_access: false` above opts the collection out, so its permissions are never added to the editor role. To give editors access later, change it to `true` and run `php please avoca:site:permissions`.',
        ];
    }

    /** @return array<int, string> */
    private static function notes(CollectionSpec $spec, array $paths, array $site): array
    {
        $conventions = "Follow the conventions of the templates in `resources/views/{$site['blocks_view_path']}/`: each section inside `partial:page_builder/block`, plain elements with the site's typography classes, `stack-*` and `gap-*` for spacing, no colour classes on text, and images through the same picture partial those templates use.";
        $guidance = isset($paths['guidance'])
            ? ["- In `{$paths['guidance']}`, replace the placeholder text under When to use, When not to use and Notes for AI. Say what the block shows, when to use it and what to use instead."]
            : [];
        $commands = '`'.self::CHECK_COMMANDS[0].'` runs, and then `'.self::CHECK_COMMANDS[1].'` passes.';
        $uncommitted = 'Nothing is committed: the person who asked reviews the change first.';

        if (! $spec->custom()) {
            if (! $spec->block) {
                return ['Nothing is left to build. Each entry is built with the page builder, like a page, and no block lists the entries. Add entries in the control panel.'];
            }

            return [
                "The blueprint is complete: each entry is built with the page builder, like a page. What is left is the listing block. Change only the listing block's files listed under Files, and this file.",
                '',
                '### Finish the listing block',
                '',
                ...$guidance,
                "- If each item in the list should show more than its title, add it in `{$paths['block_view']}`. {$conventions}",
                '',
                '### Checks',
                '',
                'The collection is finished when all of these hold:',
                '',
                ...self::numbered(['The guidance file has no placeholder text left.', $commands, $uncommitted]),
            ];
        }

        $keep = array_filter(['the title', $spec->routed() ? 'the slug in the sidebar' : null, $spec->dated ? 'the date in the sidebar' : null, $spec->routed() ? 'the SEO tab' : null]);
        $containers = implode(', ', array_map(fn ($handle, $title) => "`{$handle}` ({$title})", array_keys($site['containers']), $site['containers']));
        $build = array_filter([
            "- In `{$paths['blueprint']}`, add a field for each thing the brief names, in the order it names them, after the title in the General section.",
            '- Keep the fields already there: '.Conditions::list($keep, 'and').". When the brief names what an entry is called, such as a person's name, use the title field for it and change its display name instead of adding another field.",
            '- Handles are lowercase snake_case. Display names are plain words the client would use, in sentence case and British English. Add instructions to any field whose purpose is not obvious.',
            '- Choose the simplest field type that fits: `text` for a short line (with `input_type: email` for an email address), `textarea` for a few lines of plain text, `bard` for formatted text, `link` for a web address, `assets` with `max_files: 1` for one image, `toggle` for yes or no, `select` for a fixed set of choices, and `entries` or `terms` to point at other content.',
            $site['common_fieldset'] ? "- Where a field in `resources/fieldsets/common.yaml` fits, reuse it with `field: common.<handle>`, as the site's own fieldsets do." : null,
            $containers !== '' ? "- An asset field names one of this site's asset containers: {$containers}." : null,
            '- Make a field required only when an entry makes no sense without it.',
            '- Do not add a page builder to this blueprint.',
        ]);
        $show = array_filter([
            isset($paths['block_view']) ? "- In `{$paths['block_view']}`, show what a visitor needs in each item of the list, such as an image and a short line of text. Keep the title as the item's heading".($spec->routed() ? ", linked to the entry's page." : '.') : null,
            isset($paths['show']) ? "- In `{$paths['show']}`, lay out all of the entry's fields for its own page." : null,
            "- {$conventions}",
        ]);
        $shape = 'The title is the first field and is required.'.($spec->routed() ? ' The slug is in the sidebar.' : '').($spec->dated ? ' The date is in the sidebar and is required.' : '');

        return [
            "Finish the {$spec->title} collection so it matches the Blueprint brief. Change only the files listed under Files, and leave the collection's settings in `{$paths['collection']}` as they are.",
            '',
            '### Build the blueprint',
            '',
            ...$build,
            '',
            '### Show the fields',
            '',
            ...$show,
            '',
            ...($guidance === [] ? [] : ['### Write the guidance', '', ...$guidance, '']),
            '### Checks',
            '',
            'The collection is finished when all of these hold:',
            '',
            ...self::numbered([
                'Every field the brief names is in the blueprint, and nothing it does not name, apart from the fields this file started with.',
                $shape,
                'Every field has a unique handle and a display name, and no handle is one Statamic reserves: '.Conditions::list(array_map(fn ($word) => "`{$word}`", self::RESERVED), 'or').'.',
                'Every `import:` and every `field: <fieldset>.<handle>` names a fieldset in `resources/fieldsets/`, and every asset field names a container in `content/assets/`.',
                $commands,
                $uncommitted,
            ]),
        ];
    }

    /** @return array<int, string> */
    private static function numbered(array $items): array
    {
        return array_map(fn (int $i, string $text) => ($i + 1).". {$text}", array_keys($items), array_values($items));
    }
}
