<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Ai\ClaudeCode;
use Avocadesign\StatamicTools\Collections\CollectionFiles;
use Avocadesign\StatamicTools\Collections\CollectionMaker;
use Avocadesign\StatamicTools\Collections\CollectionRecord;
use Avocadesign\StatamicTools\Collections\CollectionSpec;
use Avocadesign\StatamicTools\Permissions\PermissionSets;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Statamic\Facades\Role;
use Statamic\Facades\YAML;

class MakeCollectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/avoca-make-collection-'.uniqid();

        $this->write('resources/fieldsets/page_builder.yaml', "title: 'Block: Page builder'\nfields:\n  -\n    handle: page_builder\n    field:\n      type: replicator\n      sets:\n        Content:\n          display: Content\n          sets:\n            text:\n              display: Text\n              fields:\n                -\n                  import: text\n        dynamic:\n          display: Dynamic\n          sets:\n            form:\n              display: Form\n              fields:\n                -\n                  import: form\n");
        $this->write('resources/fieldsets/colour_scheme.yaml', "title: 'Common: Colour Scheme'\nfields: []\n");
        $this->write('resources/fieldsets/block_hero.yaml', "title: 'Common: Hero'\nfields: []\n");
        $this->write('resources/users/roles.yaml', "editor:\n  title: Editor\n  permissions:\n    - 'access cp'\n");
        $this->write('content/assets/images.yaml', "title: Images\ndisk: images\n");
        Role::path("{$this->root}/resources/users/roles.yaml");
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname("{$this->root}/{$path}"))) {
            mkdir(dirname("{$this->root}/{$path}"), 0777, true);
        }
        file_put_contents("{$this->root}/{$path}", $contents);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    private function yaml(string $path): array
    {
        return (array) YAML::parse($this->read($path));
    }

    private function maker(): CollectionMaker
    {
        return new CollectionMaker($this->root, config('statamic-tools.site'));
    }

    private function frontMatter(string $markdown): array
    {
        preg_match('/\A---\n(.*?)\n---\n/s', $markdown, $m);

        return (array) YAML::parse($m[1] ?? '');
    }

    public function test_a_page_builder_collection_with_urls_uses_the_default_template_and_a_listing_block_in_dynamic(): void
    {
        $spec = new CollectionSpec('News', 'news', '/news/{slug}', true, false, CollectionSpec::PAGE_BUILDER);
        $maker = $this->maker();
        $this->assertSame([], CollectionMaker::blocking($maker->plan($spec)));

        $maker->write($spec, '2026-09-15');

        $collection = $this->yaml('content/collections/news.yaml');
        $this->assertSame(['default', 'layout', '/news/{slug}', true, 'desc'], [$collection['template'], $collection['layout'], $collection['route'], $collection['date'], $collection['sort_dir']]);
        $this->assertArrayNotHasKey('structure', $collection);
        $this->assertFileDoesNotExist("{$this->root}/resources/views/news/show.antlers.html", 'the kit default template renders it');

        $blueprint = $this->yaml('resources/blueprints/collections/news/news.yaml');
        [$general, $hero, $pageBuilder] = $blueprint['tabs']['main']['sections'];
        $this->assertSame(['title'], array_column($general['fields'], 'handle'));
        $this->assertSame([['import' => 'block_hero']], $hero['fields']);
        $this->assertSame([['import' => 'page_builder']], $pageBuilder['fields']);
        $this->assertSame(['main', 'seo', 'sidebar'], array_keys($blueprint['tabs']));
        $this->assertSame(['slug', 'date'], array_column($blueprint['tabs']['sidebar']['sections'][0]['fields'], 'handle'));

        $sets = $this->yaml('resources/fieldsets/page_builder.yaml')['fields'][0]['field']['sets'];
        $this->assertSame(['display' => 'News', 'instructions' => 'Entries from the News collection: all of them, or chosen ones.', 'icon' => 'file-content-list', 'fields' => [['import' => 'news']]], $sets['dynamic']['sets']['news']);
        $this->assertArrayHasKey('form', $sets['dynamic']['sets'], 'existing blocks stay where they are');
        $this->assertArrayHasKey('text', $sets['Content']['sets']);

        $block = $this->yaml('resources/fieldsets/news.yaml')['fields'];
        $this->assertSame(['heading', 'sub_heading', 'source', 'sort', 'limit', 'news', 'display_settings', null], array_map(fn (array $row) => $row['handle'] ?? null, $block));
        $this->assertSame(['import' => 'colour_scheme'], $block[7]);
        $this->assertSame(['All news', 'Chosen news'], array_column($block[2]['field']['options'], 'value'));
        $this->assertSame(['newest', 'oldest', 'title'], array_column($block[3]['field']['options'], 'key'));
        $this->assertSame(['3', false], [$block[4]['field']['default'], $block[5]['field']['create']]);
        $this->assertSame(['news'], $block[5]['field']['collections']);

        $view = $this->read('resources/views/page_builder/_news.antlers.html');
        $this->assertStringContainsString('{ collection:news :sort="news_sort" :limit="news_limit" }', $view);
        $this->assertStringContainsString("(block:sort == 'oldest') => 'date:asc',", $view);
        $this->assertStringContainsString("{{ news_limit = block:limit:value == 'all' ? 0 : (block:limit:value ?? 3) }}", $view);
        $this->assertStringContainsString('<a href="{{ url }}" class="clickable-parent">{{ title }}</a>', $view);
        $this->assertStringContainsString('{{ partial:page_builder/block class="gap-y-8" }}', $view);
        $this->assertStringContainsString("## When to use\n\nDescribe the situations this block is for.", $this->read('resources/site/blocks/news.md'));

        $this->assertSame(['access cp', ...PermissionSets::for('collections', 'news')], $this->yaml('resources/users/roles.yaml')['editor']['permissions']);

        $record = $this->read('resources/site/collections/news.md');
        $this->assertSame(['title' => 'News', 'handle' => 'news', 'route' => '/news/{slug}', 'dated' => true, 'ordering' => 'date', 'blueprint' => 'page_builder', 'listing_block' => 'news', 'editor_access' => true, 'created' => '2026-09-15'], $this->frontMatter($record));
        $this->assertStringNotContainsString('## Blueprint brief', $record);
        $this->assertStringContainsString("## Notes for AI\n\nThe blueprint is complete", $record);
    }

    public function test_a_custom_collection_records_its_brief_and_starts_from_a_minimal_blueprint(): void
    {
        $brief = 'Each person has a photo, name, role, a short bio, an email and a LinkedIn link.';
        $this->maker()->write(new CollectionSpec('People', 'people', '/people/{slug}', false, true, CollectionSpec::CUSTOM, $brief), '2026-09-15');

        $collection = $this->yaml('content/collections/people.yaml');
        $this->assertSame('people/show', $collection['template']);
        $this->assertSame(['root' => false, 'max_depth' => 1], $collection['structure']);
        $this->assertSame('asc', $collection['sort_dir']);

        $blueprint = $this->yaml('resources/blueprints/collections/people/person.yaml');
        $this->assertSame('Person', $blueprint['title']);
        $this->assertSame([['display' => 'General', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text', 'required' => true, 'localizable' => true, 'listable' => true, 'display' => 'Title', 'validate' => ['required']]]]]], $blueprint['tabs']['main']['sections']);
        $this->assertSame(['main', 'seo', 'sidebar'], array_keys($blueprint['tabs']));
        $this->assertSame(['slug'], array_column($blueprint['tabs']['sidebar']['sections'][0]['fields'], 'handle'));
        $this->assertStringNotContainsString('page_builder', $this->read('resources/blueprints/collections/people/person.yaml'));
        $this->assertStringContainsString('{{ partial src="layout/page_header" }}', $this->read('resources/views/people/show.antlers.html'));
        $block = $this->yaml('resources/fieldsets/people.yaml')['fields'];
        $this->assertSame(['order', 'people'], [$block[3]['field']['default'], $block[5]['handle']]);

        $record = $this->read('resources/site/collections/people.md');
        $this->assertSame(['custom', 'manual', '/people/{slug}'], [$this->frontMatter($record)['blueprint'], $this->frontMatter($record)['ordering'], $this->frontMatter($record)['route']]);
        $this->assertStringContainsString("## Blueprint brief\n\n{$brief}\n\n## Notes for AI\n\nFinish the People collection so it matches the Blueprint brief.", $record);
        $this->assertStringContainsString('- `resources/views/people/show.antlers.html`: the page for one entry.', $record);
        $this->assertStringContainsString('- Keep the fields already there: the title, the slug in the sidebar and the SEO tab.', $record);
        $this->assertStringContainsString("asset containers: `images` (Images).", $record);
        $this->assertStringContainsString('`php please avoca:site:catalogue` runs, and then `php please avoca:site:check --strict` passes.', $record);
        $this->assertStringNotContainsString("\u{2014}", $record, 'no em dashes');
    }

    public function test_a_collection_shown_only_in_blocks_has_no_route_slug_seo_or_template(): void
    {
        $spec = new CollectionSpec('Testimonials', 'testimonials', null, false, false, CollectionSpec::CUSTOM, 'Each testimonial has a quote, who said it and their company.');
        $maker = $this->maker();
        $this->assertNotContains('resources/views/testimonials/show.antlers.html', $maker->paths($spec));

        $maker->write($spec, '2026-09-15');

        $collection = $this->yaml('content/collections/testimonials.yaml');
        $this->assertSame(['title' => 'Testimonials', 'revisions' => false, 'slugs' => false, 'sort_dir' => 'asc'], $collection);
        $this->assertSame(['main'], array_keys($this->yaml('resources/blueprints/collections/testimonials/testimonial.yaml')['tabs']));
        $this->assertDirectoryDoesNotExist("{$this->root}/resources/views/testimonials");

        $view = $this->read('resources/views/page_builder/_testimonials.antlers.html');
        $this->assertStringNotContainsString('href=', $view, 'entries without URLs are not linked');
        $this->assertStringContainsString("{{ testimonials_sort = 'title:asc' }}", $view);
        $this->assertStringContainsString("{{ testimonials_limit = (block:limit:value ?? 'all') == 'all' ? 0 : block:limit:value }}", $view);
        $this->assertSame('none', $this->frontMatter($this->read('resources/site/collections/testimonials.md'))['route']);
        $block = $this->yaml('resources/fieldsets/testimonials.yaml')['fields'];
        $this->assertSame(['heading', 'sub_heading', 'source', 'limit', 'testimonials', 'display_settings', null], array_map(fn (array $row) => $row['handle'] ?? null, $block), 'one possible order is no choice');
        $this->assertSame(['all', true], [$block[3]['field']['default'], $block[4]['field']['create']]);
        $this->assertSame('entries', CollectionFiles::entriesField(new CollectionSpec('Sources', 'source', null, false, false, CollectionSpec::PAGE_BUILDER)));
    }

    public function test_an_existing_block_or_file_stops_the_make_before_anything_is_written(): void
    {
        $pageBuilder = $this->read('resources/fieldsets/page_builder.yaml');
        $roles = $this->read('resources/users/roles.yaml');
        $maker = $this->maker();

        // The page builder already has a block called form.
        $form = new CollectionSpec('Form', 'form', '/form/{slug}', false, false, CollectionSpec::PAGE_BUILDER);
        try {
            $maker->write($form, '2026-09-15');
            $this->fail('the make should stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('the page builder already has a block called form', $e->getMessage());
        }
        $this->assertFileDoesNotExist("{$this->root}/content/collections/form.yaml");
        $this->assertDirectoryDoesNotExist("{$this->root}/resources/site");

        // A collection file already exists.
        $this->write('content/collections/people.yaml', "title: People\n");
        $people = new CollectionSpec('People', 'people', '/people/{slug}', false, false, CollectionSpec::PAGE_BUILDER);
        $this->assertSame([['action' => 'create', 'target' => 'content/collections/people.yaml', 'status' => 'exists', 'detail' => '']], CollectionMaker::blocking($maker->plan($people)));
        try {
            $maker->write($people, '2026-09-15');
            $this->fail('the make should stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('content/collections/people.yaml already exists', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist("{$this->root}/resources/blueprints");
        $this->assertFileDoesNotExist("{$this->root}/resources/fieldsets/people.yaml");
        $this->assertSame($pageBuilder, $this->read('resources/fieldsets/page_builder.yaml'), 'the page builder is untouched');
        $this->assertSame($roles, $this->read('resources/users/roles.yaml'), 'the roles are untouched');
        $this->assertSame("title: People\n", $this->read('content/collections/people.yaml'));
    }

    public function test_a_collection_with_a_library_items_handle_stops_unless_the_clash_is_meant(): void
    {
        $this->app->instance(CollectionMaker::class, $this->maker());
        $options = ['--title' => 'Testimonials', '--no-route' => true, '--no-catalogue' => true, '--json' => true];

        $this->assertSame(1, Artisan::call('avoca:make:collection', $options), 'the addon library has a Testimonials preset');
        $stopped = json_decode(Artisan::output(), true);
        $this->assertStringStartsWith("Avoca's library already has the Testimonials preset", $stopped['errors'][0]);
        $this->assertSame('To make this collection anyway, choose another handle with --handle, or pass --ignore-library.', $stopped['errors'][1]);
        $this->assertFileDoesNotExist("{$this->root}/content/collections/testimonials.yaml");

        $this->assertSame(0, Artisan::call('avoca:make:collection', [...$options, '--ignore-library' => true]));
        $made = json_decode(Artisan::output(), true);
        $this->assertTrue($made['ok'] && $made['written']);
        $this->assertSame(['clash', 'testimonials'], [$made['library'][0]['level'], $made['library'][0]['item']]);
        $this->assertFileExists("{$this->root}/content/collections/testimonials.yaml");
    }

    public function test_the_command_runs_from_options_alone_and_reports_json(): void
    {
        $this->app->instance(CollectionMaker::class, $this->maker());
        $options = ['--title' => 'Team members', '--ordered' => true, '--blueprint' => 'custom', '--description' => 'Each member has a photo and a role.', '--no-catalogue' => true, '--json' => true];

        $this->assertSame(0, Artisan::call('avoca:make:collection', [...$options, '--dry-run' => true]));
        $dry = json_decode(Artisan::output(), true);
        $this->assertSame([true, false, 'team_members', '/team-members/{slug}'], [$dry['dry_run'], $dry['written'], $dry['collection']['handle'], $dry['collection']['route']]);
        $this->assertFileDoesNotExist("{$this->root}/content/collections/team_members.yaml", 'a dry run writes nothing');

        $this->assertSame(0, Artisan::call('avoca:make:collection', $options));
        $made = json_decode(Artisan::output(), true);
        $this->assertTrue($made['ok'] && $made['written']);
        $this->assertSame('resources/site/collections/team_members.md', $made['record']);
        $this->assertSame('Next, ask your AI agent: build the blueprint described in resources/site/collections/team_members.md', $made['next']);
        $this->assertFileExists("{$this->root}/resources/site/collections/team_members.md");

        $this->assertSame(1, Artisan::call('avoca:make:collection', $options), 'a second run stops');
        $this->assertContains('content/collections/team_members.yaml already exists', json_decode(Artisan::output(), true)['errors']);

        // Editors get access unless --no-editor-access opts the collection out.
        $this->assertContains('view team_members entries', $this->yaml('resources/users/roles.yaml')['editor']['permissions']);
        $this->assertSame(0, Artisan::call('avoca:make:collection', ['--title' => 'Awards', '--no-route' => true, '--no-block' => true, '--no-editor-access' => true, '--no-catalogue' => true, '--json' => true]));
        $awards = json_decode(Artisan::output(), true);
        $this->assertFalse($awards['collection']['editor_access']);
        $this->assertNotContains('permissions', array_column($awards['steps'], 'action'));
        $this->assertNotContains('view awards entries', $this->yaml('resources/users/roles.yaml')['editor']['permissions']);
        $this->assertFalse($this->frontMatter($this->read('resources/site/collections/awards.md'))['editor_access']);

        $this->assertSame(1, Artisan::call('avoca:make:collection', ['--title' => 'Staff', '--blueprint' => 'custom', '--json' => true]));
        $this->assertSame(['A custom blueprint needs a description of the fields each entry has.'], json_decode(Artisan::output(), true)['errors']);
    }

    public function test_a_person_is_asked_each_question_in_turn(): void
    {
        $this->app->instance(CollectionMaker::class, $this->maker());

        $this->artisan('avoca:make:collection', ['--no-catalogue' => true])
            ->expectsQuestion('What is the collection called?', 'Case studies')
            ->expectsQuestion('What is its handle?', 'case_studies')
            ->expectsConfirmation('Does each entry get its own page, with its own URL?', 'yes')
            ->expectsQuestion('What is the URL of an entry?', '/work/{slug}')
            ->expectsConfirmation('Are entries dated?', 'yes')
            ->expectsConfirmation('Do editors put entries in order by hand?', 'no')
            ->expectsQuestion('How is an entry built?', 'custom')
            ->expectsQuestion('Describe the fields each entry has.', 'A client, a summary and three photos.')
            ->expectsConfirmation('Add a block that lists these entries?', 'yes')
            ->expectsOutputToContain('Next, ask your AI agent: build the blueprint described in resources/site/collections/case_studies.md')
            ->assertExitCode(0);

        $this->assertSame(
            ['title' => 'Case studies', 'handle' => 'case_studies', 'route' => '/work/{slug}', 'dated' => true, 'ordering' => 'date', 'blueprint' => 'custom', 'listing_block' => 'case_studies', 'editor_access' => true],
            array_diff_key($this->frontMatter($this->read('resources/site/collections/case_studies.md')), ['created' => true]),
        );
        $this->assertSame(['access cp', ...PermissionSets::for('collections', 'case_studies')], $this->yaml('resources/users/roles.yaml')['editor']['permissions'], 'editors get access without being asked');
        $this->assertFileExists("{$this->root}/resources/blueprints/collections/case_studies/case_study.yaml");
    }

    public function test_editor_access_follows_the_opt_outs_and_a_missing_role_never_stops_the_make(): void
    {
        $maker = $this->maker();

        // Listed in the opt-outs file: the collection is made and the role gets nothing.
        $this->write('resources/site/editor-access.yaml', "collections:\n  - awards\n");
        $awards = new CollectionSpec('Awards', 'awards', null, false, false, CollectionSpec::PAGE_BUILDER, '', false);
        $this->assertSame(
            ['action' => 'permissions', 'target' => 'editor', 'status' => 'skipped', 'detail' => 'the collection is opted out in resources/site/editor-access.yaml'],
            collect($maker->plan($awards))->firstWhere('action', 'permissions'),
        );
        $maker->write($awards, '2026-09-15');
        $this->assertFileExists("{$this->root}/content/collections/awards.yaml");
        $this->assertSame(['access cp'], $this->yaml('resources/users/roles.yaml')['editor']['permissions']);

        // A site with no editor role still gets its collection, and its roles are left as they are.
        $roles = "marketeer:\n  title: Marketeer\n  permissions:\n    - 'access cp'\n";
        $this->write('resources/users/roles.yaml', $roles);
        $events = new CollectionSpec('Events', 'events', '/events/{slug}', true, false, CollectionSpec::PAGE_BUILDER, '', false);
        $this->assertSame([], CollectionMaker::blocking($maker->plan($events)));
        $maker->write($events, '2026-09-15');
        $this->assertFileExists("{$this->root}/content/collections/events.yaml");
        $this->assertSame($roles, $this->read('resources/users/roles.yaml'));

        // A broken opt-outs file stops the make before anything is written, since it can't tell what is opted out.
        $this->write('resources/users/roles.yaml', "editor:\n  title: Editor\n  permissions:\n    - 'access cp'\n");
        $this->write('resources/site/editor-access.yaml', "collections: people\n");
        try {
            $maker->write(new CollectionSpec('People', 'people', null, false, false, CollectionSpec::PAGE_BUILDER, '', false), '2026-09-15');
            $this->fail('the make should stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('permissions editor: resources/site/editor-access.yaml: collections must be a list of handles.', $e->getMessage());
        }
        $this->assertFileDoesNotExist("{$this->root}/content/collections/people.yaml");
    }

    public function test_claude_code_is_allowed_only_the_collection_files_and_the_two_checks(): void
    {
        $spec = new CollectionSpec('People', 'people', '/people/{slug}', false, false, CollectionSpec::CUSTOM, 'A photo and a role.');
        $command = ClaudeCode::command('Read the record.', $this->maker()->editable($spec), CollectionRecord::CHECK_COMMANDS);

        $this->assertSame(['claude', '-p', 'Read the record.', '--restricted'], array_slice($command, 0, 4));
        $this->assertSame('dontAsk', $command[array_search('--permission-mode', $command, true) + 1]);
        $allowed = array_slice($command, array_search('--allowedTools', $command, true) + 1, -1);
        $this->assertSame([
            'Read', 'Glob', 'Grep',
            'Edit(resources/blueprints/collections/people/person.yaml)',
            'Edit(resources/views/people/show.antlers.html)',
            'Edit(resources/fieldsets/people.yaml)',
            'Edit(resources/views/page_builder/_people.antlers.html)',
            'Edit(resources/site/blocks/people.md)',
            'Edit(resources/site/collections/people.md)',
            'Bash(php please avoca:site:catalogue)',
            'Bash(php please avoca:site:check --strict)',
        ], $allowed);
        $this->assertSame('--strict-mcp-config', end($command));
    }

    public function test_the_ai_handoff_never_starts_without_a_confirmation(): void
    {
        Process::fake(['command -v claude' => Process::result("/usr/local/bin/claude\n"), '*' => Process::result()]);
        $this->app->instance(CollectionMaker::class, $this->maker());

        $code = Artisan::call('avoca:make:collection', ['--title' => 'People', '--blueprint' => 'custom', '--description' => 'A photo and a role.', '--no-catalogue' => true, '--ai' => true, '--no-interaction' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Claude Code was not run: --ai needs you to confirm, and this run cannot ask.', Artisan::output());
        Process::assertRan('command -v claude');
        Process::assertDidntRun(fn ($process) => is_array($process->command) && in_array('-p', $process->command, true));
    }

    public function test_claude_code_is_only_available_when_command_v_finds_it(): void
    {
        Process::fake(['command -v claude' => Process::result('', '', 1)]);
        $this->assertFalse(ClaudeCode::available());
    }
}
