<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Library\Library;
use Avocadesign\StatamicTools\Library\Names;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class NamesTest extends TestCase
{
    private string $base;

    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/avoca-names-'.uniqid();
        $this->write('library/presets/team/item.yaml', "name: Team\nversion: 1.0.0\npage_builder:\n  -\n    handle: team\n    display: Team\n    group: dynamic\n");
        $this->write('library/presets/team/files/content/collections/people.yaml', "title: People\n");
        $this->write('library/presets/team/files/content/collections/people/jo.md', "---\ntitle: Jo\n---\n");
        $this->write('library/presets/team/files/resources/fieldsets/team.yaml', "title: 'Block: Team'\nfields: []\n");
        $this->write('library/sets/quote/item.yaml', "name: Quote\nversion: 1.0.0\narticle_sets:\n  handle: pull_quote_large\n  display: 'Large quote'\n  group: content\n");
        $this->library = new Library(["{$this->base}/library"], "{$this->base}/site");
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
        parent::tearDown();
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname("{$this->base}/{$path}"))) {
            mkdir(dirname("{$this->base}/{$path}"), 0777, true);
        }
        file_put_contents("{$this->base}/{$path}", $contents);
    }

    private function names(array $site = []): Names
    {
        return new Names($this->library, fn () => $site);
    }

    public function test_a_handle_a_library_item_uses_is_a_clash_and_a_close_one_is_similar(): void
    {
        $names = $this->names();

        $this->assertSame([['level' => 'clash', 'item' => 'team', 'name' => 'Team', 'category' => 'presets', 'status' => 'not installed', 'kinds' => ['item', 'block', 'fieldset'], 'handles' => ['team']]], $names->check('team'));
        $this->assertSame([['clash', ['collection'], ['people']]], array_map(fn ($m) => [$m['level'], $m['kinds'], $m['handles']], $names->check('people')), 'a collection file counts');
        $this->assertSame([], $names->check('jo'), 'an entry file does not');
        $this->assertSame([['clash', 'quote', ['set']]], array_map(fn ($m) => [$m['level'], $m['item'], $m['kinds']], $names->check('pull_quote_large')));

        // Case, separators, a plural and the name editors see are similar, never a clash.
        $this->assertSame([['similar', ['item', 'block', 'fieldset']]], array_map(fn ($m) => [$m['level'], $m['kinds']], $names->check('teams')));
        $this->assertSame([['similar', 'quote', ['set']]], array_map(fn ($m) => [$m['level'], $m['item'], $m['kinds']], $names->check('big_quote', 'Large Quotes')));
        $this->assertSame('similar', $names->check('pull-quote-large')[0]['level']);
        $this->assertSame([], $names->check('gallery', 'Gallery'));
    }

    public function test_only_a_clash_with_an_item_the_site_has_not_installed_blocks(): void
    {
        $this->assertCount(1, Names::blocking($this->names()->check('team')));
        $this->assertSame([], Names::blocking($this->names()->check('teams')), 'a similar name never blocks');

        $this->library->record($this->library->find('team'), '2026-09-16');
        $installed = $this->names()->check('team');
        $this->assertSame('installed', $installed[0]['status']);
        $this->assertSame([], Names::blocking($installed), "the item's files are in the site, where the site's own checks stop a collision");
    }

    public function test_the_messages_say_what_the_library_has_and_what_to_do(): void
    {
        $names = $this->names();

        $this->assertSame("Avoca's library already has the Team preset, which has a block and fieldset called team. Install it with php please avoca:library:install team, or choose another handle.", Names::describe($names->check('team')[0]));
        $this->assertSame("Avoca's library has the Team preset, which has a block and fieldset called team, a similar name. Look at it before building your own: php please avoca:library:install team --dry-run", Names::describe($names->check('teams')[0]));
        $this->assertSame("Avoca's library already has the Team preset, which has a collection called people. Install it with php please avoca:library:install team, or choose another handle.", Names::describe($names->check('people')[0]));

        $this->library->record($this->library->find('team'), '2026-09-16');
        $this->assertSame('This site installed the Team preset from Avoca\'s library, which has a block and fieldset called team.', Names::describe($this->names()->check('team')[0]));
    }

    public function test_the_site_names_in_use_and_the_library_clashes_already_in_the_site(): void
    {
        $names = $this->names(['block' => ['team' => 'Team', 'text' => 'Text'], 'collection' => ['people' => 'People'], 'fieldset' => ['team' => null, 'common' => null]]);

        $this->assertSame([['level' => 'clash', 'kind' => 'block', 'handle' => 'team'], ['level' => 'clash', 'kind' => 'fieldset', 'handle' => 'team']], $names->inSite('team'));
        $this->assertSame([['level' => 'similar', 'kind' => 'block', 'handle' => 'text']], $names->inSite('texts'));

        $clashes = $names->siteClashes();
        $this->assertSame([['item' => 'team', 'name' => 'Team', 'category' => 'presets', 'site' => [['kind' => 'block', 'handle' => 'team'], ['kind' => 'collection', 'handle' => 'people'], ['kind' => 'fieldset', 'handle' => 'team']]]], $clashes);
        $this->assertSame("this site's block team, collection people and fieldset team share their handles with the Team preset in Avoca's library, which the site hasn't installed, so installing the preset later would collide", Names::describeSiteClash($clashes[0]));
    }

    public function test_check_name_says_whether_a_handle_is_free_and_exits_non_zero_when_it_is_taken(): void
    {
        $this->app->instance(Names::class, $this->names(['block' => ['text' => 'Text']]));

        $this->assertSame(1, Artisan::call('avoca:check-name', ['handle' => 'team', '--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame([false, 'team', 'clash', []], [$report['available'], $report['handle'], $report['library'][0]['level'], $report['site']]);

        $this->assertSame(1, Artisan::call('avoca:check-name', ['handle' => 'text', '--json' => true]), 'the site already has it');
        $this->assertSame(0, Artisan::call('avoca:check-name', ['handle' => 'teams', '--json' => true]), 'a similar name is still free');
        $this->assertSame(['similar'], array_column(json_decode(Artisan::output(), true)['library'], 'level'));

        $this->assertSame(0, Artisan::call('avoca:check-name', ['handle' => 'awards']));
        $this->assertStringContainsString("awards is free: nothing in Avoca's library or this site uses it or a similar name.", Artisan::output());

        $this->assertSame(1, Artisan::call('avoca:check-name', ['handle' => 'Bad-Name', '--json' => true]));
        $this->assertStringContainsString('must start with a letter', json_decode(Artisan::output(), true)['errors'][0]);
    }
}
