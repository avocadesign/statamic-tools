<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Settings;
use Avocadesign\StatamicTools\Tests\TestCase;
use Statamic\Fields\Fields;

class SettingsTest extends TestCase
{
    public function test_every_choice_field_becomes_a_setting_with_all_its_options_and_the_default_marked(): void
    {
        $fields = new Fields([
            ['handle' => 'heading', 'field' => ['type' => 'text']],
            ['handle' => 'style', 'field' => ['type' => 'select', 'display' => 'Style', 'default' => 'offset', 'options' => ['plain' => 'Plain', 'offset' => 'Offset', 'boxed' => 'Boxed']]],
            ['handle' => 'reverse', 'field' => ['type' => 'toggle', 'display' => 'Reverse']],
            ['handle' => 'link_type', 'field' => ['type' => 'select', 'options' => ['none' => 'None', 'url' => 'URL']]],
        ]);

        $built = Settings::forFields($fields);

        $this->assertSame(['style', 'reverse'], array_column($built['settings'], 'handle'), 'link_type is never a setting');
        $style = $built['settings'][0];
        $this->assertSame(['Plain', 'Offset', 'Boxed'], array_column($style['options'], 'label'));
        $this->assertSame([false, true, false], array_column($style['options'], 'is_default'), 'the configured default is the default, not the first option');
        $this->assertSame('offset', $built['base']['style']);
        $this->assertSame('boxed', $style['options'][2]['values']['style']);
        $this->assertFalse($style['options'][2]['values']['reverse'], 'other fields stay at their default in each render');
        $this->assertSame('Reverse: On', $built['settings'][1]['options'][1]['recipe']);
    }

    public function test_a_conditional_field_carries_its_conditions_and_they_are_applied_to_its_renders(): void
    {
        $fields = new Fields([
            ['handle' => 'display_settings', 'field' => ['type' => 'revealer', 'display' => 'Display settings']],
            ['handle' => 'align', 'field' => ['type' => 'select', 'display' => 'Display style', 'default' => 'left', 'options' => ['left' => 'Left', 'centre' => 'Centre'], 'if' => ['display_settings' => 'equals true']]],
            ['handle' => 'align_headings', 'field' => ['type' => 'button_group', 'display' => 'Align Text', 'options' => ['left' => 'Left', 'centre' => 'Centre Headings'], 'if' => ['display_settings' => 'equals true', 'align' => 'equals centre']]],
        ]);

        $built = Settings::forFields($fields);
        [$align, $headings] = $built['settings'];

        $this->assertSame('Display settings', $align['revealer']);
        $this->assertSame([], $align['requires']);
        $this->assertSame('Display settings', $headings['revealer']);
        $this->assertSame([['handle' => 'align', 'display' => 'Display style', 'label' => 'Centre', 'key' => 'centre', 'op' => 'equals', 'keys' => ['centre']]], $headings['requires']);
        $this->assertSame('centre', $headings['options'][1]['values']['align'], 'the render sets Display style to Centre so the option can show');
        $this->assertTrue($headings['options'][1]['values']['display_settings']);
    }

    public function test_excluded_fields_produce_no_setting(): void
    {
        $fields = new Fields([
            ['handle' => 'block_margins', 'field' => ['type' => 'select', 'options' => ['default' => 'Default', 'no-top' => 'No top']]],
        ]);

        $this->assertSame([], Settings::forFields($fields, ['block_margins'])['settings']);
        $this->assertCount(1, Settings::forFields($fields)['settings']);
    }

    public function test_a_condition_on_the_parent_hides_the_setting_unless_a_possible_parent_meets_it(): void
    {
        $fields = new Fields([
            ['handle' => 'label', 'field' => ['type' => 'text']],
            ['handle' => 'button_alignment', 'field' => ['type' => 'button_group', 'display' => 'Button Alignment', 'options' => ['start' => 'Left', 'center' => 'Centre'], 'if' => ['$parent.type' => 'equals article']]],
            ['handle' => 'entry_only', 'field' => ['type' => 'toggle', 'display' => 'Entry only', 'if' => ['$root.template' => 'equals home']]],
        ]);

        $this->assertSame([], Settings::forFields($fields)['settings'], 'a block sits in the entry, which has no set type');
        $this->assertSame([], Settings::forFields($fields, [], null, ['text', 'media_and_text'])['settings'], 'no possible parent is called article');
        $this->assertSame(['button_alignment'], array_column(Settings::forFields($fields, [], null, ['article'])['settings'], 'handle'), 'a root condition stays hidden');
    }

    public function test_not_and_one_of_conditions_are_modelled_and_applied_to_renders(): void
    {
        $fields = new Fields([
            ['handle' => 'style', 'field' => ['type' => 'select', 'display' => 'Display style', 'default' => 'standard', 'options' => ['standard' => 'Standard', 'inline' => 'Inline', 'stacked' => 'Stacked']]],
            ['handle' => 'position', 'field' => ['type' => 'button_group', 'display' => 'Text position', 'options' => ['left' => 'Left', 'right' => 'Right'], 'if' => ['style' => 'not standard']]],
            ['handle' => 'valign', 'field' => ['type' => 'select', 'display' => 'Text vertical alignment', 'options' => ['top' => 'Top', 'bottom' => 'Bottom'], 'if' => ['style' => 'contains_any inline, stacked']]],
        ]);
        $settings = collect(Settings::forFields($fields)['settings'])->keyBy('handle');

        $this->assertSame([['handle' => 'style', 'display' => 'Display style', 'label' => 'not Standard', 'key' => 'standard', 'op' => 'not', 'keys' => ['standard']]], $settings['position']['requires']);
        $this->assertSame('inline', $settings['position']['options'][0]['values']['style'], 'a render moves off the excluded value');
        $this->assertSame([['handle' => 'style', 'display' => 'Display style', 'label' => 'Inline or Stacked', 'key' => 'inline', 'op' => 'in', 'keys' => ['inline', 'stacked']]], $settings['valign']['requires']);
        $this->assertSame('inline', $settings['valign']['options'][1]['values']['style'], 'a render picks one of the allowed values');
    }
}
