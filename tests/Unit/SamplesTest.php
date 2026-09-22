<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Samples;
use Avocadesign\StatamicTools\Tests\TestCase;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

class SamplesTest extends TestCase
{
    public function test_a_table_sample_is_a_list_of_rows_each_with_cells(): void
    {
        $sample = Samples::forField(new Field('table', ['type' => 'table']));

        $this->assertIsArray($sample);
        $this->assertTrue(array_is_list($sample), 'the table fieldtype stores rows as a list, not under a key');
        $this->assertGreaterThanOrEqual(3, count($sample));
        foreach ($sample as $row) {
            $this->assertIsArray($row['cells'] ?? null);
            $this->assertCount(count($sample[0]['cells']), $row['cells'], 'every row has the same number of cells');
        }
    }

    public function test_a_block_heading_names_the_block_and_a_repeated_item_heading_gets_its_own_text(): void
    {
        $fields = new Fields([
            ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
            ['handle' => 'sub_heading', 'field' => ['type' => 'text', 'display' => 'Sub heading']],
            ['handle' => 'cards', 'field' => ['type' => 'grid', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]],
        ]);

        $named = Samples::forFields($fields, [], 'Cards block');

        $this->assertSame('Cards block preview', $named['heading']);
        $this->assertStringNotContainsString('Cards block', $named['sub_heading']);
        $this->assertSame('A short heading', $named['cards'][0]['title'], 'a heading inside a repeated item gets the item text');
        $this->assertSame('A sample heading for this section', Samples::forFields($fields)['heading']);
    }

    public function test_side_by_side_text_areas_get_different_text_and_the_first_runs_longer(): void
    {
        $column = fn () => ['type' => 'group', 'fields' => [['handle' => 'article', 'field' => ['type' => 'bard']]]];
        $sample = Samples::forFields(new Fields([
            ['handle' => 'left_column', 'field' => $column()],
            ['handle' => 'right_column', 'field' => $column()],
        ]));
        $text = fn (array $doc) => implode('', array_column($doc[0]['content'], 'text'));

        $left = $text($sample['left_column']['article']);
        $right = $text($sample['right_column']['article']);
        $this->assertNotSame($left, $right);
        $this->assertGreaterThan(strlen($right), strlen($left));

        $single = Samples::forFields(new Fields([['handle' => 'article', 'field' => ['type' => 'bard']]]));
        $this->assertSame(Samples::bardParagraphs(), $single['article'], 'a lone text area keeps the standard paragraph');
    }

    public function test_a_repeating_field_samples_three_items_with_their_own_text_and_a_nested_repeat_samples_one(): void
    {
        $button = ['fields' => [['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label']]]];
        $card = ['fields' => [
            ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
            ['handle' => 'buttons', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['button' => $button]]]]],
        ]];
        $cards = fn (array $extra = []) => new Fields([['handle' => 'cards', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['card' => $card]]], ...$extra]]]);

        $sample = Samples::forFields($cards());
        $this->assertCount(3, $sample['cards']);
        $this->assertCount(3, array_unique(array_column($sample['cards'], 'heading')), 'each card has its own heading');
        $this->assertCount(1, $sample['cards'][0]['buttons'], 'a repeat inside an item samples one');

        $this->assertCount(2, Samples::forFields($cards(['max_sets' => 2]))['cards'], 'the field maximum wins');
        $this->assertCount(4, Samples::forFields($cards(['min_sets' => 4]))['cards'], 'the field minimum wins');

        $buttons = Samples::forFields(new Fields([['handle' => 'buttons', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['button' => $button]]]]]]));
        $this->assertCount(1, $buttons['buttons'], 'buttons read as one call to action');
    }

    public function test_a_checkboxes_sample_ticks_every_option(): void
    {
        $field = new Field('display_contacts', ['type' => 'checkboxes', 'options' => [
            ['key' => 'phone', 'value' => 'Phone'],
            ['key' => 'email', 'value' => 'Email'],
            ['key' => 'address', 'value' => 'Address'],
        ]]);

        $this->assertSame(['phone', 'email', 'address'], Samples::forField($field), 'the preview shows everything the field can add');
    }
}
