<?php

namespace Avocadesign\StatamicTools\Site;

use Avocadesign\StatamicTools\Library\SampleImages;
use Illuminate\Support\Str;
use Statamic\Facades\Form;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/** Sample values for any field, so every block and set can be rendered without authored content. */
final class Samples
{
    /** @var array<int, string>|null the images the reference pages render with, looked up once */
    private static ?array $sampleImages = null;

    /** @param ?string $context names the block or set ("Text block"); its top-level heading samples as "<context> preview". */
    public static function forFields(Fields $fields, array $overrides = [], ?string $context = null, ?int $textVariant = null, ?int $item = null): array
    {
        // Text areas side by side at one level (columns) get different text, the first a little longer.
        $areas = [];
        foreach ($fields->all() as $handle => $field) {
            if ($field->type() === 'bard' || ($field->type() === 'group' && self::holdsBard($field))) {
                $areas[] = $handle;
            }
        }
        $positions = count($areas) > 1 ? array_flip($areas) : [];

        $out = [];
        foreach ($fields->all() as $handle => $field) {
            $variant = isset($positions[$handle]) ? $positions[$handle] + 1 : $textVariant;
            $out[$handle] = array_key_exists($handle, $overrides) ? $overrides[$handle] : self::forField($field, $context, $variant, $item);
        }

        return $out;
    }

    public static function forField(Field $field, ?string $context = null, ?int $textVariant = null, ?int $item = null): mixed
    {
        $handle = $field->handle();

        return match ($handle) {
            'link_type' => 'url',
            'link_url' => '#',
            'target_blank' => false,
            'colour_scheme', 'block_margins' => 'default',
            // A sample sentence in a class attribute is worse than no class at all.
            'custom_class' => '',
            'display_settings' => true,
            default => self::byType($field, $handle, $field->type(), $context, $textVariant, $item),
        };
    }

    private static function byType(Field $field, string $handle, string $type, ?string $context = null, ?int $textVariant = null, ?int $item = null): mixed
    {
        return match ($type) {
            'text', 'slug' => self::words($handle, $field->display(), $context, $item),
            'textarea' => self::isSubheading($handle, $field->display()) ? self::SUBHEADING : (self::isHeading($handle, $field->display()) ? self::headingFor($context, $item) : 'Two short sentences of sample text, enough to see how this field wraps and how the spacing around it behaves.'),
            'markdown' => 'Sample **markdown** text with a [link](#).',
            'bard' => self::bardParagraphs($textVariant),
            'assets' => self::assets($field),
            'select', 'button_group', 'radio' => self::defaultOption($field),
            // Every option is ticked, so the preview shows everything the field can add, such as all the contact details.
            'checkboxes' => array_keys(Blocks::options($field)),
            'toggle' => (bool) $field->get('default', false),
            'integer' => 2,
            'float' => 2.5,
            'date' => now()->format('Y-m-d'),
            'time' => '10:00',
            'link' => '#',
            'color' => '#7c3aed',
            'list' => ['First item', 'Second item'],
            'video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'code' => ['code' => '<p>Sample embedded HTML</p>', 'mode' => 'htmlmixed'],
            // The table fieldtype stores a list of rows, each holding its cells.
            'table' => [
                ['cells' => ['Service', 'What it includes', 'Turnaround']],
                ['cells' => ['Website care', 'Updates, backups and security monitoring', 'Ongoing']],
                ['cells' => ['Content edits', 'Text and image changes across the site', 'Two working days']],
                ['cells' => ['New page', 'A page built from the existing blocks', 'One week']],
            ],
            'form' => self::formSample($field),
            'grid' => self::rows($field, $item !== null),
            'replicator' => self::replicatorItems($field, $item !== null),
            'group' => self::forFields(new Fields($field->get('fields', [])), [], null, $textVariant, $item),
            default => null,
        };
    }

    private const SUBHEADING = 'A subheading that expands on the heading above it';

    /** Repeated items (cards, buttons) each get their own text; heading lengths differ so uneven heights show. */
    private const ITEM_HEADINGS = ['A short heading', 'A longer heading that wraps onto a second line', 'A third heading'];

    private const ITEM_LABELS = ['Find out more', 'Get in touch', 'See our work'];

    /** The handle or the title on the field can say what it is: "Subheading", "Sub heading", "Subtitle". */
    private static function isSubheading(string $handle, ?string $display): bool
    {
        $said = Str::lower($handle.' '.(string) $display);

        return Str::contains($said, ['subheading', 'sub heading', 'sub-heading', 'subtitle', 'sub title']);
    }

    /** The field's configured default when it is one of the options, otherwise the first option. */
    private static function defaultOption(Field $field): ?string
    {
        $options = Blocks::options($field);
        $default = $field->get('default');

        return $default !== null && array_key_exists((string) $default, $options) ? (string) $default : array_key_first($options);
    }

    private static function isHeading(string $handle, ?string $display): bool
    {
        return Str::contains(Str::lower($handle.' '.(string) $display), ['heading', 'title']);
    }

    private static function headingFor(?string $context, ?int $item): string
    {
        return $item !== null ? self::ITEM_HEADINGS[$item % count(self::ITEM_HEADINGS)] : self::heading($context);
    }

    private static function heading(?string $context): string
    {
        return $context ? "{$context} preview" : 'A sample heading for this section';
    }

    private static function words(string $handle, ?string $display = null, ?string $context = null, ?int $item = null): string
    {
        if (self::isSubheading($handle, $display)) {
            return self::SUBHEADING;
        }

        $handle = Str::lower($handle.' '.(string) $display);

        return match (true) {
            Str::contains($handle, ['heading', 'title']) => self::headingFor($context, $item),
            Str::contains($handle, 'label') => $item !== null ? self::ITEM_LABELS[$item % count(self::ITEM_LABELS)] : 'Find out more',
            Str::contains($handle, 'caption') => 'A short caption for this item',
            Str::contains($handle, ['author', 'name']) => 'Alex Example',
            Str::contains($handle, 'email') => 'hello@example.com',
            Str::contains($handle, ['phone', 'mobile']) => '03 548 0000',
            Str::contains($handle, ['url', 'link']) => '#',
            Str::contains($handle, 'address') => '12 Example Street, Nelson',
            default => 'Sample text',
        };
    }

    /**
     * How many items a repeating field samples: three at the top level, one inside an item (a card's buttons),
     * and never outside the field's own min and max.
     */
    /** One item for a repeat inside an item, or for a field listed in site.sample_single (buttons). */
    private static function single(Field $field, bool $nested): bool
    {
        return $nested || in_array($field->handle(), (array) config('statamic-tools.site.sample_single', ['buttons']), true);
    }

    private static function repeatCount(Field $field, string $unit, bool $single): int
    {
        $want = $single ? 1 : 3;
        $max = (int) $field->get("max_{$unit}");
        if ($max > 0) {
            $want = min($want, $max);
        }

        return max($want, (int) $field->get("min_{$unit}"), 1);
    }

    private static function rows(Field $field, bool $nested = false): array
    {
        $fields = new Fields($field->get('fields', []));
        $rows = [];
        for ($i = 0, $count = self::repeatCount($field, 'rows', self::single($field, $nested)); $i < $count; $i++) {
            $rows[] = ['id' => "sample-{$i}", ...self::forFields($fields, [], null, null, $i)];
        }

        return $rows;
    }

    private static function replicatorItems(Field $field, bool $nested = false): array
    {
        $types = [];
        foreach ($field->get('sets', []) as $groupHandle => $group) {
            $members = isset($group['sets']) && is_array($group['sets']) ? $group['sets'] : [$groupHandle => $group];
            foreach ($members as $handle => $set) {
                $types[$handle] = $set;
            }
        }
        if (! $types) {
            return [];
        }

        // One of each set type in turn, up to the sample count (or every type, if there are more).
        $handles = array_keys($types);
        $single = self::single($field, $nested);
        $count = max(self::repeatCount($field, 'sets', $single), $single ? 1 : min(count($handles), (int) $field->get('max_sets') ?: PHP_INT_MAX));
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $handle = $handles[$i % count($handles)];
            $items[] = ['id' => "sample-{$handle}-{$i}", 'type' => $handle, 'enabled' => true, ...self::forFields(new Fields($types[$handle]['fields'] ?? []), [], null, null, $i)];
        }

        return $items;
    }

    /** A paragraph with a link and bold text, as ProseMirror nodes. */
    /**
     * Sample body copy with a link and bold text. With no variant it is the standard paragraph; side-by-side
     * text areas get variant 1 (a little longer) and 2 or more (different words, a little shorter).
     */
    public static function bardParagraphs(?int $variant = null): array
    {
        [$before, $after] = match (true) {
            $variant === 1 => ['Body copy with ', '. This column runs a little longer than the one beside it, so uneven heights show in the preview.'],
            $variant !== null && $variant >= 2 => ['Different words in this column, with ', ', kept a little shorter.'],
            default => ['Body copy with ', '. A second sentence gives the paragraph enough length to wrap on most widths.'],
        };

        return [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => $before],
                ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#', 'rel' => null, 'target' => null, 'title' => null]]], 'text' => 'a link'],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold text'],
                ['type' => 'text', 'text' => $after],
            ],
        ]];
    }

    /** A form from the site: the contact form when there is one, otherwise the first. */
    private static function formSample(Field $field): string|array|null
    {
        $handles = Form::all()->map->handle()->values()->all();
        $handle = in_array('contact', $handles, true) ? 'contact' : ($handles[0] ?? null);
        if ($handle === null) {
            return null;
        }

        return (int) $field->get('max_items') === 1 ? $handle : [$handle];
    }

    private static function holdsBard(Field $field): bool
    {
        return collect((new Fields($field->get('fields', [])))->all())->contains(fn (Field $inner) => $inner->type() === 'bard');
    }

    /** One image for a single-image field, several for a gallery, taken from what the images container holds. */
    private static function assets(Field $field): string|array
    {
        $max = (int) $field->get('max_files');
        if ($max === 1) {
            return self::placeholder() ?? '';
        }
        $count = max((int) $field->get('min_files'), $max > 1 ? min($max, 6) : 6);
        $images = array_filter(array_map(fn (int $i) => self::placeholder($i + 1), range(0, $count - 1)));

        return array_values($images);
    }

    /**
     * An image for the reference pages to render, from the images container: the ones named placeholder first,
     * biggest first, then any other image big enough, as installing a library item chooses them. Nothing is
     * generated and nothing is written to the container, so a site's asset library keeps only its own files.
     * Ask for a number to walk the list, so a gallery shows different images rather than the same one six times.
     *
     * Returns the container-relative path, such as temp/a-peak.jpg, or null when the container holds nothing
     * worth showing.
     */
    public static function placeholder(?int $number = null): ?string
    {
        self::$sampleImages ??= app(SampleImages::class)->choices();

        if (self::$sampleImages === []) {
            return null;
        }

        return self::$sampleImages[(max(1, (int) $number) - 1) % count(self::$sampleImages)];
    }

    /** The container is read once a page, not once a block. Long running workers and tests start again from here. */
    public static function forgetSampleImages(): void
    {
        self::$sampleImages = null;
    }
}
