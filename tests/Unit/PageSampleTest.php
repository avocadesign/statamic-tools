<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\PageSample;
use Avocadesign\StatamicTools\Tests\TestCase;

class PageSampleTest extends TestCase
{
    public function test_it_takes_the_pages_that_cover_the_most_between_them(): void
    {
        $pages = [
            ['url' => '/one', 'features' => ['page', 'text', 'image']],
            ['url' => '/two', 'features' => ['page', 'text']],
            ['url' => '/three', 'features' => ['page', 'form', 'table']],
        ];

        $this->assertSame(['/one', '/three'], PageSample::choose($pages, 5),
            'the two pages between them cover everything; the third adds nothing');
    }

    public function test_it_stops_once_nothing_new_is_left_to_cover(): void
    {
        $pages = [
            ['url' => '/one', 'features' => ['page', 'text']],
            ['url' => '/two', 'features' => ['page', 'text']],
            ['url' => '/three', 'features' => ['page']],
        ];

        $this->assertSame(['/one'], PageSample::choose($pages, 10),
            'two hundred pages built from the same blocks are one page worth rendering');
    }

    public function test_the_limit_wins_and_takes_the_widest_pages_first(): void
    {
        $pages = [
            ['url' => '/narrow', 'features' => ['page']],
            ['url' => '/wide', 'features' => ['page', 'a', 'b', 'c']],
            ['url' => '/middling', 'features' => ['page', 'd']],
        ];

        $this->assertSame(['/wide', '/middling'], PageSample::choose($pages, 2));
    }

    public function test_the_same_site_gives_the_same_answer_twice(): void
    {
        $pages = [
            ['url' => '/a', 'features' => ['page', 'text']],
            ['url' => '/b', 'features' => ['page', 'text']],
        ];

        $this->assertSame(PageSample::choose($pages, 1), PageSample::choose($pages, 1), 'a tie goes to the first');
        $this->assertSame(['/a'], PageSample::choose($pages, 1));
    }

    public function test_no_pages_is_not_an_error(): void
    {
        $this->assertSame([], PageSample::choose([], 5));
    }

    public function test_pages_chosen_elsewhere_count_as_covered(): void
    {
        $pages = [
            ['url' => '/one', 'features' => ['page', 'text']],
            ['url' => '/two', 'features' => ['page', 'form']],
        ];

        $this->assertSame(['/two'], PageSample::choose($pages, 2, ['page', 'text']),
            'the mount page and one entry per collection are already rendered; the fill should not repeat them');
    }
}
