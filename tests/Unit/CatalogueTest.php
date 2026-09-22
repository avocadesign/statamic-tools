<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Catalogue;
use Avocadesign\StatamicTools\Tests\TestCase;
use Statamic\Fields\Fields;

class CatalogueTest extends TestCase
{
    public function test_a_block_lists_its_guidance_and_only_the_fields_an_editor_can_see(): void
    {
        $fields = new Fields([
            ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
            ['handle' => 'body', 'field' => ['type' => 'textarea', 'display' => 'Body', 'validate' => ['required']]],
            ['handle' => 'display_settings', 'field' => ['type' => 'revealer', 'display' => 'Display settings']],
            ['handle' => 'align', 'field' => ['type' => 'select', 'display' => 'Display style', 'default' => 'left', 'options' => ['left' => 'Left', 'centre' => 'Centre'], 'if' => ['display_settings' => 'equals true']]],
            ['handle' => 'button_alignment', 'field' => ['type' => 'button_group', 'display' => 'Button Alignment', 'options' => ['start' => 'Left'], 'if' => ['$parent.type' => 'equals article']]],
        ]);
        $docs = fn (string $kind, string $handle) => [
            'exists' => true, 'description' => 'A block of text.', 'when_to_use' => 'For body copy.', 'when_not_to_use' => '',
            'notes_for_ai' => 'Keep headings under eight words.', 'other' => [],
        ];

        $markdown = Catalogue::render(['text' => ['handle' => 'text', 'display' => 'Text', 'instructions' => 'Text block', 'fields' => $fields]], [], [], [], $docs);

        $this->assertStringContainsString("### Text (`text`)\n\nA block of text.\n", $markdown);
        $this->assertStringContainsString("#### When to use\n\nFor body copy.\n", $markdown);
        $this->assertStringNotContainsString('When not to use', $markdown, 'an unwritten section is left out');
        $this->assertStringContainsString("#### Notes for AI\n\nKeep headings under eight words.\n", $markdown);
        $this->assertStringContainsString('- `body`: Body. Plain text over several lines (`textarea`), required.', $markdown);
        $this->assertStringContainsString('- `align`: Display style. Choice (`select`). Options: `left` Left, `centre` Centre. Default `left`. Only when Display settings is on.', $markdown);
        $this->assertStringNotContainsString('button_alignment', $markdown, 'a field the editor never shows is left out');
    }

    public function test_design_guidance_sits_before_the_blocks_only_when_written(): void
    {
        $docs = fn (string $kind, string $handle) => ['exists' => false, 'description' => null, 'when_to_use' => '', 'when_not_to_use' => '', 'notes_for_ai' => '', 'other' => []];
        $design = ['exists' => true, 'path' => 'resources/site/design.md', 'sections' => [['Colour schemes', 'Use Primary once per page.']], 'notes_for_ai' => 'Never put two Dark blocks together.'];

        $with = Catalogue::render([], [], [], [], $docs, $design);
        $this->assertStringContainsString("## Design\n\nThese rules apply to every page, block and set.\n\n### Colour schemes\n\nUse Primary once per page.\n", $with);
        $this->assertStringContainsString("### Notes for AI\n\nNever put two Dark blocks together.\n", $with);
        $this->assertLessThan(strpos($with, '## Page builder blocks'), strpos($with, '## Design'));

        $this->assertStringNotContainsString('## Design', Catalogue::render([], [], [], [], $docs, ['exists' => true, 'path' => 'x', 'sections' => [], 'notes_for_ai' => '']));
    }

    public function test_each_block_and_set_names_the_group_it_is_listed_under(): void
    {
        $docs = fn (string $kind, string $handle) => ['exists' => false, 'description' => null, 'when_to_use' => '', 'when_not_to_use' => '', 'notes_for_ai' => '', 'other' => []];
        $item = fn (string $handle, string $display, ?string $group) => ['handle' => $handle, 'display' => $display, 'instructions' => '', 'group' => $group, 'fields' => new Fields([])];

        $markdown = Catalogue::render(['form' => $item('form', 'Form', 'Dynamic')], ['table' => $item('table', 'Table', 'Content')], [], [], $docs);

        $this->assertStringContainsString("### Form (`form`)\n\nListed under Dynamic in the page builder.\n", $markdown);
        $this->assertStringContainsString("### Table (`table`)\n\nListed under Content in the text editor.\n", $markdown);
    }
}
