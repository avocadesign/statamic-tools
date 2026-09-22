<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\CssTokens;
use PHPUnit\Framework\TestCase;

class CssTokensTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/avoca-css-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/site.css', "@import \"tailwindcss\";\n@import \"./theme.css\";\n@import \"./colours.css\";\n");
        file_put_contents($this->dir.'/theme.css', "@theme {\n  --color-primary: oklch(53% 0.3 290);\n  --color-neutral-100: var(--color-slate-100);\n  --font-weight-*: initial;\n  /* --font-serif: Awesome, Georgia, serif; */\n  /* --font-weight-black: 900; */\n  --text-base: clamp(1rem, 2vw, 1.25rem);\n  --text-base--line-height: 1.5;\n  --font-sans: Inter, sans-serif;\n  --font-weight-bold: 700;\n}\n");
        file_put_contents($this->dir.'/colours.css', "@theme {\n  --color-dark: var(--color-neutral-900);\n  --body-color: var(--color-neutral-100);\n}\n@layer components { .scheme-dark { --body-color: white; } }\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
    }

    public function test_it_follows_relative_imports_and_reads_theme_tokens(): void
    {
        $tokens = CssTokens::fromEntry($this->dir.'/site.css');

        $this->assertSame(['site.css', 'theme.css', 'colours.css'], $tokens->files());
        $this->assertSame('oklch(53% 0.3 290)', $tokens->all()['color-primary']['value']);
        $this->assertSame('var(--color-neutral-900)', $tokens->all()['color-dark']['value']);
    }

    public function test_it_ignores_resets_and_only_reads_theme_blocks(): void
    {
        $tokens = CssTokens::fromEntry($this->dir.'/site.css');

        $this->assertArrayNotHasKey('font-serif', $tokens->all(), 'commented-out tokens are not declarations');
        $this->assertArrayNotHasKey('font-weight-black', $tokens->all());

        $this->assertArrayNotHasKey('font-weight-*', $tokens->all());
        $this->assertSame('var(--color-neutral-100)', $tokens->all()['body-color']['value'], 'the @theme declaration, not the @layer override');
    }

    public function test_type_scale_excludes_line_height_companions(): void
    {
        $scale = CssTokens::fromEntry($this->dir.'/site.css')->typeScale();

        $this->assertSame(['text-base'], array_column($scale, 'name'));
    }

    public function test_colour_groups_put_semantic_first_then_scales(): void
    {
        $groups = CssTokens::fromEntry($this->dir.'/site.css')->colourGroups();

        $this->assertSame(['Semantic', 'Text', 'Neutral scale'], array_keys($groups));
        $this->assertSame(['color-primary', 'color-dark'], array_column($groups['Semantic'], 'name'));
        $this->assertSame(['color-neutral-100'], array_column($groups['Neutral scale'], 'name'));
    }

    public function test_the_prose_group_holds_colours_and_not_spacing(): void
    {
        file_put_contents($this->dir.'/prose.css', "@theme {\n  --prose-base: var(--body-color);\n  --prose-hr: var(--color-primary);\n  --prose-quotes: white;\n  --prose-space: 1em;\n  --prose-list-item-space: .5em;\n  --prose-list-space: var(--prose-space);\n  --prose-invert-base: var(--color-primary);\n  --prose-sm-modifier: .875;\n}\n");
        file_put_contents($this->dir.'/site.css', "@import \"tailwindcss\";\n@import \"./theme.css\";\n@import \"./colours.css\";\n@import \"./prose.css\";\n");

        $groups = CssTokens::fromEntry($this->dir.'/site.css')->colourGroups();

        $this->assertSame(['prose-base', 'prose-hr', 'prose-quotes'], array_column($groups['Prose'], 'name'), 'a length, and a token that resolves to one, is not a colour');
    }

    public function test_fonts_exclude_weights(): void
    {
        $tokens = CssTokens::fromEntry($this->dir.'/site.css');

        $this->assertSame(['font-sans'], array_column($tokens->fonts(), 'name'));
        $this->assertSame(['font-weight-bold'], array_column($tokens->weights(), 'name'));
    }

    public function test_a_rule_reads_its_declarations_apply_list_and_media_blocks(): void
    {
        file_put_contents($this->dir.'/layout.css', "@layer components {\n  .page-builder {\n    @apply py-12 md:py-16 stack-12 md:stack-16;\n    &:has(.x) { padding-top: 0; }\n  }\n  .fluid-grid {\n    --col-gap: clamp(1rem, 3vw, calc(var(--spacing) * 8)); /* fluid */\n    display: grid;\n  }\n  .span-md {\n    grid-column: content;\n    @media (min-width: theme(--breakpoint-md)) { grid-column: col-3 / span 8; }\n  }\n}\n@utility span-full { grid-column: full; }\n");
        file_put_contents($this->dir.'/site.css', "@import \"./theme.css\";\n@import \"./layout.css\";\n");

        $tokens = CssTokens::fromEntry($this->dir.'/site.css');

        $grid = $tokens->rule('.fluid-grid');
        $this->assertSame('layout.css', $grid['file']);
        $this->assertSame(['--col-gap' => 'clamp(1rem, 3vw, calc(var(--spacing) * 8))', 'display' => 'grid'], $grid['declarations']);
        $this->assertSame(['py-12', 'md:py-16', 'stack-12', 'md:stack-16'], $tokens->rule('.page-builder')['apply']);
        $this->assertSame([], $tokens->rule('.page-builder')['declarations'], 'a nested &:has() block is not the rule\'s own declaration');
        $this->assertSame('content', $tokens->rule('.span-md')['declarations']['grid-column']);
        $this->assertSame(['(min-width: theme(--breakpoint-md))' => ['grid-column' => 'col-3 / span 8']], $tokens->rule('.span-md')['media']);
        $this->assertSame('full', $tokens->rule('@utility span-full')['declarations']['grid-column']);
        $this->assertNull($tokens->rule('.missing'));
    }
}
