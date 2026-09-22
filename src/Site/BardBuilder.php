<?php

namespace Avocadesign\StatamicTools\Site;

use Statamic\Fields\Field;
use Statamic\Fields\Value;

/** Builds a real Bard value (ProseMirror nodes) containing every set of the article field. */
final class BardBuilder
{
    public static function withAllSets(?Field $field, array $sets): ?Value
    {
        if (! $field) {
            return null;
        }

        $doc = [
            self::heading(2, 'Every text editor set, in context'),
            ...Samples::bardParagraphs(),
            self::styled('A lede paragraph, set with the "lede" text style.', 'lede'),
            self::bulletList(['A bulleted list item', 'Another list item with a bit more text']),
        ];

        foreach ($sets as $handle => $set) {
            $doc[] = self::heading(3, $set['display']." (set: {$handle})");
            $doc[] = self::paragraph("Text before the \"{$set['display']}\" set.");
            $doc[] = [
                'type' => 'set',
                'attrs' => ['id' => "sk-{$handle}", 'enabled' => true, 'values' => ['type' => $handle, ...Samples::forFields($set['fields'])]],
            ];
            $doc[] = self::paragraph('Text after the set, to check the spacing around it.');
        }

        return new Value($doc, $field->handle(), $field->fieldtype());
    }


    /** A Bard value holding one set with the given values, with a paragraph either side. */
    public static function single(?Field $field, string $handle, array $values, string $display = ''): ?Value
    {
        if (! $field) {
            return null;
        }

        return new Value([
            self::paragraph('Text before the '.($display ?: $handle).' set.'),
            ['type' => 'set', 'attrs' => ['id' => "sk-{$handle}", 'enabled' => true, 'values' => ['type' => $handle, ...$values]]],
            self::paragraph('Text after the set, to check the spacing around it.'),
        ], $field->handle(), $field->fieldtype());
    }

    private static function heading(int $level, string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function styled(string $text, string $class): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'btsSpan', 'attrs' => ['class' => $class]]], 'text' => $text]]];
    }

    private static function bulletList(array $items): array
    {
        return ['type' => 'bulletList', 'content' => array_map(fn ($t) => ['type' => 'listItem', 'content' => [self::paragraph($t)]], $items)];
    }
}
