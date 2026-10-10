<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Prototype\Prototype;
use Avocadesign\StatamicTools\Site\SiteCss;
use Avocadesign\StatamicTools\Tests\TestCase;

class PrototypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/avoca-prototype-'.uniqid();
        mkdir("{$this->dir}/prototype/1", 0755, true);
        mkdir("{$this->dir}/css", 0755, true);
        file_put_contents("{$this->dir}/prototype/1/data.js", "const PROJECT = { name: 'Test' }; /* DATA-MARKER */\n");
        file_put_contents("{$this->dir}/prototype/1/pages.js", "/* PAGES-MARKER */\n");
        file_put_contents("{$this->dir}/prototype/1/version.json", '{"format": 2, "label": "First look", "date": "2026-10-10", "notes": []}');
        file_put_contents("{$this->dir}/css/site.css", "@import \"tailwindcss\";\n@import \"./colours.css\" layer(components);\n@plugin \"@tailwindcss/forms\";\n.site-rule { color: red; }\n");
        file_put_contents("{$this->dir}/css/colours.css", "@theme static {\n    --color-primary: oklch(53% 0.3 290);\n    --color-accent: #ff6600;\n    --color-gray-100: var(--color-slate-100);\n}\n");

        config([
            'statamic-tools.prototype.enabled' => true,
            'statamic-tools.prototype.path' => "{$this->dir}/prototype",
            'statamic-tools.site.css_entry' => "{$this->dir}/css/site.css",
            'statamic-tools.feedback.password' => null,
            'statamic-tools.feedback.reviewers_path' => "{$this->dir}/reviewers.yaml",
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function legacy(string $id): void
    {
        mkdir("{$this->dir}/prototype/{$id}");
        file_put_contents("{$this->dir}/prototype/{$id}/index.html", "<!doctype html><html><head><title>Old</title></head><body>LEGACY BUILD</body></html>\n");
    }

    public function test_the_prototype_is_hidden_when_switched_off_or_missing(): void
    {
        config(['statamic-tools.prototype.enabled' => false]);
        $this->get('/prototype')->assertNotFound();
        $this->post('/prototype', ['name' => 'Aroha'])->assertNotFound();

        config(['statamic-tools.prototype.enabled' => true, 'statamic-tools.prototype.path' => "{$this->dir}/nowhere"]);
        $this->get('/prototype')->assertNotFound();
    }

    public function test_it_is_on_by_default_for_local_and_staging_only(): void
    {
        config(['statamic-tools.prototype.enabled' => null]);

        foreach (['local' => true, 'staging' => true, 'production' => false, 'testing' => false] as $env => $on) {
            $this->app['env'] = $env;
            $this->assertSame($on, Prototype::enabled(), $env);
        }

        $this->app['env'] = 'production';
        config(['statamic-tools.prototype.enabled' => 'true']);
        $this->assertTrue(Prototype::enabled());
    }

    public function test_the_page_is_the_add_ons_interface_around_the_sites_content(): void
    {
        $this->get('/prototype')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<title>First look', false)
            ->assertSee('window.PROTOTYPE_VIEWER = null;', false)
            ->assertSee('DATA-MARKER', false)
            ->assertSee('PAGES-MARKER', false)
            ->assertSee('function pageHeader(', false)
            ->assertSee('/* ---------- Shell ---------- */', false)
            ->assertSee('"label":"First look"', false);
    }

    public function test_the_frames_get_the_sites_own_css_with_the_wireframe_on_top(): void
    {
        $css = Prototype::frameCss();

        $this->assertStringContainsString('.site-rule { color: red; }', $css);
        $this->assertStringContainsString("@layer components {\n@theme static", $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('@plugin', $css);
        // The brand's other colours turn grey at their own lightness; the greys and the primary are left to the layer.
        $this->assertStringContainsString('--color-accent: oklch(from #ff6600 l 0 h);', $css);
        $this->assertStringNotContainsString('--color-gray-100: oklch(from', $css);
        $this->assertStringContainsString('--color-primary: oklch(0.34 0 0);', $css);
        $this->assertStringContainsString('.wf-img {', $css);
        $this->assertSame(SiteCss::flatten("{$this->dir}/css/site.css"), substr($css, 0, strlen(SiteCss::flatten("{$this->dir}/css/site.css"))));
    }

    public function test_a_password_shows_the_sign_in_page_and_signing_in_opens_the_prototype(): void
    {
        config(['statamic-tools.feedback.password' => 'kiwi']);

        $this->get('/prototype')->assertOk()->assertSee('Your name')->assertSee('Password')->assertDontSee('window.PROTOTYPE_VIEWER', false);

        $this->post('/prototype', ['name' => 'Aroha', 'password' => 'nope'])
            ->assertRedirect('/prototype')->assertSessionHasErrors('password')->assertSessionHasInput('name', 'Aroha');
        $this->post('/prototype', ['password' => 'kiwi'])->assertSessionHasErrors('name');

        $response = $this->post('/prototype', ['name' => 'Aroha', 'password' => 'kiwi'])->assertRedirect('/prototype')->assertCookie('prototype');
        $cookie = $response->getCookie('prototype')->getValue();

        $this->withCookie('prototype', $cookie)->get('/prototype')->assertOk()->assertSee('window.PROTOTYPE_VIEWER = {"name":"Aroha","staff":false};', false);

        // A new password signs everyone out.
        config(['statamic-tools.feedback.password' => 'kauri']);
        $this->withCookie('prototype', $cookie)->get('/prototype')->assertSee('Your name');
    }

    public function test_the_page_gets_the_sites_name_and_whether_feedback_is_on(): void
    {
        config(['app.name' => 'Harbour Trust', 'statamic-tools.feedback.enabled' => true]);
        $this->app['env'] = 'local';

        $this->get('/prototype')->assertOk()
            ->assertSee('window.PROTOTYPE_SITE = "Harbour Trust";', false)
            ->assertSee('window.PROTOTYPE_FEEDBACK = {"on":true,"base":"/!/statamic-tools/feedback"};', false)
            ->assertDontSee('prototype-theme', false);
    }

    public function test_signing_in_from_a_comment_link_opens_that_comment(): void
    {
        file_put_contents("{$this->dir}/reviewers.yaml", "reviewers:\n  - name: Jane Smith\n    email: jane@example.com\n");
        $id = '01M4J4ZM55RDK9KEXMQH5SWZ15';

        $this->get("/prototype/1?comment={$id}")->assertOk()->assertSee('name="comment" value="'.$id.'"', false);
        $this->get('/prototype/1?comment=../etc')->assertOk()->assertDontSee('name="comment"', false);
        $this->post('/prototype', ['version' => '1', 'comment' => $id, 'email' => 'jane@example.com'])->assertRedirect("/prototype/1?comment={$id}");
        $this->post('/prototype', ['version' => '1', 'comment' => 'nope', 'email' => 'jane@example.com'])->assertRedirect('/prototype/1');
    }

    public function test_the_content_model_is_the_teams_unless_it_is_shared(): void
    {
        $team = ['name' => 'Avoca', 'staff' => true];
        $reviewer = ['name' => 'Jane Smith', 'staff' => false];

        $this->assertSame(['model' => true, 'model_team_only' => true], Prototype::views($team));
        $this->assertSame(['model' => false, 'model_team_only' => false], Prototype::views($reviewer));
        $this->assertFalse(Prototype::views(null)['model']);

        config(['statamic-tools.prototype.content_model' => 'everyone']);
        $this->assertSame(['model' => true, 'model_team_only' => false], Prototype::views($reviewer));
        $this->assertSame(['model' => true, 'model_team_only' => false], Prototype::views($team));

        config(['statamic-tools.prototype.content_model' => 'off']);
        $this->assertFalse(Prototype::views($team)['model']);

        config(['statamic-tools.prototype.content_model' => 'team']);
        $this->get('/prototype')->assertSee('window.PROTOTYPE_VIEWS = {"model":false,"model_team_only":false};', false);
    }

    public function test_a_list_of_reviewers_asks_for_an_email_even_without_a_password(): void
    {
        file_put_contents("{$this->dir}/reviewers.yaml", "reviewers:\n  - name: Jane Smith\n    email: jane@example.com\n");

        $this->get('/prototype')->assertOk()->assertSee('Email address')->assertDontSee('Password');
        $this->post('/prototype', ['email' => 'someone@else.com'])->assertSessionHasErrors('email');

        $cookie = $this->post('/prototype', ['email' => 'JANE@example.com'])->assertRedirect('/prototype')->getCookie('prototype')->getValue();
        $this->withCookie('prototype', $cookie)->get('/prototype')->assertSee('"name":"Jane Smith"', false);
    }

    public function test_each_version_has_its_own_address_and_signing_in_returns_to_it(): void
    {
        mkdir("{$this->dir}/prototype/2");
        foreach (['data.js', 'pages.js'] as $file) {
            copy("{$this->dir}/prototype/1/{$file}", "{$this->dir}/prototype/2/{$file}");
        }
        file_put_contents("{$this->dir}/prototype/2/version.json", '{"label": "Second look"}');

        $this->get('/prototype')->assertSee('<title>Second look', false);
        $this->get('/prototype/1')->assertSee('<title>First look', false)->assertSee('"url":"/prototype/2"', false);
        $this->get('/prototype/9')->assertNotFound();

        config(['statamic-tools.feedback.password' => 'kiwi']);
        $this->post('/prototype', ['name' => 'Aroha', 'password' => 'kiwi', 'version' => '1'])->assertRedirect('/prototype/1');
        $this->get('/prototype/sign-out')->assertRedirect('/prototype');
    }

    public function test_a_version_from_before_the_move_is_served_as_it_was_built(): void
    {
        $this->legacy('0');

        $this->get('/prototype/0')->assertOk()->assertSee('LEGACY BUILD')->assertSee('<head><script>window.PROTOTYPE_VIEWER = null;', false)->assertDontSee('PAGES-MARKER', false);
        $this->get('/prototype')->assertSee('<title>First look', false);
    }

    public function test_a_new_version_starts_as_a_copy_of_the_newest(): void
    {
        $this->artisan('avoca:prototype:version', ['version' => 'B', '--label' => 'Option B'])->assertSuccessful();

        $this->assertFileEquals("{$this->dir}/prototype/1/pages.js", "{$this->dir}/prototype/B/pages.js");
        $about = json_decode(file_get_contents("{$this->dir}/prototype/B/version.json"), true);
        $this->assertSame(['format' => 2, 'label' => 'Option B', 'date' => now()->toDateString(), 'notes' => []], $about);

        $this->artisan('avoca:prototype:version', ['version' => 'B'])->expectsOutput('Version B already exists.')->assertFailed();
        $this->artisan('avoca:prototype:version', ['version' => '../x'])->assertFailed();
    }
}
