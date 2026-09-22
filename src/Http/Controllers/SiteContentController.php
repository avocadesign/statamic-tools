<?php

namespace Avocadesign\StatamicTools\Http\Controllers;

use Avocadesign\StatamicTools\Site\BardBuilder;
use Avocadesign\StatamicTools\Site\Blocks;
use Avocadesign\StatamicTools\Site\Docs;
use Avocadesign\StatamicTools\Site\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\Value;
use Statamic\View\View;

class SiteContentController extends SiteController
{
    public function __invoke(): Response
    {
        $this->gate();
        $prefix = config('statamic-tools.site.prefix', 'site');

        return $this->page('content', [
            'title' => 'Content reference',
            'body_class' => 'sk-content',
            'help' => config('statamic-tools.site.help', []),
            'render_url' => url("/{$prefix}/content/render"),
            'blocks' => self::blocks(),
            'sets' => self::sets(),
            'revealer' => self::revealer(),
            // Block previews are spaced by the page template's own section stack, so the gaps match a real page.
            'section_stack' => \Avocadesign\StatamicTools\Site\Spacing::section(resource_path('views/'.config('statamic-tools.site.page_template', 'default').'.antlers.html'))['stack_classes'] ?? 'page-builder',
        ]);
    }

    /**
     * One block or set rendered with the chosen options, for the live preview. An option only
     * counts when its setting would be visible in the editor, so the preview can never show a
     * combination an editor cannot make.
     */
    public function render(Request $request): Response
    {
        $this->gate();
        $kind = $request->query('kind') === 'set' ? 'set' : 'block';
        $handle = (string) $request->query('handle', '');
        $sets = $kind === 'block' ? Blocks::pageBuilder() : Blocks::article();
        abort_unless(isset($sets[$handle]), 404);
        $set = $sets[$handle];
        $chosen = $request->query('s', []);
        $values = self::valuesFor($set['fields'], is_array($chosen) ? $chosen : [], "{$set['display']} {$kind}", $kind === 'set' ? Blocks::textEditorParents() : []);

        if ($kind === 'block') {
            $cfg = config('statamic-tools.site');
            $replicator = Blocks::field($cfg['page_builder_fieldset'], $cfg['page_builder_fieldset']);
            $raw = [[...$values, 'id' => "sk-{$handle}-live", 'type' => $handle, 'enabled' => true]];
            $data = ['kind' => 'block', 'items' => $replicator ? collect((new Value($raw, $replicator->handle(), $replicator->fieldtype()))->value())->values()->all() : $raw];
        } else {
            $data = ['kind' => 'set', 'bard' => BardBuilder::single(Blocks::articleField(), $handle, $values, $set['display'])?->value()];
        }

        $html = (new View)
            ->template('statamic-tools::site.render')
            ->with(['site_prefix' => config('statamic-tools.site.prefix', 'site'), ...$data])
            ->render();

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    /** Sample values with the chosen options applied where the editor would show the setting. */
    private static function valuesFor(Fields $fields, array $chosen, ?string $context = null, array $parentTypes = []): array
    {
        $built = Settings::forFields($fields, config('statamic-tools.site.variant_exclude', []), $context, $parentTypes);
        $values = $built['base'];
        foreach ($built['settings'] as $setting) {
            $key = $chosen[$setting['handle']] ?? null;
            $option = is_scalar($key) ? collect($setting['options'])->firstWhere('option_key', (string) $key) : null;
            if ($option) {
                $values[$setting['handle']] = $option['values'][$setting['handle']];
            }
        }
        // A setting whose conditions are not met is hidden in the editor, so it keeps its default.
        // Conditions can chain, so go round until nothing changes.
        for ($pass = 0, $changed = true; $changed && $pass < 5; $pass++) {
            $changed = false;
            foreach ($built['settings'] as $setting) {
                foreach ($setting['requires'] as $r) {
                    if (! self::holds($r, $values[$r['handle']] ?? null) && $values[$setting['handle']] !== $built['base'][$setting['handle']]) {
                        $values[$setting['handle']] = $built['base'][$setting['handle']];
                        $changed = true;
                    }
                }
            }
        }

        return $values;
    }

    /** Whether a requirement (equals, not, or one of) holds for a value. */
    private static function holds(array $requirement, mixed $value): bool
    {
        $key = self::asKey($value);

        return match ($requirement['op'] ?? 'equals') {
            'not' => $key !== $requirement['keys'][0],
            'in' => in_array($key, $requirement['keys'], true),
            default => $key === $requirement['key'],
        };
    }

    private static function asKey(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** Every page-builder block: guidance, developer details, its settings with every option, and the block as it comes. */
    public static function blocks(): array
    {
        $cfg = config('statamic-tools.site');
        $exclude = $cfg['variant_exclude'] ?? [];
        $replicator = Blocks::field($cfg['page_builder_fieldset'], $cfg['page_builder_fieldset']);

        // Render every block's default look in one pass through the real page-builder field,
        // exactly as an entry's value would be.
        $raw = [];
        $out = [];
        foreach (Blocks::pageBuilder() as $handle => $set) {
            $built = Settings::forFields($set['fields'], $exclude, "{$set['display']} block");
            $raw[] = [...$built['base'], 'id' => "sk-{$handle}", 'type' => $handle, 'enabled' => true];
            $settings = array_map(fn ($s) => self::setting($s, 'In the editor'), $built['settings']);
            $out[] = [
                ...self::summary($set, $built['settings']),
                'docs' => Docs::for('blocks', $handle),
                'uses_sets' => Blocks::holdsTextEditor($set['fields']),
                'revealer' => self::revealerOf($set['fields']),
                'preview' => ['items' => []],
                'settings' => $settings,
                'setting_count' => count($settings),
                'option_count' => array_sum(array_map(fn ($s) => count($s['options']), $settings)),
            ];
        }
        $augmented = $replicator ? collect((new Value($raw, $replicator->handle(), $replicator->fieldtype()))->value())->values()->all() : $raw;
        foreach ($out as $b => $block) {
            $out[$b]['preview']['items'] = isset($augmented[$b]) ? [$augmented[$b]] : [];
        }

        return $out;
    }

    /** Every text-editor set: guidance, developer details, its settings with every option, and the set as it comes inside a Bard field. */
    public static function sets(): array
    {
        $exclude = config('statamic-tools.site.variant_exclude', []);
        $field = Blocks::articleField();
        $out = [];
        foreach (Blocks::article() as $handle => $set) {
            $built = Settings::forFields($set['fields'], $exclude, "{$set['display']} set", Blocks::textEditorParents());
            $settings = array_map(fn ($s) => self::setting($s, 'In the text editor'), $built['settings']);
            $out[] = [
                ...self::summary($set, $built['settings']),
                'docs' => Docs::for('sets', $handle),
                'revealer' => self::revealerOf($set['fields']),
                'preview' => ['bard' => BardBuilder::single($field, $handle, $built['base'], $set['display'])?->value()],
                'settings' => $settings,
                'setting_count' => count($settings),
                'option_count' => array_sum(array_map(fn ($s) => count($s['options']), $settings)),
            ];
        }

        return $out;
    }

    /** The block's own switch, as the editor shows it: label, help text and the words on the switch. Null when it has none. */
    private static function revealerOf(Fields $fields): ?array
    {
        foreach ($fields->all() as $handle => $field) {
            if ($field->type() === 'revealer') {
                return ['handle' => $handle, 'display' => $field->display(), 'instructions' => (string) $field->get('instructions'), 'input_label' => (string) ($field->get('input_label') ?: 'Show settings')];
            }
        }

        return null;
    }

    /** One setting as the page shows it: its options, the condition in words, and how to reach it in the editor. */
    private static function setting(array $setting, string $editor): array
    {
        $how = [];
        if ($setting['revealer']) {
            $how[] = "switch on {$setting['revealer']}";
        }
        foreach ($setting['requires'] as $r) {
            $how[] = match ($r['op'] ?? 'equals') {
                'not' => "set {$r['display']} to anything but ".substr($r['label'], 4),
                'in' => "set {$r['display']} to {$r['label']}",
                default => "set {$r['display']} to {$r['label']}",
            };
        }
        $default = collect($setting['options'])->firstWhere('is_default', true);

        return [
            'handle' => $setting['handle'],
            'display' => $setting['display'],
            'indent' => $setting['requires'] !== [],
            'requires_note' => $setting['requires'] ? 'Only when '.implode(' and ', array_map(fn ($r) => "{$r['display']} is {$r['label']}", $setting['requires'])) : null,
            'requires_pairs' => implode(';', array_map(fn ($r) => match ($r['op'] ?? 'equals') {
                'not' => "{$r['handle']}!={$r['key']}",
                'in' => "{$r['handle']}~=".implode('|', $r['keys']),
                default => "{$r['handle']}={$r['key']}",
            }, $setting['requires'])),
            'default_key' => $default['option_key'] ?? '',
            'how' => implode(', ', $how),
            'editor' => $editor,
            'under' => $setting['revealer'] !== null,
            'options' => array_map(fn ($o) => ['option_key' => $o['option_key'], 'label' => $o['label'], 'is_default' => $o['is_default']], $setting['options']),
        ];
    }

    /**
     * The "Display settings" switch as the blocks configure it, with how many blocks have one,
     * for the note at the top of the page builder tab.
     */
    private static function revealer(): array
    {
        $found = array_values(array_filter(array_map(fn ($set) => self::revealerOf($set['fields']), Blocks::pageBuilder())));

        return $found ? [...$found[0], 'count' => count($found)] : [];
    }

    private static function summary(array $set, array $built): array
    {
        $settings = array_map(fn (array $s) => ['handle' => $s['handle'], 'display' => $s['display'], 'options' => implode(', ', array_column($s['options'], 'label'))], $built);

        return [
            'handle' => $set['handle'],
            'display' => $set['display'],
            'group' => $set['group'],
            'instructions' => $set['instructions'],
            'origin' => $set['partial']['origin'] ?? 'missing',
            'partial_path' => $set['partial']['path'],
            'icon' => $set['icon'] ?? null,
            'imports' => $set['imports'] ?? [],
            'preview_image' => Blocks::previewImage($set['handle'], $set['image'] ?? null),
            'field_rows' => self::fieldRows($set['fields']),
            'setting_rows' => $settings,
            'required_note' => self::requiredNote($set['fields']),
        ];
    }

    /** The fields an editor must fill, as the note above the preview names them; null when none. */
    private static function requiredNote(Fields $fields): ?string
    {
        $names = [];
        foreach ($fields->all() as $field) {
            $rules = $field->get('validate', []);
            $rules = is_array($rules) ? $rules : [$rules];
            $required = $field->get('required') === true || in_array('required', array_map(fn ($r) => is_string($r) ? strtolower(trim($r)) : '', $rules), true);
            if ($required) {
                $names[] = $field->display();
            }
        }

        return $names ? implode(', ', $names) : null;
    }

    /** @return array<int, array{handle: string, display: string, type: string, condition: ?string}> */
    private static function fieldRows(Fields $fields): array
    {
        $rows = [];
        foreach ($fields->all() as $handle => $field) {
            $rows[] = ['handle' => $handle, 'display' => $field->display(), 'type' => $field->type(), 'condition' => self::condition($field)];
        }

        return $rows;
    }

    /** The field's visibility condition as the fieldset states it, for the developer details. */
    private static function condition(Field $field): ?string
    {
        foreach (['if' => '', 'show_when' => '', 'if_any' => 'any of: ', 'unless' => 'unless ', 'hide_when' => 'hidden when '] as $key => $prefix) {
            $rules = $field->get($key);
            if (! is_array($rules) || ! $rules) {
                continue;
            }
            $parts = [];
            foreach ($rules as $other => $rule) {
                $parts[] = $other.' '.(is_bool($rule) ? 'equals '.($rule ? 'true' : 'false') : $rule);
            }

            return $prefix.implode(', ', $parts);
        }

        return null;
    }
}
