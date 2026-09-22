<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Conditions;
use Avocadesign\StatamicTools\Tests\TestCase;
use Statamic\Fields\Fields;

class ConditionsTest extends TestCase
{
    public function test_conditions_on_the_fields_beside_it_read_in_the_editors_words(): void
    {
        $fields = new Fields([
            ['handle' => 'display_settings', 'field' => ['type' => 'revealer', 'display' => 'Display settings']],
            ['handle' => 'layout', 'field' => ['type' => 'select', 'display' => 'Column layout', 'options' => ['even' => 'Even', 'stacked' => 'Text above media']]],
            ['handle' => 'position', 'field' => ['type' => 'select', 'display' => 'Media position', 'options' => ['left' => 'Left'], 'if' => ['display_settings' => 'equals true', 'layout' => 'not stacked']]],
        ]);

        $described = Conditions::describe($fields->all()->get('position'), $fields);

        $this->assertTrue($described['visible']);
        $this->assertSame('Only when Display settings is on and Column layout is not Text above media', $described['words']);
        $this->assertSame(['visible' => true, 'words' => null], Conditions::describe($fields->all()->get('layout'), $fields));
    }

    public function test_a_condition_on_the_parent_names_the_parent_field_or_hides_the_field(): void
    {
        $block = new Fields([['handle' => 'card_type', 'field' => ['type' => 'select', 'display' => 'Card Type', 'options' => ['text' => 'Text', 'image' => 'Image', 'icon' => 'Icon']]]]);
        $card = new Fields([['handle' => 'card_image', 'field' => ['type' => 'assets', 'display' => 'Card Image', 'if' => ['$parent.card_type' => 'contains_any image, icon']]]]);

        $this->assertSame("Only when the block's Card Type is Image or Icon", Conditions::describe($card->all()->get('card_image'), $card, $block)['words']);

        $buttons = new Fields([['handle' => 'button_alignment', 'field' => ['type' => 'button_group', 'display' => 'Button Alignment', 'if' => ['$parent.type' => 'equals article']]]]);
        $alignment = $buttons->all()->get('button_alignment');
        $this->assertFalse(Conditions::describe($alignment, $buttons, null, ['text', 'media_and_text'])['visible']);
        $this->assertTrue(Conditions::describe($alignment, $buttons, null, ['article'])['visible']);
        $this->assertFalse(Conditions::describe($alignment, $buttons)['visible'], 'a block sits in the entry, which has no set type');
    }
}
