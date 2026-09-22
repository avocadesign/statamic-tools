<?php

namespace Avocadesign\StatamicTools\Site;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * What an editor can change on a block or set, read from its fields: one setting per choice
 * field or toggle, in editor order, with every option (default included) as the values that
 * render it. A field that only appears under a condition (a revealer switched on, another
 * field set to a value) has that condition applied, so every render is one an editor can
 * produce, and the condition is reported so the page can say it in words.
 */
final class Settings
{
    /**
     * @return array{base: array<string, mixed>, settings: array<int, array{
     *     handle: string, display: string, revealer: ?string,
     *     requires: array<int, array{handle: string, display: string, label: string, key: string}>,
     *     options: array<int, array{option_key: string, label: string, is_default: bool, recipe: string, values: array<string, mixed>}>
     * }>}
     */
    /** @param array<int, string> $parentTypes the set types that can hold these fields; a condition on $parent.type is met only by one of them. */
    public static function forFields(Fields $fields, array $exclude = [], ?string $context = null, array $parentTypes = []): array
    {
        $base = Samples::forFields($fields, [], $context);
        $exclude = [...['link_type', 'target_blank', 'display_settings'], ...$exclude];
        $all = $fields->all();
        $settings = [];
        foreach (Blocks::variantFields($fields, $exclude) as $handle => $variant) {
            $field = $all->get($handle);
            [$needs, $chain, $possible] = $field ? self::requirements($field, $fields, [], $parentTypes, $base) : [[], [], true];
            if (! $possible) {
                continue; // hidden in the editor wherever this block or set can sit
            }
            $revealer = null;
            $requires = [];
            foreach ($chain as $link) {
                if ($link['revealer']) {
                    $revealer = $link['display'];
                } else {
                    $requires[] = ['handle' => $link['handle'], 'display' => $link['display'], 'label' => $link['label'], 'key' => $link['key'], 'op' => $link['op'], 'keys' => $link['keys']];
                }
            }
            $options = [];
            foreach ($variant['options'] as $key => $label) {
                $value = match ($key) { 'true' => true, 'false' => false, default => $key };
                $isDefault = (string) ($base[$handle] ?? '') === (string) $key || ($base[$handle] ?? null) === $value;
                $options[] = [
                    'option_key' => (string) $key,
                    'label' => $label,
                    'is_default' => $isDefault,
                    'recipe' => "{$variant['display']}: {$label}",
                    'values' => [...$base, ...$needs, $handle => $value],
                ];
            }
            $settings[] = ['handle' => $handle, 'display' => $variant['display'], 'revealer' => $revealer, 'requires' => $requires, 'options' => $options];
        }

        return ['base' => $base, 'settings' => $settings];
    }

    /**
     * What the other fields must hold for this field to show in the editor, following each
     * condition's own conditions first: the values to set, and the chain in editor terms.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{handle: string, display: string, label: string, key: string, revealer: bool}>, 2: bool}
     */
    public static function requirements(Field $field, Fields $fields, array $seen = [], array $parentTypes = [], array $base = []): array
    {
        $values = [];
        $chain = [];
        $possible = true;
        $conditions = $field->get('if') ?? $field->get('show_when') ?? [];
        if (! is_array($conditions)) {
            return [$values, $chain, $possible];
        }

        foreach ($conditions as $other => $rule) {
            // The entry's own values are unknown here, and a parent can only be judged by its set type.
            if (str_starts_with($other, '$root.') || str_starts_with($other, 'root.')) {
                $possible = false;
                continue;
            }
            if (str_starts_with($other, '$parent.')) {
                $want = self::equalsValue($rule);
                if (substr($other, 8) !== 'type' || $want === null || ! in_array($want, $parentTypes, true)) {
                    $possible = false;
                }
                continue;
            }
            if (in_array($other, $seen, true) || ! $target = $fields->all()->get($other)) {
                continue;
            }
            [$op, $keys] = self::operator($rule);
            if ($op === null) {
                continue; // a condition the preview cannot model is left to the editor
            }
            [$deeper, $deeperChain, $deeperPossible] = self::requirements($target, $fields, [...$seen, $other], $parentTypes, $base);
            $possible = $possible && $deeperPossible;
            $values = [...$values, ...$deeper];
            $chain = [...$chain, ...$deeperChain];

            if (in_array($target->type(), ['revealer', 'toggle'], true)) {
                if ($op === 'in') {
                    continue;
                }
                $on = in_array(strtolower($keys[0]), ['true', '1', 'yes', 'on'], true) === ($op === 'equals');
                $values[$other] = $on;
                $chain[] = ['handle' => $other, 'display' => $target->display(), 'label' => $on ? 'on' : 'off', 'key' => $on ? 'true' : 'false', 'op' => 'equals', 'keys' => [$on ? 'true' : 'false'], 'revealer' => $target->type() === 'revealer'];
            } else {
                $options = Blocks::options($target);
                $current = (string) ($base[$other] ?? '');
                $values[$other] = match ($op) {
                    'equals' => $keys[0],
                    'not' => $current !== $keys[0] ? $current : (string) (collect(array_keys($options))->first(fn ($k) => (string) $k !== $keys[0]) ?? $current),
                    default => in_array($current, $keys, true) ? $current : $keys[0],
                };
                $label = match ($op) {
                    'equals' => $options[$keys[0]] ?? $keys[0],
                    'not' => 'not '.($options[$keys[0]] ?? $keys[0]),
                    default => Conditions::list(array_map(fn ($k) => $options[$k] ?? $k, $keys), 'or'),
                };
                $chain[] = ['handle' => $other, 'display' => $target->display(), 'label' => $label, 'key' => $keys[0], 'op' => $op, 'keys' => $keys, 'revealer' => false];
            }
        }

        $unique = [];
        foreach ($chain as $link) {
            $unique[$link['handle'].'='.$link['label']] = $link;
        }

        return [$values, array_values($unique), $possible];
    }

    /**
     * The conditions the preview can model: equals, not and one of (contains_any), with their option keys.
     *
     * @return array{0: ?string, 1: array<int, string>}
     */
    private static function operator(mixed $rule): array
    {
        if (($want = self::equalsValue($rule)) !== null) {
            return ['equals', [$want]];
        }
        if (is_string($rule) && preg_match('/^(?:not|isnt|!=|!==)\s+(.+)$/i', trim($rule), $m)) {
            return ['not', [trim($m[1])]];
        }
        if (is_string($rule) && preg_match('/^(?:contains_any|includes_any)\s+(.+)$/i', trim($rule), $m)) {
            return ['in', array_values(array_filter(array_map('trim', explode(',', $m[1])), 'strlen'))];
        }

        return [null, []];
    }

    /** The value an "equals" condition wants, or null for any other operator. */
    private static function equalsValue(mixed $rule): ?string
    {
        if (is_bool($rule)) {
            return $rule ? 'true' : 'false';
        }
        if (is_int($rule) || is_float($rule)) {
            return (string) $rule;
        }
        if (! is_string($rule)) {
            return null;
        }
        $rule = trim($rule);
        if (preg_match('/^(?:equals|is|==|=)\s+(.+)$/i', $rule, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(?:not|isnt|!=|contains|contains_any|includes|includes_any|empty|is_empty|not_empty|null|is_null|>|<|>=|<=|custom)\b/i', $rule)) {
            return null;
        }

        return $rule; // a bare value means equals
    }
}
