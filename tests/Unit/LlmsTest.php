<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Llms;
use Avocadesign\StatamicTools\Tests\TestCase;

class LlmsTest extends TestCase
{
    public function test_a_routed_collection_the_file_never_mentions_is_a_problem(): void
    {
        $content = "# A site\n\n## Pages\n\n{{ collection:pages }}...{{ /collection:pages }}\n";

        $this->assertSame([], Llms::problems($content, ['pages']));
        $this->assertSame(
            ["llms.txt doesn't list the services collection, which has a route: add a section for it in the Bots global, LLMs tab"],
            Llms::problems($content, ['pages', 'services'])
        );
    }

    public function test_the_kit_s_own_placeholders_count_as_unfinished(): void
    {
        $content = "# A site\n\n## Other collections (1 section per collection)\n\n-\n\n- Location: [Town/City, Region, Country]\n";

        $problems = Llms::problems($content, []);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('1 section per collection', $problems[0]);
        $this->assertStringContainsString('Town/City', $problems[1]);
    }

    public function test_an_empty_file_is_one_problem_not_many(): void
    {
        $this->assertSame(['llms.txt has no content: write it in the Bots global, LLMs tab'], Llms::problems('', ['pages']));
        $this->assertSame(['llms.txt has no content: write it in the Bots global, LLMs tab'], Llms::problems(null, ['pages']));
    }
}
