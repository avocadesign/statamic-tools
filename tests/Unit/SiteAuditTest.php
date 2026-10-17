<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\Audit\Audit;
use Avocadesign\StatamicTools\Tests\TestCase;

class SiteAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/avoca-audit-'.uniqid();
        $this->cleanSite();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_a_site_built_the_way_the_recipe_says_has_nothing_to_report(): void
    {
        $result = Audit::make($this->root)->run();

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['ignored']);
    }

    public function test_an_alias_of_a_tailwind_token_is_found_but_the_kit_s_hooks_are_not(): void
    {
        $this->write('resources/css/theme.css', "@theme {\n  --text-3xl: 2rem;\n  --t-h2: var(--text-3xl);\n  --typography-h2: var(--text-3xl);\n  --btn-primary-bg: var(--color-ink);\n  --text-3xl--line-height: var(--leading-tight);\n  --hero-from: var(--color-ink);\n}\n.scheme-dark { --hero-from: white; }\n");

        $this->assertSame(['resources/css/theme.css:3 --t-h2 only points at --text-3xl: use --text-3xl'], $this->found('token-alias'));
    }

    public function test_two_tokens_with_one_value_are_found_tailwind_s_own_among_them(): void
    {
        $this->write('node_modules/tailwindcss/theme.css', "@theme default {\n  --radius-3xl: 1.5rem;\n  --leading-tight: 1.25;\n}\n");
        $this->write('resources/css/theme.css', "@theme {\n  --color-paper: #FFF;\n  --color-white: #ffffff;\n  --radius-panel: 1.5rem;\n  --text-xl: 1.25rem;\n}\n");

        $this->assertSame([
            "resources/css/theme.css:2 --color-paper and --color-white both hold #ffffff",
            "resources/css/theme.css:4 Tailwind's --radius-3xl and --radius-panel both hold 1.5rem",
        ], $this->found('token-duplicate'), 'a radius and a font size of 1.25rem mean different things');
    }

    public function test_a_namespace_the_site_resets_is_not_compared_with_tailwind_s(): void
    {
        $this->write('node_modules/tailwindcss/theme.css', "@theme default {\n  --radius-3xl: 1.5rem;\n}\n");
        $this->write('resources/css/theme.css', "@theme {\n  --radius-*: initial;\n  --radius-panel: 1.5rem;\n}\n");

        $this->assertSame([], $this->found('token-duplicate'));
    }

    public function test_a_spacing_scale_of_the_site_s_own_is_found_once_per_file(): void
    {
        $this->write('resources/css/theme.css', "@theme {\n  --s1: 4px;\n  --s2: 8px;\n  --s4: 16px;\n  --spacing-18: 4.5rem;\n  --spacing-page-edge: 2rem;\n}\n");

        $this->assertSame([
            'resources/css/theme.css:2 a spacing scale of its own, --s1, --s2, --s4: write each as a step of --spacing',
            'resources/css/theme.css:5 --spacing-18 repeat what --spacing already gives every step: remove them',
        ], $this->found('token-scale'));
    }

    public function test_tokens_belong_in_the_token_files_though_a_component_may_keep_its_parameters(): void
    {
        $this->write('resources/css/tokens.css', ":root {\n  --brand: #f0f;\n}\n");
        $this->write('resources/css/components/card.css', "@theme {\n  --radius-card: 1rem;\n}\n:root {\n  --card-tilt: 3deg;\n}\n");

        $this->assertSame([
            'resources/css/components/card.css:1 declares 1 token of its own in @theme: --radius-card',
            'resources/css/tokens.css:1 declares 1 token of its own in :root { }: --brand',
        ], $this->found('token-elsewhere'));
    }

    public function test_bem_is_found_once_per_block_where_it_is_defined(): void
    {
        $this->write('resources/css/components/card.css', ".card__title { color: red; }\n.card--wide { width: 100%; }\n.glide__track { overflow: hidden; }\n");
        $this->write('resources/views/page_builder/_card.antlers.html', '<div class="card--wide"><h3 class="card__title">{{ title }}</h3><div class="glide__track"></div></div>');
        $this->write('resources/views/page_builder/_tile.antlers.html', '<div class="tile__body bg-(--card-bg) [--x:1]"></div>');

        $this->assertSame([
            'resources/css/components/card.css:1 card: 2 BEM classes, .card__title, .card--wide, written in _card.antlers.html',
            'resources/views/page_builder/_tile.antlers.html:1 tile: 1 BEM class, tile__body, which no stylesheet defines',
        ], $this->found('bem-class'), "a carousel library's own classes and Tailwind's (--var) values are not BEM");
    }

    public function test_a_class_only_one_template_uses_is_found(): void
    {
        $this->write('resources/css/utilities.css', "@layer components {\n  .eyebrow { @apply text-xs uppercase; }\n  .card-shell { @apply rounded-xl; }\n  .js-armed .card-shell { opacity: 1; }\n}\n");
        $this->write('resources/views/page_builder/_card.antlers.html', '<div class="card-shell lg:card-shell"><p class="eyebrow">{{ label }}</p></div>');
        $this->write('resources/views/layout.antlers.html', "<html><script>document.documentElement.classList.add('js-armed')</script>{{ template_content }}</html>");

        $this->assertSame(['resources/css/utilities.css:3 1 class only resources/views/page_builder/_card.antlers.html uses: .card-shell'], $this->found('single-use-class'), 'eyebrow is shared; js-armed is set by a script');
    }

    public function test_a_file_that_only_imports_another_is_found(): void
    {
        $this->write('resources/css/whoosh.css', "/* The site's own */\n@import \"./components/card.css\";\n");

        $this->assertSame(['resources/css/whoosh.css:2 only imports ./components/card.css'], $this->found('import-only'));
    }

    public function test_a_rule_that_sets_what_peak_sets_is_an_override_but_a_utility_of_the_site_s_own_is_not(): void
    {
        $this->write('resources/css/utilities.css', ".fluid-grid { column-gap: 2rem; }\n.fluid-grid { color: red; }\n@utility stack-block {\n  > * { --stack-space: 2rem; }\n}\n");

        $this->assertSame(['resources/css/utilities.css:1 .fluid-grid sets column-gap, which peak.css sets for it'], $this->found('peak-override'));
    }

    public function test_tailwind_without_source_none_is_found(): void
    {
        $this->write('resources/css/site.css', "@import \"tailwindcss\";\n@import \"./theme.css\";\n");

        $this->assertSame(['resources/css/site.css:1 imports tailwindcss without source(none), so Tailwind scans the whole repository'], $this->found('source-none'));
    }

    public function test_colours_and_type_sizes_written_into_a_template_are_found_but_not_in_an_svg_or_an_email(): void
    {
        $this->write('resources/views/page_builder/_card.antlers.html', "<div class=\"bg-[#f5f5f5] text-[oklch(50%_0.1_200)] text-[22px] text-(--x) text-lg\" style=\"color: #333\">\n<svg><circle style=\"fill: #df3f95\"/></svg></div>");
        $this->write('resources/views/email/form.antlers.html', '<td style="color: #333" class="text-[13px]"></td>');

        $this->assertSame(['resources/views/page_builder/_card.antlers.html:1 3 colours written in: bg-[#f5f5f5], text-[oklch(50%_0.1_200)], style #333'], $this->found('arbitrary-colour'));
        $this->assertSame(['resources/views/page_builder/_card.antlers.html:1 1 type size written in: text-[22px]'], $this->found('arbitrary-text-size'));
    }

    public function test_an_at_source_in_a_file_critical_css_imports_is_found(): void
    {
        $this->write('resources/css/peak.css', "@source \"../views/**/*\";\n@layer components { .fluid-grid { display: grid; column-gap: 1rem; } }\n");
        $this->write('resources/css/critical.css', "@import \"tailwindcss\" source(none);\n@import \"./peak.css\";\n@source \"../views/layout\";\n");

        $this->assertSame(['resources/css/peak.css:1 critical.css imports this file, so its @source "../views/**/*" scans for the critical sheet too'], $this->found('critical-shared-source'));
    }

    public function test_a_page_specific_critical_sheet_has_to_save_the_other_pages_enough(): void
    {
        $this->write('resources/css/critical.css', "@import \"tailwindcss\" source(none);\n");
        foreach (['forms', 'map', 'faq'] as $name) {
            $this->write("resources/css/critical-{$name}.css", "@import \"./critical.css\";\n");
        }
        $this->assertContains('no build in public/ to measure, so the critical sheets were not compared: run npm run build', Audit::make($this->root)->run()['notes']);

        // Rules that don't compress away, so the sizes are what the rules add.
        $rules = fn (string $prefix, int $count) => implode('', array_map(fn ($i) => ".{$prefix}".md5($prefix.$i).'{width:'.$i.'px}', range(1, $count)));
        $base = $rules('a', 300);
        $saving = fn (string $css) => 1 - strlen(gzencode($base, 9)) / strlen(gzencode($css, 9));
        for ($n = 1, $faq = ''; $saving($faq = $base.$rules('q', $n)) < 0.12; $n++) {
        }
        $this->write('public/build/assets/critical.css', $base);
        $this->write('public/build/assets/critical-forms.css', $base.$rules('f', 3));
        $this->write('public/build/assets/critical-map.css', $base.$rules('m', 300));
        $this->write('public/build/assets/critical-faq.css', $faq);
        $this->write('public/build/manifest.json', json_encode(array_merge(
            ['resources/css/critical.css' => ['file' => 'assets/critical.css']],
            ...array_map(fn ($name) => ["resources/css/critical-{$name}.css" => ['file' => "assets/critical-{$name}.css"]], ['forms', 'map', 'faq']),
        )));

        $found = $this->found('critical-saving');
        $this->assertCount(1, $found, 'the map sheet saves the other pages half their critical CSS');
        $this->assertMatchesRegularExpression('~^resources/css/critical-forms\.css:1 saves the other pages [0-9]% of their critical CSS~', $found[0]);
        $this->assertMatchesRegularExpression('~^critical-faq\.css saves the other pages 1[0-4]% .*: on the line for a sheet of its own$~m', implode("\n", Audit::make($this->root)->run()['notes']));
    }

    public function test_a_script_that_finds_elements_by_class_is_found(): void
    {
        $this->write('resources/js/faq.js', "// .not-a-selector in a comment\nconst a = root.querySelector('[data-faq=\"a.b\"]')\nconst b = root.querySelector('span:not(.dot)')\nconst c = el.closest(`[data-topic=\"\${key}\"]`)\nconst d = document.getElementsByClassName('x')\n");
        $this->write('resources/views/page_builder/_faq.antlers.html', '<div x-init="$el.querySelector(\'.answer\').focus()"></div>');

        $this->assertSame([
            "resources/js/faq.js:3 querySelector('span:not(.dot)') finds an element by class",
            'resources/js/faq.js:5 getElementsByClassName finds elements by class',
            "resources/views/page_builder/_faq.antlers.html:1 querySelector('.answer') finds an element by class",
        ], $this->found('script-class-selector'));
    }

    public function test_a_script_reading_a_value_only_a_stylesheet_sets_is_found(): void
    {
        $this->write('resources/css/components/hero.css', ":root {\n  --hero-delay: 200ms;\n  --hero-gap: 1rem;\n}\n");
        $this->write('resources/css/theme.css', "@theme static {\n  --duration-travel: 420ms;\n}\n");
        $this->write('resources/views/page_builder/_hero.antlers.html', '<div style="--hero-speed: {{ speed }}"></div>');
        $this->write('resources/js/hero.js', "const read = (styles, name) => styles.getPropertyValue(name)\nconst a = read(root, '--hero-delay')\nconst b = read(root, '--hero-speed')\nconst c = read(root, '--duration-travel')\nel.style.setProperty('--hero-x', '1px')\nconst d = read(el, '--hero-x')\n");

        $this->assertSame(["resources/js/hero.js:2 reads --hero-delay, which only hero.css sets: pass it in x-data, or set it in the element's style"], $this->found('script-reads-css'));
    }

    public function test_an_alpine_plugin_nothing_uses_is_found(): void
    {
        $this->write('resources/js/site.js', "import Alpine from 'alpinejs'\nimport collapse from '@alpinejs/collapse'\nimport intersect from '@alpinejs/intersect'\n// x-intersect would go here\nAlpine.plugin(collapse)\nAlpine.plugin(intersect)\n");

        $this->assertSame(['resources/js/site.js:6 registers @alpinejs/intersect, and nothing uses x-intersect'], $this->found('unused-alpine-plugin'));

        $this->write('resources/js/site.js', "import Alpine from 'alpinejs'\nimport collapse from '@alpinejs/collapse'\nimport morph from '@alpinejs/morph'\nAlpine.plugin([collapse, morph])\n");
        $this->assertSame(['resources/js/site.js:4 registers @alpinejs/morph, and nothing uses Alpine.morph or x-morph'], $this->found('unused-alpine-plugin'));
    }

    public function test_a_captcha_the_layout_prints_is_found_but_not_one_a_block_pushes(): void
    {
        $this->write('resources/views/layout.antlers.html', "<head>\n{{ captcha:head }}\n<script src=\"https://maps.googleapis.com/maps/api/js\"></script>\n<script src=\"https://www.googletagmanager.com/gtag/js\"></script>\n</head>");
        $this->write('resources/views/page_builder/_form.antlers.html', '{{ push:head }}{{ captcha:head }}{{ /push:head }}');

        $this->assertSame([
            'resources/views/layout.antlers.html:2 {{ captcha:head }} loads on every page: push it from the block that needs it',
            'resources/views/layout.antlers.html:3 maps.googleapis.com loads on every page: push it from the block that needs it',
        ], $this->found('layout-third-party'));
    }

    public function test_a_bare_img_is_found_but_not_an_svg_a_script_s_or_the_picture_partial_s(): void
    {
        $this->write('resources/views/layout/_page_header.antlers.html', "{{# hero #}}\n<img src=\"{{ glide:hero_image width=\"1280\" }}\" alt=\"\">\n<img src=\"/visuals/logo.svg\" alt=\"\">\n<img :src=\"images[i].url\">\n<noscript><img src=\"https://example.com/pixel.gif\"></noscript>");
        $this->write('resources/views/components/utilities/_figure.antlers.html', '<img src="{{ url }}" srcset="{{ srcset }}">');
        $this->write('resources/views/email/form.antlers.html', '<img src="{{ logo }}">');

        $this->assertSame(['resources/views/layout/_page_header.antlers.html:2 <img src="{{ glide:hero_image width="1280" }}"> has no srcset or sizes: use the figure or picture partial'], $this->found('bare-img'));
    }

    public function test_a_long_desc_is_found(): void
    {
        $this->write('resources/views/page_builder/_note.antlers.html', "{{#\n    @name Note\n    @desc The Note page builder block,\n    which runs on.\n    @set page.page_builder.note\n#}}\n");

        $this->assertSame(['resources/views/page_builder/_note.antlers.html:3 @desc runs to 2 lines'], $this->found('desc-long'));
    }

    public function test_a_comment_that_repeats_the_code_s_values_is_found_but_one_that_gives_context_is_not(): void
    {
        $this->write('resources/views/page_builder/_note.antlers.html', implode("\n", [
            '<!-- /page_builder/_note.antlers.html -->',
            '{{# text panel: white, 860px at most, on the left #}}',
            '<div class="bg-white max-w-[860px]">',
            '{{# skip button, a 32px circle that widens on hover #}}',
            '<button class="size-8 rounded-full hover:w-auto lg:px-2" data-skip></button>',
            '{{# Buttons sit a clear step below the text: the column gap plus mt-6. #}}',
            '{{ partial:components/buttons class="mt-6" }}',
            '{{# A long note that says why this works the way it does, which a reader would never guess from the markup, and which names data-skip and --note-gap so they can find what reads them. #}}',
            '<div data-skip style="--note-gap: 2rem"></div>',
            '{{# <div class="old">2px 4px</div> #}}',
            '</div>',
        ]));
        $this->write('resources/css/components/ring.css', "/* the focus ring, a solid 2px line in the readable pink */\n.ring { outline: 2px solid var(--color-accent); }\n/* 9999px, not rounded-full's infinite radius, which animates differently */\n.pill { border-radius: 9999px; }\n");

        $this->assertSame([
            'resources/css/components/ring.css:1 repeats 2px, pink from the code beside it: the focus ring, a solid 2px line in the readable pink',
            'resources/views/page_builder/_note.antlers.html:2 repeats 860px, white from the code beside it: text panel: white, 860px at most, on the left',
        ], $this->found('comment-restates'), 'one value repeated, as 32px is size-8, is not enough; names say what reads them; commented-out code is code');
    }

    public function test_a_comment_that_tells_history_is_found(): void
    {
        $this->write('resources/js/hero.js', "// Ported from the Astro build's hero.js.\nexport default {}\n/* Used to hide alpine elements before being rendered. */\n/* The rect is measured against the page, not the frame. */\n/* Paper's card (12 October): 7px padding. */\n");

        $this->assertSame([
            'resources/js/hero.js:1 "Ported from": Ported from the Astro build\'s hero.js.',
            'resources/js/hero.js:5 "12 October": Paper\'s card (12 October): 7px padding.',
        ], $this->found('comment-history'));
    }

    public function test_a_colour_defined_outside_colours_css_is_found_but_a_mix_of_the_palette_is_not(): void
    {
        $this->write('resources/css/colours.css', "@theme {\n  --color-ink: #320c42;\n  --color-surface: #dfddf4;\n  --body-color: var(--color-ink);\n}\n");
        $this->write('resources/css/components/hero.css', implode("\n", [
            ':root {',
            '  --hero-tilt: 3deg;',
            '  --hero-ground: color-mix(in srgb, var(--color-ink) 10%, var(--color-surface));',
            '  --hero-glow: oklch(from var(--color-ink) l c h / 50%);',
            '  --hero-tick: url("data:image/svg+xml,%3Csvg stroke=\'%23fff\'/%3E");',
            '}',
            '.hero__ink { color: #330c43; }',
            '.hero__near { color: #3a1650; }',
            '.hero__shade { background: rgba(50, 12, 66, 0.2); }',
            '.hero__leaf { fill: #2f9e44; }',
        ]));

        $this->assertSame([
            'resources/css/components/hero.css:7 defines 4 colours of its own: #330c43 (the same as --color-ink), #3a1650 (close to --color-ink), rgba(50, 12, 66, 0.2) (--color-ink at 20%), #2f9e44',
        ], $this->found('component-colour'), 'mixes of the palette, parameters and data images are not colours of its own');
    }

    public function test_a_script_that_marks_state_with_a_class_is_found_once_per_file(): void
    {
        $this->write('resources/js/hero.js', "const frame = document.createElement('iframe')\nframe.className = 'hero__frame'\nhero.classList.add('is-playing')\nhero.classList.toggle('is-held', !visible)\nif (art.classList.contains('is-playing')) {}\nel.dataset.active = ''\n");
        $this->write('resources/views/layout.antlers.html', "<script>document.documentElement.classList.add('js-armed')</script>");

        $this->assertSame([
            "resources/js/hero.js:3 marks state with is-playing, is-held, and reads state from is-playing: a data- attribute lets the template's utilities read it",
            "resources/views/layout.antlers.html:1 marks state with js-armed: a data- attribute lets the template's utilities read it",
        ], $this->found('script-class-state'), 'a class given to an element the script creates is styling, not state');
    }

    public function test_what_utilities_can_say_in_a_stylesheet_is_counted_for_apply(): void
    {
        $this->write('resources/css/components/scene.css', implode("\n", [
            '.scene__card { display: flex; gap: 1rem; border-radius: var(--radius-xl); color: var(--color-ink); }',
            '.scene__pin { position: absolute; top: 50%; width: 38px; height: 38px; transform: rotate(4deg); }',
            '.scene__path { offset-path: path("M0 0"); animation: drift 3s; }',
            '@media (min-width: 64rem) { .scene__card { display: grid; padding: 0.5rem 2rem; } }',
            '@supports (offset-path: none) { .scene__pin { display: none; } }',
            '@keyframes drift { from { opacity: 0; } to { opacity: 1; } }',
        ]));

        $this->assertSame([
            'resources/css/components/scene.css:1 2 rules in whole and 1 in part could be @apply, 10 of its 13 declarations: '
                .'.scene__card → flex gap-4 rounded-xl text-ink; .scene__pin → absolute top-1/2 w-9.5 h-9.5; .scene__card → lg:grid lg:py-2 lg:px-8',
        ], $this->found('apply-candidates'), 'a transform, a path and an animation stay CSS; @supports and keyframes are left alone');
    }

    public function test_vendor_overrides_are_read_but_never_reported(): void
    {
        $this->write('resources/views/vendor/statamic/forms/fields/text.antlers.html', '<input class="form__input bg-[#fff]"><img src="{{ x }}">');

        $result = Audit::make($this->root)->run();

        $this->assertSame([], array_filter($result['findings'], fn ($f) => str_starts_with($f['path'], 'resources/views/vendor/')));
    }

    public function test_a_finding_kept_with_its_reason_is_silenced_and_one_without_a_reason_is_not(): void
    {
        $this->write('resources/css/components/hero.css', ".hero__art { color: red; }\n.scene__sky { color: blue; }\n");
        $this->write('resources/css/components/map.css', ".map__pin { color: red; }\n");
        $this->write('resources/site/audit-ignore.yaml', implode("\n", [
            '-',
            '  rule: bem-class',
            '  path: resources/css/components/*.css',
            '  match: hero',
            "  reason: The hero's illustrated scene, keyed as drawn.",
            '-',
            '  rule: bem-class',
            '  path: resources/css/components/',
            '  match: map',
            '-',
            '  rule: bare-img',
            '  path: resources/views/',
            '  reason: Nothing matches this any more.',
            '',
        ]));

        $result = Audit::make($this->root)->run();

        $this->assertSame(['hero: 1 BEM class, .hero__art'], array_column($result['ignored'], 'message'));
        $this->assertSame("The hero's illustrated scene, keyed as drawn.", $result['ignored'][0]['reason']);
        $this->assertSame([
            'bem-class resources/css/components/hero.css scene: 1 BEM class, .scene__sky',
            'bem-class resources/css/components/map.css map: 1 BEM class, .map__pin',
            'ignore-reason resources/site/audit-ignore.yaml entry 2 (bem-class in resources/css/components/) has no reason, so it silences nothing',
        ], array_map(fn ($f) => "{$f['rule']} {$f['path']} {$f['message']}", $result['findings']));
        $this->assertSame(['entry 3 in resources/site/audit-ignore.yaml (bare-img in resources/views/) matches nothing now: remove it'], $result['unused']);
    }

    public function test_paths_narrow_what_is_reported(): void
    {
        $this->write('resources/css/components/hero.css', ".hero__art { color: red; }\n");
        $this->write('resources/css/components/map.css', ".map__pin { color: red; }\n");

        $result = Audit::make($this->root)->run(['resources/css/components/map.css']);

        $this->assertSame(['resources/css/components/map.css'], array_column($result['findings'], 'path'));
    }

    public function test_the_command_is_advisory_unless_strict(): void
    {
        $this->write('resources/css/components/hero.css', ".hero__art { color: red; }\n");

        $this->artisan('avoca:site:audit', ['--root' => $this->root])
            ->expectsOutputToContain('resources/css/components/hero.css:1 hero: 1 BEM class, .hero__art')
            ->assertSuccessful();
        $this->artisan('avoca:site:audit', ['--root' => $this->root, '--strict' => true])->assertFailed();
    }

    public function test_the_command_reports_json(): void
    {
        $this->write('resources/css/components/hero.css', ".hero__art { color: red; }\n");

        $this->artisan('avoca:site:audit', ['--root' => $this->root, '--json' => true])
            ->expectsOutputToContain('"recipe": "recipe.md#styling"')
            ->assertSuccessful();
    }

    /** @return array<int, string> the findings of one rule, as path:line message */
    private function found(string $rule): array
    {
        $findings = array_filter(Audit::make($this->root)->run()['findings'], fn (array $f) => $f['rule'] === $rule);

        return array_values(array_map(fn (array $f) => $f['path'].($f['line'] ? ":{$f['line']}" : '').' '.$f['message'], $findings));
    }

    private function write(string $path, string $contents): void
    {
        $file = "{$this->root}/{$path}";
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $contents);
    }

    /** A small site as the kit and the recipe lay one out. */
    private function cleanSite(): void
    {
        $this->write('resources/css/site.css', "@import \"tailwindcss\" source(none);\n@source \"../views\";\n@source \"../js\";\n@import \"./peak.css\";\n@import \"./theme.css\";\n@import \"./colours.css\" theme(static);\n@import \"./utilities.css\";\n");
        $this->write('resources/css/critical.css', "@import \"tailwindcss\" source(none);\n@import \"./peak.css\";\n@import \"./theme.css\";\n@source \"../views/layout.antlers.html\";\n");
        $this->write('resources/css/peak.css', "@layer components {\n  .fluid-grid { display: grid; column-gap: var(--col-gap); }\n}\n@utility span-full {\n  grid-column: full;\n}\n@utility stack-* {\n  > * { --stack-space: calc(var(--spacing) * --value(number)); }\n}\n");
        $this->write('resources/css/theme.css', "@theme {\n  --text-xl: 1.25rem;\n  --text-xl--line-height: var(--leading-tight);\n  --radius-5xl: 3rem;\n}\n");
        $this->write('resources/css/colours.css', "@theme {\n  --color-ink: #320c42;\n  --color-primary: var(--color-ink);\n  --body-color: var(--color-ink);\n  --btn-primary-bg: var(--color-primary);\n}\n@layer components {\n  .scheme-dark { --body-color: white; }\n}\n");
        $this->write('resources/css/utilities.css', "@layer components {\n  .eyebrow { @apply text-xs tracking-widest uppercase; }\n}\n@custom-variant armed (.js-armed &);\n");
        $this->write('resources/views/layout.antlers.html', "<html>\n<head>{{ stack:head }}</head>\n<body>{{ template_content }}</body>\n</html>\n");
        $this->write('resources/views/page_builder/_faq.antlers.html', implode("\n", [
            '{{#',
            '    @name FAQ',
            '    @desc The FAQ page builder block.',
            '    @set page.page_builder.faq',
            '#}}',
            '',
            '<!-- /page_builder/_faq.antlers.html -->',
            '{{ partial:page_builder/block }}',
            '    {{# question list #}}',
            '    <div class="span-content flex flex-col gap-4" x-data="faq">',
            '        <p class="eyebrow">{{ block:label }}</p>',
            '        <div x-collapse data-faq-answer></div>',
            '    </div>',
            '    {{ partial:components/utilities/figure :image="block:image" sizes="(min-width: 768px) 50vw, 90vw" }}',
            '{{ /partial:page_builder/block }}',
            '<!-- End: /page_builder/_faq.antlers.html -->',
        ]));
        $this->write('resources/views/page_builder/_cards.antlers.html', '<p class="eyebrow text-lg">{{ block:label }}</p>');
        $this->write('resources/js/site.js', "import Alpine from 'alpinejs'\nimport collapse from '@alpinejs/collapse'\nimport faq from './faq'\n\nAlpine.plugin(collapse)\nAlpine.data('faq', faq)\nAlpine.start()\n");
        $this->write('resources/js/faq.js', "export default () => ({\n    open() {\n        this.\$root.querySelector('[data-faq-answer]')?.focus()\n    },\n})\n");
    }
}
