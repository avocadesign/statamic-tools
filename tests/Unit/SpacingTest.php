<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Spacing;
use PHPUnit\Framework\TestCase;

class SpacingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/avoca-views-'.uniqid();
        mkdir($this->dir.'/page_builder', 0777, true);
        mkdir($this->dir.'/components');
        file_put_contents($this->dir.'/default.antlers.html', "<main x-init=\"\$el.classList.remove('pb-12')\"\n    class=\"pb-12 md:pb-16 lg:pb-24 stack-12 md:stack-16 lg:stack-18\" id=\"content\">\n<article class=\"prose contents stack-8\"></article></main>");
        file_put_contents($this->dir.'/page_builder/_text.antlers.html', '<div class="stack-2"><article class="stack-8 stack-space-2"></article></div>');
        file_put_contents($this->dir.'/components/_contact_details.antlers.html', '<div class="flex stack-3">');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_responsive_values_read_each_breakpoint_prefix(): void
    {
        $classes = 'pb-12 md:pb-16 stack-12 md:stack-16 lg:stack-18 stack-space-2';

        $this->assertSame(['' => 12.0, 'md' => 16.0, 'lg' => 18.0], Spacing::responsive($classes, 'stack'));
        $this->assertSame(['' => 12.0, 'md' => 16.0], Spacing::responsive($classes, 'pb'));
    }

    public function test_a_size_is_steps_of_the_unit_in_rem_and_px(): void
    {
        $this->assertSame(['rem' => '3rem', 'px' => '48px'], Spacing::size(12, '0.25rem'));
        $this->assertSame(['rem' => '0.25rem', 'px' => '4px'], Spacing::size(1, '4px'));
    }

    public function test_the_section_stack_comes_from_the_page_template_and_is_left_out_of_the_stack_choices(): void
    {
        $section = Spacing::section($this->dir.'/default.antlers.html');

        $this->assertSame('stack-12 md:stack-16 lg:stack-18', $section['stack_classes']);
        $this->assertSame(['' => 12.0, 'md' => 16.0, 'lg' => 24.0], $section['padding_bottom']);

        $stacks = Spacing::stacks($this->dir, ['page_builder/_text' => 'Text block'], $section, $this->dir.'/default.antlers.html');

        $this->assertSame([2.0, 3.0, 8.0], array_column($stacks, 'steps'), 'the section stack classes are not listed as stack choices');
        $this->assertSame(['Default template', 'Text block'], $stacks[2]['used_in']);
        $this->assertSame(['Contact details (components)'], $stacks[1]['used_in']);
        $this->assertSame('stack-8', $stacks[2]['class']);
    }
}
