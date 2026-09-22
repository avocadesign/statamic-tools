<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Library\Library;
use Avocadesign\StatamicTools\Permissions\EditorAccess;
use Avocadesign\StatamicTools\Permissions\PermissionSets;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\YAML;
use Statamic\Statamic;

class EditorAccessTest extends TestCase
{
    /** The kit's shape: an editor role with preferences, and another role that updates to the editor role leave alone. */
    private const ROLES = <<<'YML'
        editor:
          title: Editor
          permissions:
            - 'access cp'
          preferences:
            nav:
              content:
                'content::assets::page_builder': '@hide'
        marketeer:
          title: Marketeer
          permissions:
            - 'access cp'
            - 'edit seo globals'

        YML;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/avoca-editor-access-'.uniqid();

        // A temporary site: Statamic keeps its structures and roles here, and editor access reads its opt-outs here.
        foreach (['collections', 'taxonomies', 'navigation', 'globals', 'global-variables', 'asset-containers', 'collection-trees', 'nav-trees', 'entries', 'terms'] as $store) {
            Stache::store($store)->directory("{$this->root}/content/{$store}");
        }
        $this->write('resources/users/roles.yaml', self::ROLES);
        Role::path("{$this->root}/resources/users/roles.yaml");
        $this->app->instance(EditorAccess::class, EditorAccess::make($this->root, config('statamic-tools.site')));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
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

    /** @return array<int, string> the editor role's permissions, as roles.yaml has them */
    private function editorPermissions(): array
    {
        return (array) (YAML::parse($this->read('resources/users/roles.yaml'))['editor']['permissions'] ?? []);
    }

    private function problemCount(string $output): int
    {
        return preg_match('/(\d+) problem\(s\)/', $output, $match) ? (int) $match[1] : 0;
    }

    public function test_each_permission_set_is_every_permission_statamic_registers_for_its_type(): void
    {
        $registry = Permission::boot()->all();
        $flatten = function ($permission) use (&$flatten): array {
            return [$permission->originalValue(), ...$permission->children()->flatMap(fn ($child) => $flatten($child))->all()];
        };
        $roots = [
            'collections' => 'view {collection} entries',
            'taxonomies' => 'view {taxonomy} terms',
            'navigation' => 'view {nav} nav',
            'globals' => 'edit {global} globals',
            'assets' => 'view {container} assets',
        ];
        foreach ($roots as $type => $root) {
            $this->assertSame($flatten($registry->get($root)), PermissionSets::PERMISSIONS[$type], "{$type} is {$root} and every permission under it, in order");
        }

        // Every permission Statamic has for one of these structures is in a set, and nothing else is.
        $registered = $registry->keys()->filter(fn (string $key) => preg_match('/\{(collection|taxonomy|nav|global|container)\}/', $key))->sort()->values()->all();
        $this->assertSame($registered, collect(PermissionSets::PERMISSIONS)->flatten()->sort()->values()->all());

        // A collection gets exactly what the kit gives pages, other authors included.
        $this->assertSame([
            'view pages entries', 'edit pages entries', 'create pages entries', 'delete pages entries', 'publish pages entries', 'reorder pages entries',
            'edit other authors pages entries', 'publish other authors pages entries', 'delete other authors pages entries',
        ], PermissionSets::for('collections', 'pages'));
        $this->assertSame(['view main nav', 'edit main nav'], PermissionSets::for('navigation', 'main'));
    }

    public function test_a_structure_statamic_creates_gives_the_editor_role_its_permissions_on_core(): void
    {
        Exceptions::fake();
        $this->assertFalse((bool) Statamic::pro(), 'the site runs Statamic Core');

        Collection::make('news')->title('News')->save();
        Taxonomy::make('tags')->title('Tags')->save();
        Nav::make('footer')->title('Footer')->save();
        GlobalSet::make('contact')->title('Contact')->save();
        AssetContainer::make('photos')->title('Photos')->disk('local')->save();

        $this->assertSame([
            'access cp',
            ...PermissionSets::for('collections', 'news'),
            ...PermissionSets::for('taxonomies', 'tags'),
            ...PermissionSets::for('navigation', 'footer'),
            ...PermissionSets::for('globals', 'contact'),
            ...PermissionSets::for('assets', 'photos'),
        ], $this->editorPermissions());
        $roles = YAML::parse($this->read('resources/users/roles.yaml'));
        $this->assertSame(YAML::parse(self::ROLES)['editor']['preferences'], $roles['editor']['preferences'], 'the role keeps its preferences');
        $this->assertSame(YAML::parse(self::ROLES)['marketeer'], $roles['marketeer'], 'other roles are left alone');

        // Saving a structure that already exists never puts back a permission someone took away.
        Role::find('editor')->removePermission('delete news entries')->save();
        Collection::find('news')->title('Latest news')->save();
        $this->assertNotContains('delete news entries', $this->editorPermissions());
        Exceptions::assertNothingReported();
    }

    public function test_an_opted_out_structure_is_never_given_permissions_and_keeps_what_it_has(): void
    {
        $this->write('resources/site/editor-access.yaml', "# Kept away from editors.\nglobals:\n  - seo\nassets:\n  - favicons\n");
        $this->write('resources/site/collections/awards.md', "---\ntitle: Awards\nhandle: awards\neditor_access: false\n---\n# Awards\n");
        $this->write('resources/users/roles.yaml', str_replace("    - 'access cp'\n  preferences", "    - 'access cp'\n    - 'view favicons assets'\n  preferences", self::ROLES));
        $before = $this->read('resources/users/roles.yaml');

        GlobalSet::make('seo')->title('SEO')->save();
        AssetContainer::make('favicons')->title('Favicons')->disk('local')->save();
        Collection::make('awards')->title('Awards')->save();
        $this->assertSame($before, $this->read('resources/users/roles.yaml'), 'creating them adds nothing');

        $this->assertSame(0, Artisan::call('avoca:site:permissions'));
        $output = Artisan::output();
        $this->assertStringContainsString('- collection awards: opted out in resources/site/collections/awards.md', $output);
        $this->assertStringContainsString('- global set seo: opted out in resources/site/editor-access.yaml', $output);
        $this->assertStringContainsString('- asset container favicons: opted out in resources/site/editor-access.yaml, keeping the 1 permission it has', $output);
        $this->assertStringContainsString('The editor role already has every permission it needs.', $output);
        $this->assertSame($before, $this->read('resources/users/roles.yaml'), 'the sync adds nothing for them and removes nothing');
    }

    public function test_the_dry_run_lists_what_the_sync_would_add_and_writes_nothing(): void
    {
        Collection::make('news')->title('News')->save();
        Nav::make('footer')->title('Footer')->save();
        // As if the structures had arrived as files, from git or written by hand: the role has none of their permissions.
        $this->write('resources/users/roles.yaml', self::ROLES);
        $before = $this->read('resources/users/roles.yaml');

        $this->assertSame(0, Artisan::call('avoca:site:permissions', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString("The editor role's permissions for the site's 2 structures:", $output);
        $this->assertStringContainsString('+ collection news: add '.implode(', ', PermissionSets::for('collections', 'news'))."\n", $output);
        $this->assertStringContainsString("+ navigation footer: add view footer nav, edit footer nav\n", $output);
        $this->assertStringContainsString('Dry run: nothing was written. The real run would add 11 permissions to the editor role.', $output);
        $this->assertSame($before, $this->read('resources/users/roles.yaml'), 'a dry run writes nothing');

        $this->assertSame(0, Artisan::call('avoca:site:permissions'));
        $this->assertStringContainsString('Added 11 permissions to the editor role.', Artisan::output());
        $this->assertSame(['access cp', ...PermissionSets::for('collections', 'news'), ...PermissionSets::for('navigation', 'footer')], $this->editorPermissions());

        $this->assertSame(0, Artisan::call('avoca:site:permissions', ['--dry-run' => true]));
        $this->assertStringContainsString('= collection news: has every permission', Artisan::output());
    }

    public function test_without_an_editor_role_or_with_a_broken_opt_outs_file_nothing_is_added_and_nothing_stops(): void
    {
        Exceptions::fake();
        $roles = "marketeer:\n  title: Marketeer\n  permissions:\n    - 'access cp'\n";
        $this->write('resources/users/roles.yaml', $roles);
        Collection::make('news')->title('News')->save();
        $this->assertNotNull(Collection::find('news'), 'the collection is created');
        $this->assertSame($roles, $this->read('resources/users/roles.yaml'), 'with no editor role the listener skips quietly');
        $this->assertSame(1, Artisan::call('avoca:site:permissions', ['--dry-run' => true]));
        $this->assertStringContainsString('The site has no editor role, so nothing was added.', Artisan::output());

        $this->write('resources/users/roles.yaml', self::ROLES);
        $this->write('resources/site/editor-access.yaml', "globals: seo\n");
        Taxonomy::make('tags')->title('Tags')->save();
        $this->assertSame(self::ROLES, $this->read('resources/users/roles.yaml'), 'with a broken opt-outs file the listener adds nothing');
        $this->assertSame(1, Artisan::call('avoca:site:permissions'));
        $this->assertStringContainsString('resources/site/editor-access.yaml: globals must be a list of handles.', Artisan::output());
        $this->assertSame(self::ROLES, $this->read('resources/users/roles.yaml'));
        Exceptions::assertNothingReported();
    }

    public function test_the_structures_a_set_of_files_sets_up_come_from_their_settings_files(): void
    {
        $this->assertSame(
            [['collections', 'faq'], ['navigation', 'footer'], ['globals', 'opening_hours'], ['assets', 'downloads'], ['taxonomies', 'topics']],
            EditorAccess::structuresIn([
                'content/collections/faq.yaml',
                'content/collections/faq/189273f4-09dd-4364-a8d3-30e3ad278676.md',
                'content/trees/collections/faq.yaml',
                'content/navigation/footer.yaml',
                'content/trees/navigation/footer.yaml',
                'content/globals/opening_hours.yaml',
                'content/globals/default/opening_hours.yaml',
                'content/assets/downloads.yaml',
                'content/taxonomies/topics.yaml',
                'content/taxonomies/topics/news.yaml',
                'resources/blueprints/collections/faq/faq.yaml',
            ]),
        );
    }

    public function test_a_library_install_gives_the_editor_role_what_it_adds_and_keeps_the_items_own_permissions(): void
    {
        $this->write('library/presets/team/item.yaml', "name: Team\nversion: 1.0.0\npermissions:\n  editor:\n    - 'view contact form submissions'\n    - 'view team entries'\n");
        $this->write('library/presets/team/files/content/collections/team.yaml', "title: Team\n");
        $this->write('library/presets/team/files/content/navigation/team_links.yaml', "title: 'Team links'\n");
        $this->app->instance(Library::class, new Library(["{$this->root}/library"], $this->root));
        $collection = PermissionSets::for('collections', 'team');

        $this->assertSame(0, Artisan::call('avoca:library:install', ['handle' => 'team', '--dry-run' => true, '--no-catalogue' => true]));
        $dry = Artisan::output();
        $this->assertStringContainsString('+ editor role for collection team: add '.implode(', ', array_slice($collection, 1))." (it has 1 of 9)\n", $dry, "the item's own permission counts as there");
        $this->assertStringContainsString("+ editor role for navigation team_links: add view team_links nav, edit team_links nav\n", $dry);
        $this->assertSame(self::ROLES, $this->read('resources/users/roles.yaml'), 'a dry run writes nothing');

        // The installer writes roles.yaml itself, after the dry run above read the role.
        $this->assertSame(0, Artisan::call('avoca:library:install', ['handle' => 'team', '--no-catalogue' => true]));
        $this->assertStringContainsString("+ editor role for navigation team_links: add view team_links nav, edit team_links nav\n", Artisan::output());
        $this->assertSame(
            ['access cp', 'view contact form submissions', ...$collection, ...PermissionSets::for('navigation', 'team_links')],
            $this->editorPermissions(),
        );
    }

    public function test_site_check_warns_about_missing_editor_permissions_and_strict_never_fails_on_them(): void
    {
        Collection::make('news')->title('News')->save();
        $this->write('resources/users/roles.yaml', self::ROLES);
        $this->write('resources/site/editor-access.yaml', "globals:\n  - seoo\n");

        Artisan::call('avoca:site:check', ['--strict' => true]);
        $missing = Artisan::output();
        $this->assertStringContainsString('! the editor role is missing 9 permissions for collection news: run php please avoca:site:permissions, or opt it out in resources/site/editor-access.yaml', $missing);
        $this->assertStringContainsString('! resources/site/editor-access.yaml opts out global set seoo, which the site does not have', $missing);

        Artisan::call('avoca:site:permissions');
        $this->write('resources/site/editor-access.yaml', "globals: []\n");
        Artisan::call('avoca:site:check', ['--strict' => true]);
        $complete = Artisan::output();
        $this->assertStringContainsString('✓ the editor role has the permissions for 1 structure', $complete);
        $this->assertStringNotContainsString('! the editor role', $complete);
        $this->assertSame($this->problemCount($complete), $this->problemCount($missing), 'missing permissions are warnings, never problems');
    }
}
