<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Blocks;
use PHPUnit\Framework\TestCase;
use Statamic\Fields\Field;

class BlocksOptionsTest extends TestCase
{
    public function test_it_reads_every_option_shape_statamic_allows(): void
    {
        $map = new Field('a', ['type' => 'select', 'options' => ['left' => 'Left', 'centre' => 'Centred']]);
        $keyValue = new Field('b', ['type' => 'button_group', 'options' => [['key' => 'solid', 'value' => 'Solid'], ['key' => 'outline', 'value' => 'Outline']]]);
        $list = new Field('c', ['type' => 'select', 'options' => ['spanner', 'tap']]);
        $ints = new Field('d', ['type' => 'select', 'options' => [2 => 'Two', 3 => 'Three']]);

        $this->assertSame(['left' => 'Left', 'centre' => 'Centred'], Blocks::options($map));
        $this->assertSame(['solid' => 'Solid', 'outline' => 'Outline'], Blocks::options($keyValue));
        $this->assertSame(['spanner' => 'spanner', 'tap' => 'tap'], Blocks::options($list));
        $this->assertSame(['2' => 'Two', '3' => 'Three'], Blocks::options($ints));
    }
}
