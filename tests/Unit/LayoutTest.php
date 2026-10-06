<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Layout;
use PHPUnit\Framework\TestCase;

class LayoutTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/avoca-layout-'.uniqid().'.antlers.html';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function layout(string $html): string
    {
        file_put_contents($this->file, $html);

        return Layout::bodyClass($this->file);
    }

    public function test_the_body_classes_are_read_from_the_layout(): void
    {
        $this->assertSame('flex flex-col min-h-screen bg-white', $this->layout("<html>\n<head></head>\n<body class=\"flex flex-col min-h-screen bg-white\">\n{{ template_content }}\n</body></html>"));
    }

    public function test_other_attributes_and_line_breaks_are_allowed(): void
    {
        $this->assertSame('bg-neutral text-white', $this->layout("<body id=\"top\"\n    class='bg-neutral\n    text-white' x-data>"));
    }

    public function test_antlers_in_the_attribute_is_left_out_with_what_a_condition_adds(): void
    {
        $this->assertSame('min-h-screen bg-white', $this->layout('<body class="min-h-screen {{ if segment_1 == \'dark\' }}bg-black text-white{{ /if }} {{ body_class }} bg-white">'));
    }

    public function test_no_layout_or_no_class_reads_as_empty(): void
    {
        $this->assertSame('', Layout::bodyClass(sys_get_temp_dir().'/avoca-no-such-layout.antlers.html'));
        $this->assertSame('', $this->layout('<body data-theme="dark">'));
    }
}
