<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Docs;
use Avocadesign\StatamicTools\Tests\TestCase;

class DocsTest extends TestCase
{
    public function test_guidance_splits_into_sections_and_stub_text_counts_as_not_written(): void
    {
        $sections = Docs::markdownSections(implode("\n", [
            '## When to use', '', 'For body copy.', '',
            '## When not to use', '', 'Name the block to reach for instead, and why.', '',
            '## Variants', '', 'Anything worth knowing about the display settings.', '',
            '## Notes for AI', '', 'Keep it short.', '',
            '## Tips', '', 'Use sparingly.', '',
        ]));

        $this->assertSame('For body copy.', $sections['when_to_use']);
        $this->assertSame('', $sections['when_not_to_use']);
        $this->assertSame('Keep it short.', $sections['notes_for_ai']);
        $this->assertSame([['Tips', 'Use sparingly.']], $sections['other']);

        $stub = Docs::stub('blocks', 'text', 'Text');
        $this->assertStringContainsString('## Notes for AI', $stub);
        $this->assertSame('', Docs::markdownSections($stub)['notes_for_ai'], 'a fresh stub gives the catalogue nothing');
    }

    public function test_a_design_stub_gives_the_catalogue_nothing_until_written(): void
    {
        $stub = Docs::designStub();
        $this->assertStringContainsString('## Colour schemes', $stub);
        $this->assertSame(['sections' => [], 'notes_for_ai' => ''], Docs::designSections(preg_replace('/\A---.*?---\n/s', '', $stub)));

        $written = Docs::designSections("## Colour schemes\n\nUse Primary once per page.\n\n## Spacing\n\nSay when the block margins should change from Default.\n\n## Notes for AI\n\nKeep it calm.\n");
        $this->assertSame([['Colour schemes', 'Use Primary once per page.']], $written['sections']);
        $this->assertSame('Keep it calm.', $written['notes_for_ai']);
    }

    public function test_a_stub_quotes_its_front_matter_so_a_hash_in_the_instructions_survives(): void
    {
        $stub = Docs::stub('blocks', 'anchor', 'Anchor', 'An invisible anchor that can be linked to via #id.');
        preg_match('/\A---\n(.*?)\n---\n/s', $stub, $m);

        $this->assertSame('An invisible anchor that can be linked to via #id.', \Statamic\Facades\YAML::parse($m[1])['description']);
    }
}
