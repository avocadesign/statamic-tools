<?php

namespace Avocadesign\StatamicTools\Collections;

use Avocadesign\StatamicTools\Site\Docs;
use Illuminate\Support\Str;

/**
 * The contents of every file a new collection needs, as arrays for YAML and strings for templates. The page builder
 * blueprint is modelled on the kit's pages "page" blueprint. A custom blueprint starts minimal, for an AI agent to
 * finish from the brief in the collection's record. The listing block follows the library's collection presets:
 * all or chosen entries, an order, a number to show and the colour scheme settings.
 *
 * $site describes the site the files are for, as CollectionMaker::site() returns it.
 */
final class CollectionFiles
{
    private const SEO_SECTIONS = [
        ['display' => 'Basic', 'instructions' => 'Basic SEO settings.', 'fields' => [['import' => 'statamic-peak-seo::seo_basic']]],
        ['display' => 'Advanced', 'instructions' => 'Advanced SEO settings.', 'fields' => [['import' => 'statamic-peak-seo::seo_advanced']]],
        ['display' => 'Open Graph', 'instructions' => 'Open Graph meta tags for social sharing.', 'fields' => [['import' => 'statamic-peak-seo::seo_open_graph']]],
        ['display' => 'Sitemap', 'instructions' => 'Sitemap configuration.', 'fields' => [['import' => 'statamic-peak-seo::seo_sitemap']]],
        ['display' => 'JSON-ld Schema', 'instructions' => 'Custom JSON-ld schema objects.', 'fields' => [['import' => 'statamic-peak-seo::seo_jsonld']]],
    ];

    /** The listing block's own fields, which the entries field must not share a handle with. */
    private const BLOCK_FIELDS = ['heading', 'sub_heading', 'source', 'sort', 'limit', 'display_settings', 'colour_scheme', 'block_margins'];

    /** @return array<string, string> what each file is => its path from the site root, in the order they are written */
    public static function paths(CollectionSpec $spec, array $site): array
    {
        $h = $spec->handle;
        $paths = [
            'collection' => "content/collections/{$h}.yaml",
            'blueprint' => "resources/blueprints/collections/{$h}/".Str::singular($h).'.yaml',
        ];
        if ($spec->custom() && $spec->routed()) {
            $paths['show'] = "resources/views/{$h}/show.antlers.html";
        }
        if ($spec->block) {
            $paths['block_fieldset'] = "resources/fieldsets/{$h}.yaml";
            $paths['block_view'] = "resources/views/{$site['blocks_view_path']}/_{$h}.antlers.html";
            $paths['guidance'] = "{$site['docs_path']}/blocks/{$h}.md";
        }
        $paths['record'] = "{$site['docs_path']}/collections/{$h}.md";

        return $paths;
    }

    /** The collection's title for use mid-sentence: "People" => people, "Team members" => team members, "FAQs" stays FAQs. */
    public static function plural(CollectionSpec $spec): string
    {
        return preg_match('/^[A-Z][a-z]/', $spec->title) ? lcfirst($spec->title) : $spec->title;
    }

    /** The block's entries field is named after the collection, as in the library presets, unless that handle is taken. */
    public static function entriesField(CollectionSpec $spec): string
    {
        return in_array($spec->handle, [...self::BLOCK_FIELDS, ...CollectionRecord::RESERVED], true) ? 'entries' : $spec->handle;
    }

    public static function collection(CollectionSpec $spec): array
    {
        $config = ['title' => $spec->title];
        if ($spec->routed()) {
            $config['template'] = $spec->custom() ? "{$spec->handle}/show" : 'default';
            $config['layout'] = 'layout';
        }
        $config['revisions'] = false;
        if ($spec->routed()) {
            $config['route'] = $spec->route;
        } else {
            $config['slugs'] = false;
        }
        if ($spec->dated) {
            $config['date'] = true;
        }
        $config['sort_dir'] = $spec->ordering() === 'date' ? 'desc' : 'asc';
        if ($spec->routed()) {
            $config['preview_targets'] = [['label' => 'Entry', 'url' => '{permalink}', 'refresh' => false]];
        }
        if ($spec->ordered) {
            $config['structure'] = ['root' => false, 'max_depth' => 1];
        }

        return $config;
    }

    public static function blueprint(CollectionSpec $spec, array $site): array
    {
        $sections = [[
            'display' => 'General',
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text', 'required' => true, 'localizable' => true, 'listable' => true, 'display' => 'Title', 'validate' => ['required']]]],
        ]];
        if (! $spec->custom()) {
            if ($spec->routed() && $site['hero_fieldset'] !== null) {
                $sections[] = ['display' => 'Hero', 'collapsible' => true, 'fields' => [['import' => $site['hero_fieldset']]]];
            }
            $sections[] = ['display' => 'Page builder', 'fields' => [['import' => $site['page_builder_fieldset']]]];
        }
        $tabs = ['main' => ['display' => 'Main', 'sections' => $sections]];
        if ($spec->routed()) {
            $tabs['seo'] = ['display' => 'SEO', 'sections' => self::SEO_SECTIONS];
        }

        $meta = [];
        if ($spec->routed()) {
            $meta[] = ['handle' => 'slug', 'field' => ['type' => 'slug', 'localizable' => true, 'validate' => ['required'], 'display' => 'Slug']];
        }
        if ($spec->dated) {
            $meta[] = ['handle' => 'date', 'field' => ['type' => 'date', 'required' => true, 'default' => 'now', 'listable' => true, 'mode' => 'single', 'time_enabled' => false, 'validate' => ['required'], 'display' => 'Date']];
        }
        if ($meta !== []) {
            $tabs['sidebar'] = ['display' => 'Sidebar', 'sections' => [['display' => 'Meta', 'fields' => $meta]]];
        }

        return ['title' => Str::singular($spec->title), 'tabs' => $tabs];
    }

    /** @return array<string, array{0: string, 1: string}> option key => [what the editor sees, sort parameter], the default first */
    public static function sortOptions(CollectionSpec $spec): array
    {
        return [
            ...($spec->ordered ? ['order' => ['Collection order', 'order:asc']] : []),
            ...($spec->dated ? ['newest' => ['Newest first', 'date:desc'], 'oldest' => ['Oldest first', 'date:asc']] : []),
            'title' => ['A to Z', 'title:asc'],
        ];
    }

    public static function blockInstructions(CollectionSpec $spec): string
    {
        return "Entries from the {$spec->title} collection: all of them, or chosen ones.";
    }

    public static function blockFieldset(CollectionSpec $spec, array $site): array
    {
        $plural = self::plural($spec);
        $sort = self::sortOptions($spec);
        $options = fn (array $pairs) => array_map(fn ($key, $value) => ['key' => (string) $key, 'value' => $value], array_keys($pairs), array_values($pairs));

        $fields = [
            ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
            ['handle' => 'sub_heading', 'field' => ['type' => 'text', 'display' => 'Sub heading']],
            ['handle' => 'source', 'field' => [
                'options' => $options(['all' => "All {$plural}", 'chosen' => "Chosen {$plural}"]),
                'default' => 'all',
                'type' => 'button_group',
                'display' => Str::ucfirst($plural).' to show',
                'instructions' => "All {$plural} lists {$plural} from the {$spec->title} collection, in the order and number set here.",
                'width' => 50,
            ]],
        ];
        // An order with a single option is no choice, so an undated collection not ordered by hand always lists A to Z.
        if (count($sort) > 1) {
            $fields[] = ['handle' => 'sort', 'field' => array_filter([
                'options' => $options(array_map(fn (array $option) => $option[0], $sort)),
                'default' => array_key_first($sort),
                'type' => 'button_group',
                'display' => 'Order',
                'instructions' => $spec->ordered ? "Collection order is the order the {$plural} are arranged in, in the {$spec->title} collection." : null,
                'width' => 50,
                'if' => ['source' => 'equals all'],
            ])];
        }
        $fields[] = ['handle' => 'limit', 'field' => [
            'options' => $options(['3' => '3', '6' => '6', '9' => '9', '12' => '12', 'all' => 'All']),
            'default' => $spec->dated ? '3' : 'all',
            'type' => 'button_group',
            'display' => 'Number to show',
            'width' => 50,
            'if' => ['source' => 'equals all'],
        ]];
        $fields[] = ['handle' => self::entriesField($spec), 'field' => [
            'type' => 'entries',
            'collections' => [$spec->handle],
            'mode' => 'default',
            'create' => ! $spec->routed(),
            'display' => Str::ucfirst($plural),
            'instructions' => "Choose the {$plural} for this block, in the order they should appear. With none chosen, ".($spec->dated ? 'the three newest show.' : "all {$plural} show."),
            'if' => ['source' => 'equals chosen'],
        ]];
        if ($site['scheme_fieldset'] !== null || ($site['margins_fieldset'] ?? null) !== null) {
            $fields[] = ['handle' => 'display_settings', 'field' => ['mode' => 'toggle', 'input_label' => 'Show settings', 'type' => 'revealer', 'display' => 'Display settings', 'instructions' => 'Change how this block looks: its layout, colour scheme and spacing.']];
            foreach ([$site['scheme_fieldset'] ?? null, $site['margins_fieldset'] ?? null, $site['class_fieldset'] ?? null] as $fieldset) {
                if ($fieldset !== null) {
                    $fields[] = ['import' => $fieldset];
                }
            }
        }

        return ['title' => "Block: {$spec->title}", 'fields' => $fields];
    }

    public static function blockView(CollectionSpec $spec, array $site, string $blueprintPath): string
    {
        $h = $spec->handle;
        $sort = self::sortOptions($spec);
        $default = $sort[array_key_first($sort)][1];
        $cases = array_map(fn (string $key, array $option) => "        (block:sort == '{$key}') => '{$option[1]}',", array_keys($sort), array_values($sort));
        $sortLine = count($sort) > 1
            ? implode("\n", ["{{ {$h}_sort = switch(", ...array_slice($cases, 1), "        () => '{$default}'", '    ) }}'])
            : "{{ {$h}_sort = '{$default}' }}";

        return strtr(<<<'ANTLERS'
        {{#
            @name [TITLE]
            @desc The [TITLE] page builder block: entries from the [TITLE] collection, all of them or chosen ones.
            @set page.page_builder.[H]
        #}}

        <!-- /[VIEWS]/_[H].antlers.html -->
        {{ partial:page_builder/block class="gap-y-8" }}
            {{# Chosen entries keep the order they were chosen in. All entries, or Chosen with none chosen, lists the collection. #}}
            [SORT]
            {{ [H]_limit = [LIMIT] }}
            {{ [H]_entries = block:source == 'chosen' && (block:[ENTRIES] | count) > 0
                ? block:[ENTRIES]
                : { collection:[H] :sort="[H]_sort" :limit="[H]_limit" }
            }}

            {{ if block:heading || block:sub_heading }}
                <header class="span-content flex flex-col gap-1">
                    {{ if block:heading }}
                        <h2>{{ block:heading | nl2br }}</h2>
                    {{ /if }}
                    {{ if block:sub_heading }}
                        <h3 class="subheading">{{ block:sub_heading | nl2br }}</h3>
                    {{ /if }}
                </header>
            {{ /if }}

            <ul class="span-content grid md:grid-cols-12 gap-fluid-grid-gap">
                {{ [H]_entries }}
                    <li
                        class="
                            [ITEM_CLASSES]
                            {{ switch(
                                (total_results === 1) => 'md:col-span-8 md:col-start-3',
                                (total_results === 2) => 'md:col-span-6',
                                (total_results === 3) => 'md:col-span-4',
                                (total_results === 4) => 'md:col-span-6',
                                (total_results > 4) => 'md:col-span-4',
                                () => void
                            )}}
                        "
                    >
                        <div class="w-full stack-4">
                            [HEADING]
                            {{# Show more of each entry here, such as an image or a short summary, from the fields in [BLUEPRINT]. #}}
                        </div>
                    </li>
                {{ /[H]_entries }}
            </ul>
        {{ /partial:page_builder/block }}
        <!-- End: /[VIEWS]/_[H].antlers.html -->

        ANTLERS, [
            '[TITLE]' => $spec->title,
            '[H]' => $h,
            '[VIEWS]' => $site['blocks_view_path'],
            '[ENTRIES]' => self::entriesField($spec),
            '[BLUEPRINT]' => $blueprintPath,
            '[SORT]' => $sortLine,
            // A button group arrives as a labelled value, so its :value is what the collection tag's limit can count.
            '[LIMIT]' => $spec->dated ? "block:limit:value == 'all' ? 0 : (block:limit:value ?? 3)" : "(block:limit:value ?? 'all') == 'all' ? 0 : block:limit:value",
            '[ITEM_CLASSES]' => $spec->routed() ? 'relative group flex flex-col items-start' : 'flex flex-col items-start',
            // With URLs the title's link covers the whole item (clickable-parent.css), as it does in the kit's cards.
            '[HEADING]' => $spec->routed()
                ? '<h3 class="card-heading mb-0"><a href="{{ url }}" class="clickable-parent">{{ title }}</a></h3>'
                : '<h3 class="card-heading mb-0">{{ title }}</h3>',
        ]);
    }

    public static function showView(CollectionSpec $spec, string $blueprintPath): string
    {
        return strtr(<<<'ANTLERS'
        {{#
            @name [TITLE] show
            @desc One entry from the [TITLE] collection, on its own page: the page header, then the entry's fields, laid out like the default page template.
        #}}

        <!-- /[H]/show.antlers.html -->
        <main
                x-data
                x-init="
                    const lastSection = document.querySelector('main section:last-of-type');
                    if (lastSection?.classList.contains('no-space-b')) {
                        $el.classList.remove('pb-12', 'md:pb-16', 'lg:pb-24');
                    }
                "
                class="pb-12 md:pb-16 lg:pb-24 stack-12 md:stack-16 lg:stack-18"
                id="content">
            {{ partial src="layout/page_header" }}

            {{# Lay out the entry's fields here, from [BLUEPRINT]. Keep each part inside a page builder block wrapper, so it sits on the same grid and spacing as every page. #}}
            {{ partial:page_builder/block }}
                <div class="span-content stack-8">
                </div>
            {{ /partial:page_builder/block }}
        </main>
        <!-- End: /[H]/show.antlers.html -->

        ANTLERS, ['[TITLE]' => $spec->title, '[H]' => $spec->handle, '[BLUEPRINT]' => $blueprintPath]);
    }

    /** The listing block's guidance, as the stub avoca:site:check --stubs writes, so its placeholders never reach the catalogue. */
    public static function guidance(CollectionSpec $spec): string
    {
        return Docs::stub('blocks', $spec->handle, $spec->title, self::blockInstructions($spec));
    }
}
