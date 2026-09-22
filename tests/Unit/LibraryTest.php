<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Library\Installer;
use Avocadesign\StatamicTools\Library\Item;
use Avocadesign\StatamicTools\Library\Library;
use Avocadesign\StatamicTools\Library\SampleImages;
use Avocadesign\StatamicTools\Site\Samples;
use Avocadesign\StatamicTools\Tests\TestCase;
use Statamic\Facades\YAML;

class LibraryTest extends TestCase
{
    /** A sample entry that marks its featured image and two gallery images, one unquoted with a comment. */
    private const SAMPLE_ENTRY = <<<'MD'
        ---
        id: 6531f956-867b-4469-b318-9ae6b240c526
        title: 'A sample project'
        featured_image: 'avoca:sample-image'
        page_builder:
          -
            id: vtwpcm2h
            type: gallery
            images:
              - 'avoca:sample-image'
              - avoca:sample-image # unquoted, with a comment
            enabled: true
        ---
        The body mentions avoca:sample-image and keeps it.

        MD;

    private string $base;

    private string $root;

    private string $lib;

    private string $shots;

    private string $images;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/avoca-library-'.uniqid();
        $this->root = "{$this->base}/site";
        $this->lib = "{$this->base}/library";
        $this->shots = "{$this->root}/public/page_builder";
        $this->images = "{$this->root}/public/images";

        $this->write("{$this->root}/resources/fieldsets/page_builder.yaml", "title: 'Block: Page builder'\nfields:\n  -\n    handle: page_builder\n    field:\n      type: replicator\n      sets:\n        Content:\n          display: Content\n          sets:\n            text:\n              display: Text\n              fields:\n                -\n                  import: text\n        dynamic:\n          display: Dynamic\n          sets: {  }\n");
        $this->write("{$this->root}/resources/users/roles.yaml", "editor:\n  title: Editor\n  permissions:\n    - 'access cp'\n");

        $item = "{$this->lib}/blocks/team";
        $this->write("{$item}/item.yaml", "name: Team\nversion: 1.2.0\ndescription: People in a grid.\npage_builder:\n  handle: team\n  group: dynamic\n  icon: users\npermissions:\n  editor:\n    - 'view people entries'\n");
        $this->write("{$item}/files/resources/fieldsets/team.yaml", "title: 'Block: Team'\nfields: []\n");
        $this->write("{$item}/files/resources/views/page_builder/_team.antlers.html", "<div>Team</div>\n");
        $this->write("{$item}/files/resources/site/blocks/team.md", "---\ntitle: Team\n---\n");
        $this->write("{$item}/screenshots/team.jpeg", 'jpeg bytes');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
        parent::tearDown();
    }

    private function write(string $file, string $contents): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    private function library(): Library
    {
        return new Library([$this->lib], $this->root);
    }

    private function statuses(array $plan): array
    {
        return array_values(array_unique(array_column($plan, 'status')));
    }

    /** The projects preset: a sample entry that marks its images, and a file outside content/ that mentions the marker. */
    private function projectsPreset(string $entry = self::SAMPLE_ENTRY): Item
    {
        $preset = "{$this->lib}/presets/projects";
        $this->write("{$preset}/item.yaml", "name: Projects\nversion: 1.0.0\n");
        $this->write("{$preset}/files/content/collections/projects/a-sample-project.md", $entry);
        $this->write("{$preset}/files/resources/site/blocks/projects.md", "Sample entries mark an image with featured_image: 'avoca:sample-image'\n");

        return $this->library()->find('projects');
    }

    private function installer(): Installer
    {
        return new Installer($this->library(), $this->shots, sampleImages: new SampleImages($this->images));
    }

    /** An image in the site's images container: a real PNG, or with $meta a file whose size is only in Statamic's metadata. */
    private function image(string $path, int $width, int $height, bool $meta = false): void
    {
        $file = "{$this->images}/{$path}";
        $this->write($file, 'not image bytes');
        if ($meta) {
            $this->write(dirname($file).'/.meta/'.basename($file).'.yaml', "data: {}\nwidth: {$width}\nheight: {$height}\n");
        } else {
            imagepng(imagecreatetruecolor($width, $height), $file);
        }
    }

    private function entryStep(array $plan): ?array
    {
        return collect($plan)->firstWhere('target', 'content/collections/projects/a-sample-project.md');
    }

    private function installedEntry(): string
    {
        return (string) file_get_contents("{$this->root}/content/collections/projects/a-sample-project.md");
    }

    public function test_it_lists_items_with_their_status(): void
    {
        $library = $this->library();
        $team = $library->find('team');

        $this->assertSame(['team'], array_keys($library->items()));
        $this->assertSame('blocks', $team->category);
        $this->assertSame('not installed', $library->status($team));
        $this->assertSame([['handle' => 'team', 'display' => 'Team', 'group' => 'dynamic', 'instructions' => '', 'icon' => 'users', 'import' => 'team']], $team->blocks());
    }

    public function test_installing_copies_files_adds_the_block_to_its_group_and_records_it(): void
    {
        $library = $this->library();
        $team = $library->find('team');
        $installer = new Installer($library, $this->shots);
        $this->assertSame(['new'], $this->statuses($installer->plan($team)));

        $installer->install($team, false, '2026-09-15');

        $this->assertFileExists("{$this->root}/resources/views/page_builder/_team.antlers.html");
        $this->assertFileExists("{$this->root}/resources/site/blocks/team.md");
        $this->assertFileExists("{$this->shots}/team.jpeg");
        $sets = YAML::parse(file_get_contents("{$this->root}/resources/fieldsets/page_builder.yaml"))['fields'][0]['field']['sets'];
        $this->assertSame(['display' => 'Team', 'icon' => 'users', 'image' => 'team.jpeg', 'fields' => [['import' => 'team']]], $sets['dynamic']['sets']['team']);
        $this->assertArrayHasKey('text', $sets['Content']['sets'], 'existing blocks stay where they are');
        $this->assertContains('view people entries', YAML::parse(file_get_contents("{$this->root}/resources/users/roles.yaml"))['editor']['permissions']);
        $this->assertSame(['team' => ['version' => '1.2.0', 'installed' => '2026-09-15', 'category' => 'blocks']], $library->installed());
        $this->assertSame('installed', $library->status($team));
        $this->assertSame(['same'], $this->statuses($installer->plan($team)), 'installing again would change nothing');
    }

    public function test_a_file_that_differs_stops_the_install_unless_forced(): void
    {
        $this->write("{$this->root}/resources/views/page_builder/_team.antlers.html", "<div>Edited on this site</div>\n");
        $library = $this->library();
        $team = $library->find('team');
        $installer = new Installer($library, $this->shots);

        try {
            $installer->install($team);
            $this->fail('the install should stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('resources/views/page_builder/_team.antlers.html', $e->getMessage());
        }
        $this->assertFileDoesNotExist("{$this->root}/resources/fieldsets/team.yaml", 'nothing is written when the install stops');

        $installer->install($team, true);
        $this->assertSame("<div>Team</div>\n", file_get_contents("{$this->root}/resources/views/page_builder/_team.antlers.html"));
    }

    public function test_a_block_needs_a_group(): void
    {
        $this->write("{$this->lib}/blocks/team/item.yaml", "name: Team\npage_builder:\n  handle: team\n");
        $library = $this->library();
        $plan = (new Installer($library, $this->shots))->plan($library->find('team'));

        $this->assertSame(['action' => 'block', 'target' => 'team', 'status' => 'error', 'detail' => 'item.yaml gives it no group'], collect($plan)->firstWhere('action', 'block'));
    }

    public function test_sample_images_use_the_largest_image_named_placeholder(): void
    {
        $this->image('a/placeholder-wide.png', 1600, 900);
        $this->image('b/My-PLACEHOLDER.jpg', 2400, 1600, meta: true);
        $this->image('c/larger-but-not-named.jpg', 4000, 3000, meta: true);
        $projects = $this->projectsPreset();
        $installer = $this->installer();

        $this->assertSame(
            ['action' => 'copy', 'target' => 'content/collections/projects/a-sample-project.md', 'status' => 'new', 'detail' => 'featured_image and images use b/My-PLACEHOLDER.jpg (2400 × 1600), the largest image named placeholder'],
            $this->entryStep($installer->plan($projects)),
        );
        $installer->install($projects);

        $this->assertSame(<<<'MD'
            ---
            id: 6531f956-867b-4469-b318-9ae6b240c526
            title: 'A sample project'
            featured_image: 'b/My-PLACEHOLDER.jpg'
            page_builder:
              -
                id: vtwpcm2h
                type: gallery
                images:
                  - 'b/My-PLACEHOLDER.jpg'
                  - 'b/My-PLACEHOLDER.jpg'
                enabled: true
            ---
            The body mentions avoca:sample-image and keeps it.

            MD, $this->installedEntry());
        $this->assertFileEquals("{$this->lib}/presets/projects/files/resources/site/blocks/projects.md", "{$this->root}/resources/site/blocks/projects.md", 'only files under content/ have their markers replaced');
        $this->assertFileDoesNotExist("{$this->images}/a/.meta/placeholder-wide.png.yaml", 'reading an image size writes nothing to the container');
    }

    public function test_without_a_placeholder_sample_images_use_the_largest_image_big_enough(): void
    {
        $this->image('square.png', 1150, 1150);
        $this->image('photos/tall.jpg', 900, 1300, meta: true);
        $this->image('photos/wide.png', 1200, 600);
        $this->write("{$this->images}/placeholder.svg", '<svg xmlns="http://www.w3.org/2000/svg" width="4000" height="4000"/>');
        $projects = $this->projectsPreset();

        $this->assertSame(
            'featured_image and images use photos/tall.jpg (900 × 1300), the largest image, as none is named placeholder',
            $this->entryStep($this->installer()->plan($projects))['detail'],
            'square.png has the most pixels but is short of 1200 on its long side, and an SVG does not count as an image',
        );
        $this->installer()->install($projects);
        $this->assertStringContainsString("\nfeatured_image: 'photos/tall.jpg'\n", $this->installedEntry());
    }

    public function test_without_an_image_big_enough_the_sample_image_fields_are_left_out(): void
    {
        $this->image('small.png', 800, 600);
        $projects = $this->projectsPreset();
        $installer = $this->installer();

        $this->assertSame(
            'featured_image and images left out, as no image in the images container is named placeholder or is at least 1200 pixels on its long side',
            $this->entryStep($installer->plan($projects))['detail'],
        );
        $installer->install($projects);

        $this->assertSame(<<<'MD'
            ---
            id: 6531f956-867b-4469-b318-9ae6b240c526
            title: 'A sample project'
            page_builder:
              -
                id: vtwpcm2h
                type: gallery
                images:
                enabled: true
            ---
            The body mentions avoca:sample-image and keeps it.

            MD, $this->installedEntry());
        $this->assertSame(
            'featured_image left out, as the site has no images asset container',
            (new SampleImages(null))->apply("featured_image: 'avoca:sample-image'\n")['detail'],
        );
    }

    public function test_installing_again_compares_the_file_as_written_so_nothing_conflicts(): void
    {
        $this->image('temp/placeholder-wepb-image.webp', 2070, 1563, meta: true);
        $projects = $this->projectsPreset();
        $installer = $this->installer();
        $installer->install($projects);
        $written = $this->installedEntry();

        $this->assertSame(['same'], $this->statuses($installer->plan($projects)));
        $installer->install($projects);
        $this->assertSame($written, $this->installedEntry());

        $this->write("{$this->root}/content/collections/projects/a-sample-project.md", str_replace('temp/placeholder-wepb-image.webp', 'projects/real-photo.jpg', $written));
        $this->assertSame(
            ['action' => 'copy', 'target' => 'content/collections/projects/a-sample-project.md', 'status' => 'conflict', 'detail' => 'exists and differs, featured_image and images use temp/placeholder-wepb-image.webp (2070 × 1563), the largest image named placeholder'],
            $this->entryStep($installer->plan($projects)),
        );
    }

    public function test_a_marker_that_is_not_a_whole_value_on_its_own_line_is_an_error(): void
    {
        $this->image('placeholder.png', 1600, 900);
        $projects = $this->projectsPreset("---\ntitle: 'A sample project'\nimages: ['avoca:sample-image']\n---\n");

        $this->assertSame(
            ['action' => 'copy', 'target' => 'content/collections/projects/a-sample-project.md', 'status' => 'error', 'detail' => 'a sample image marker must be the whole value of a field or list item, on its own line'],
            $this->entryStep($this->installer()->plan($projects)),
        );
    }

    public function test_sample_images_are_offered_placeholders_first_then_the_biggest(): void
    {
        $this->image('temp/a-peak.jpg', 3696, 2448);
        $this->image('temp/placeholder-wepb-image.webp', 2070, 1563);
        $this->image('photos/small.jpg', 400, 300);
        $this->image('photos/big.jpg', 2400, 1600);

        $this->assertSame([
            'temp/placeholder-wepb-image.webp',
            'temp/a-peak.jpg',
            'photos/big.jpg',
        ], (new SampleImages($this->images))->choices(), 'named placeholder first, then the biggest that are large enough');
    }

    public function test_a_gallery_walks_the_images_instead_of_repeating_one(): void
    {
        $this->image('temp/a-peak.jpg', 3696, 2448);
        $this->image('temp/placeholder-wepb-image.webp', 2070, 1563);
        $this->useTestContainer();

        $walked = array_map(fn (int $i) => Samples::placeholder($i), [1, 2, 3, 4]);

        $this->assertSame([
            'temp/placeholder-wepb-image.webp',
            'temp/a-peak.jpg',
            'temp/placeholder-wepb-image.webp',
            'temp/a-peak.jpg',
        ], $walked, 'the list repeats once it runs out, rather than showing the same image every time');
        $this->assertSame('temp/placeholder-wepb-image.webp', Samples::placeholder(), 'no number means the first');
    }

    public function test_no_image_worth_showing_leaves_the_pages_without_one(): void
    {
        $this->image('photos/small.jpg', 400, 300);
        $this->useTestContainer();

        $this->assertSame([], (new SampleImages($this->images))->choices());
        $this->assertNull(Samples::placeholder(), 'nothing is generated to fill the gap');
    }

    public function test_choosing_images_writes_nothing_to_the_container(): void
    {
        $this->image('temp/a-peak.jpg', 3696, 2448);
        $before = $this->containerFiles();
        $this->useTestContainer();
        Samples::placeholder();

        $this->assertSame($before, $this->containerFiles(), 'the reference pages read the container and never add to it');
    }

    /** @return array<int, string> */
    private function containerFiles(): array
    {
        $found = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->images, \FilesystemIterator::SKIP_DOTS));
        foreach ($dir as $file) {
            $found[] = $file->getPathname();
        }
        sort($found);

        return $found;
    }

    /** Point the bound chooser at this test's images folder, and clear what Samples looked up before. */
    private function useTestContainer(): void
    {
        Samples::forgetSampleImages();
        $this->app->bind(SampleImages::class, fn () => new SampleImages($this->images));
    }
}
