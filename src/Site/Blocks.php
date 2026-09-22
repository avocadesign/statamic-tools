<?php

namespace Avocadesign\StatamicTools\Site;

use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Sets;

/**
 * The one place that knows what this site's page builder and text editor contain.
 * Everything under /site, the catalogue and the validator read blocks and sets from here.
 */
final class Blocks
{
    /** @return array<string, array{handle: string, display: string, instructions: string, group: ?string, fields: Fields, partial: array}> */
    public static function pageBuilder(): array
    {
        $cfg = config('statamic-tools.site');
        $field = self::field($cfg['page_builder_fieldset'], $cfg['page_builder_fieldset']);

        return $field ? self::sets($field, $cfg['blocks_view_path']) : [];
    }

    public static function articleField(): ?Field
    {
        $cfg = config('statamic-tools.site');

        return self::field($cfg['article_fieldset'], $cfg['article_field']);
    }

    /** Bard sets of the article field. */
    public static function article(): array
    {
        $field = self::articleField();

        return $field ? self::sets($field, config('statamic-tools.site.sets_view_path')) : [];
    }

    public static function field(string $fieldset, string $handle): ?Field
    {
        if (! $fieldset = Fieldset::find($fieldset)) {
            return null;
        }

        return $fieldset->fields()->all()->get($handle);
    }

    private static function sets(Field $field, string $viewDir): array
    {
        $out = [];
        foreach ($field->get('sets', []) as $groupHandle => $group) {
            $grouped = isset($group['sets']) && is_array($group['sets']);
            $members = $grouped ? $group['sets'] : [$groupHandle => $group];
            foreach ($members as $handle => $set) {
                $out[$handle] = [
                    'handle' => $handle,
                    'display' => $set['display'] ?? $handle,
                    'instructions' => $set['instructions'] ?? '',
                    'group' => $grouped ? ($group['display'] ?? $groupHandle) : null,
                    'fields' => new Fields($set['fields'] ?? []),
                    'partial' => self::partial($viewDir, $handle),
                    'icon' => $set['icon'] ?? null,
                    'image' => $set['image'] ?? null,
                    'imports' => self::imports($set['fields'] ?? []),
                ];
            }
        }

        return $out;
    }

    /** @return array{path: ?string, origin: ?string} */
    private static function partial(string $dir, string $handle): array
    {
        foreach (["_{$handle}", $handle] as $name) {
            if (is_file(resource_path("views/{$dir}/{$name}.antlers.html"))) {
                return ['path' => "{$dir}/{$name}.antlers.html", 'origin' => 'site'];
            }
        }
        if (view()->exists("statamic-tools::{$dir}._{$handle}")) {
            return ['path' => "statamic-tools::{$dir}._{$handle}", 'origin' => 'vendor'];
        }

        return ['path' => null, 'origin' => null];
    }

    /** Fieldsets a set pulls in with `import:`, as paths under the site root. */
    private static function imports(array $fields): array
    {
        $out = [];
        foreach ($fields as $row) {
            if (is_array($row) && isset($row['import'])) {
                $out[] = "resources/fieldsets/{$row['import']}.yaml";
            }
        }

        return $out;
    }

    /**
     * The set picker image, resolved the way the control panel does it (statamic.assets.set_preview_images).
     * When the set has none, a file in that container named after the set is reported as a candidate.
     *
     * @return array{configured: bool, url: ?string, file: ?string, missing: bool, candidate: ?string}
     */
    public static function previewImage(string $handle, ?string $image): array
    {
        $out = ['configured' => false, 'url' => null, 'file' => null, 'missing' => false, 'candidate' => null];
        if (! $cfg = Sets::previewImageConfig()) {
            return $out;
        }
        $out['configured'] = true;
        $prefix = $cfg['container'].'::'.($cfg['folder'] ? $cfg['folder'].'/' : '');
        if ($image) {
            $asset = Asset::find($prefix.$image);
            $out['file'] = $prefix.$image;
            $out['url'] = $asset?->url();
            $out['missing'] = ! $asset;

            return $out;
        }
        $names = [$handle, str_replace('_', '-', $handle)];
        $out['candidate'] = AssetContainer::find($cfg['container'])
            ?->assets($cfg['folder'] ?: '/')
            ->first(fn ($asset) => in_array($asset->filename(), $names, true))
            ?->basename();

        return $out;
    }

    /** Whether fields include the text editor, directly or inside a group, so text editor sets can go in them. */
    public static function holdsTextEditor(Fields $fields): bool
    {
        $article = config('statamic-tools.site.article_field', 'article');
        foreach ($fields->all() as $handle => $field) {
            if ($field->type() === 'bard' && ($handle === $article || ! empty($field->get('sets')))) {
                return true;
            }
            if ($field->type() === 'group' && self::holdsTextEditor(new Fields($field->get('fields', [])))) {
                return true;
            }
        }

        return false;
    }

    /** The page builder blocks that hold the text editor field directly: the set types a text editor set's $parent can be. */
    public static function textEditorParents(): array
    {
        $article = config('statamic-tools.site.article_field', 'article');

        return array_keys(array_filter(self::pageBuilder(), fn (array $set) => $set['fields']->all()->has($article)));
    }

    /** Option keys => labels of a choice field, whatever shape the fieldset used. */
    public static function options(Field $field): array
    {
        $options = $field->get('options', []);
        $out = [];
        if (array_is_list($options)) {
            foreach ($options as $option) {
                if (is_array($option)) {
                    $out[(string) ($option['key'] ?? '')] = (string) ($option['value'] ?? $option['key'] ?? '');
                } else {
                    $out[(string) $option] = (string) $option;
                }
            }
        } else {
            foreach ($options as $key => $label) {
                $out[(string) $key] = (string) $label;
            }
        }
        unset($out['']);

        return $out;
    }

    /**
     * Fields that produce visual variants: choice fields with more than one option, and toggles.
     *
     * @return array<string, array{display: string, options: array<string, string>}>
     */
    public static function variantFields(Fields $fields, array $exclude = ['link_type', 'target_blank', 'display_settings']): array
    {
        $out = [];
        foreach ($fields->all() as $handle => $field) {
            if (in_array($handle, $exclude, true)) {
                continue;
            }
            if (in_array($field->type(), ['select', 'button_group', 'radio'], true)) {
                $options = self::options($field);
                if (count($options) > 1) {
                    $out[$handle] = ['display' => $field->display(), 'options' => $options];
                }
            } elseif ($field->type() === 'toggle') {
                $out[$handle] = ['display' => $field->display(), 'options' => ['false' => 'Off', 'true' => 'On']];
            }
        }

        return $out;
    }
}
