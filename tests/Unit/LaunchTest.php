<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Launch;
use Avocadesign\StatamicTools\Tests\TestCase;

class LaunchTest extends TestCase
{
    public function test_a_launched_site_still_on_the_kit_s_constraint_is_told_to_narrow_it(): void
    {
        $problems = Launch::problems('2026-10-14', '<2.0', 'v0.1.48');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('move past 0.1 (<2.0): ask for ^0.1.48', $problems[0]);
    }

    public function test_a_launched_site_held_to_its_line_is_no_problem(): void
    {
        $this->assertSame([], Launch::problems('2026-10-14', '^0.1.48', 'v0.1.48'));
        $this->assertSame([], Launch::problems('2026-10-14', '~0.1.48', 'v0.1.50'));
        $this->assertSame([], Launch::problems('2026-10-14', '0.1.48', 'v0.1.48'));
    }

    public function test_from_1_0_the_line_is_the_first_number(): void
    {
        $this->assertSame([], Launch::problems('2026-10-14', '^1.2.0', 'v1.4.1'));

        $problems = Launch::problems('2026-10-14', '<3.0', 'v1.4.1');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('move past 1.x (<3.0): ask for ^1.4.1', $problems[0]);
    }

    public function test_a_site_not_yet_launched_says_nothing(): void
    {
        $this->assertSame([], Launch::problems(null, '<2.0', 'v0.1.48'));
        $this->assertSame([], Launch::problems('', '<2.0', 'v0.1.48'));
    }

    public function test_a_launch_date_yaml_read_as_a_timestamp_still_counts(): void
    {
        $this->assertCount(1, Launch::problems(1792022400, '<2.0', 'v0.1.48'));
    }

    public function test_a_local_folder_a_branch_or_an_unreadable_constraint_says_nothing(): void
    {
        $this->assertSame([], Launch::problems('2026-10-14', '*@dev', 'dev-main'));
        $this->assertSame([], Launch::problems('2026-10-14', 'not a constraint', 'v0.1.48'));
        $this->assertSame([], Launch::problems('2026-10-14', null, 'v0.1.48'));
        $this->assertSame([], Launch::problems('2026-10-14', '<2.0', null));
    }
}
