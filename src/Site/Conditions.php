<?php

namespace Avocadesign\StatamicTools\Site;

use Illuminate\Support\Str;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * When a field shows in the editor, read from its conditions (if, if_any, unless, unless_any and the older
 * show_when and hide_when names) and put in the editor's own words, plus whether it can show at all where the
 * fields sit. A condition on $root is unknown here, and $parent.type is judged by the set types that can hold
 * the fields.
 */
final class Conditions
{
    private const GROUPS = [
        'if' => ['all', false], 'show_when' => ['all', false],
        'if_any' => ['any', false], 'show_when_any' => ['any', false],
        'unless' => ['all', true], 'hide_when' => ['all', true],
        'unless_any' => ['any', true], 'hide_when_any' => ['any', true],
    ];

    private const OPERATORS = [
        'equals' => 'equals', 'is' => 'equals', '==' => 'equals', '===' => 'equals',
        'not' => 'not', 'isnt' => 'not', '!=' => 'not', '!==' => 'not',
        'contains_any' => 'contains_any', 'includes_any' => 'contains_any',
        'contains' => 'contains', 'includes' => 'contains',
        '>=' => 'gte', '<=' => 'lte', '>' => 'gt', '<' => 'lt',
        'custom' => 'custom',
    ];

    /**
     * @param  Fields  $siblings  the fields at the same level
     * @param  ?Fields  $parent  the fields one level up, which $parent.x refers to, when known
     * @param  array<int, string>  $parentTypes  the set types that can hold these fields, for $parent.type
     * @param  string  $parentNoun  what the level above is called in the words ("the block's Card Type")
     * @return array{visible: bool, words: ?string}
     */
    public static function describe(Field $field, Fields $siblings, ?Fields $parent = null, array $parentTypes = [], string $parentNoun = 'block'): array
    {
        $visible = true;
        $phrases = [];
        foreach (self::GROUPS as $key => [$mode, $negate]) {
            $rules = $field->get($key);
            if (! is_array($rules) || $rules === []) {
                continue;
            }
            $states = [];
            $words = [];
            foreach ($rules as $target => $rule) {
                [$operator, $value] = self::parse($rule);
                [$state, $phrase] = self::rule((string) $target, $operator, $value, $siblings, $parent, $parentTypes, $parentNoun);
                $states[] = $state;
                if ($phrase !== null) {
                    $words[] = $phrase;
                }
            }
            // A rule's state is true when it always holds here, false when it never can, null when the editor decides.
            $canHold = $mode === 'all' ? ! in_array(false, $states, true) : (in_array(true, $states, true) || in_array(null, $states, true));
            $alwaysHolds = $mode === 'all' ? (! in_array(false, $states, true) && ! in_array(null, $states, true)) : in_array(true, $states, true);
            if ((! $negate && ! $canHold) || ($negate && $alwaysHolds)) {
                $visible = false;
            }
            if ($words !== []) {
                $phrases[] = ($negate ? 'hidden when ' : 'only when ').self::list($words, $mode === 'all' ? 'and' : 'or');
            }
        }

        return ['visible' => $visible, 'words' => $phrases === [] ? null : Str::ucfirst(implode(', and ', $phrases))];
    }

    /** "A", "A and B", "A, B and C" */
    public static function list(array $items, string $last): string
    {
        $items = array_values($items);
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        return implode(', ', array_slice($items, 0, -1)).' '.$last.' '.end($items);
    }

    /** @return array{0: ?bool, 1: ?string} whether the rule holds here (null when the editor decides) and its words */
    private static function rule(string $target, string $operator, string $value, Fields $siblings, ?Fields $parent, array $parentTypes, string $parentNoun): array
    {
        if (str_starts_with($target, '$root.') || str_starts_with($target, 'root.')) {
            return [false, null];
        }
        $scope = $siblings;
        $owner = '';
        if (str_starts_with($target, '$parent.')) {
            $target = substr($target, strlen('$parent.'));
            if ($target === 'type') {
                $holds = match ($operator) {
                    'equals' => in_array($value, $parentTypes, true),
                    'not' => $parentTypes === [] || array_diff($parentTypes, [$value]) !== [],
                    default => false,
                };

                return [$holds, null];
            }
            if ($parent === null) {
                return [false, null];
            }
            $scope = $parent;
            $owner = "the {$parentNoun}'s ";
        }
        $field = $scope->all()->get($target);

        return $field ? [null, $owner.self::phrase($field, $operator, $value)] : [null, null];
    }

    private static function phrase(Field $field, string $operator, string $value): string
    {
        $label = $field->display();
        $lower = strtolower($value);
        if (in_array($field->type(), ['revealer', 'toggle'], true) && in_array($lower, ['true', 'false'], true) && in_array($operator, ['equals', 'not'], true)) {
            return $label.' is '.((($lower === 'true') === ($operator === 'equals')) ? 'on' : 'off');
        }
        if (in_array($lower, ['empty', 'null'], true) && in_array($operator, ['equals', 'not'], true)) {
            return $label.' is '.($operator === 'equals' ? 'empty' : 'filled in');
        }
        $options = Blocks::options($field);
        $one = $options[$value] ?? $value;
        $many = array_map(fn (string $v) => $options[$v] ?? $v, array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen')));

        return match ($operator) {
            'equals' => $label.' is '.$one,
            'not' => $label.' is not '.$one,
            'contains' => $label.' includes '.$one,
            'contains_any' => $label.($field->type() === 'checkboxes' ? ' includes ' : ' is ').self::list($many, 'or'),
            'gt' => $label.' is more than '.$value,
            'gte' => $label.' is at least '.$value,
            'lt' => $label.' is less than '.$value,
            'lte' => $label.' is at most '.$value,
            default => $label.' passes a custom check',
        };
    }

    /** @return array{0: string, 1: string} */
    private static function parse(mixed $rule): array
    {
        if (is_bool($rule)) {
            return ['equals', $rule ? 'true' : 'false'];
        }
        $rule = trim((string) $rule);
        if (preg_match('/^(\S+)\s+(.+)$/s', $rule, $m) && isset(self::OPERATORS[strtolower($m[1])])) {
            return [self::OPERATORS[strtolower($m[1])], trim($m[2])];
        }

        return ['equals', $rule];
    }
}
