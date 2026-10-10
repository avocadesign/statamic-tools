<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Http\Middleware\InjectFeedbackWidget;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class FeedbackTest extends TestCase
{
    private string $dir;

    private FeedbackStore $store;

    private const PERSON = ['name' => 'Jane Client', 'staff' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/avoca-feedback-'.uniqid();
        $this->store = new FeedbackStore($this->dir);
        $this->app->instance(FeedbackStore::class, $this->store);
        config(['statamic-tools.feedback.enabled' => true, 'statamic-tools.feedback.password' => null]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function comment(string $url = '/about', string $body = 'Make the heading shorter'): array
    {
        return $this->store->create([
            'url' => $url,
            'body' => $body,
            'anchor' => ['selector' => '#content > section:nth-of-type(2) h2', 'x' => 0.2, 'y' => 0.5, 'block' => 'text', 'label' => 'near “About us”'],
            'viewport' => ['width' => 1440, 'height' => 900, 'breakpoint' => 'xl'],
        ], self::PERSON);
    }

    private function signedIn(): static
    {
        // JSON requests in tests only carry cookies with credentials.
        return $this->withCredentials()->withCookie('prototype', json_encode(['name' => 'Jane Client', 'key' => hash_hmac('sha256', '', (string) config('app.key'))]));
    }

    public function test_a_comment_is_stored_replied_to_resolved_and_reopened(): void
    {
        $comment = $this->comment();

        $this->assertSame('open', $comment['status']);
        $this->assertFileExists($this->dir.'/'.$comment['id'].'.yaml');

        $this->store->reply($comment['id'], 'Done, have a look', ['name' => 'Brendyn', 'staff' => true]);
        $resolved = $this->store->resolve($comment['id'], ['name' => 'Brendyn', 'staff' => true]);

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('Brendyn', $resolved['resolved_by']['name']);
        $this->assertSame('Done, have a look', $resolved['replies'][0]['body']);
        $this->assertSame('open', $this->store->reopen($comment['id'])['status']);
        $this->assertNull($this->store->reopen($comment['id'])['resolved_by']);
    }

    public function test_comments_are_listed_by_page_and_by_state(): void
    {
        $about = $this->comment('/about');
        $this->comment('/contact');
        $this->store->resolve($about['id'], self::PERSON);

        $this->assertCount(2, $this->store->all());
        $this->assertCount(1, $this->store->all('/contact'));
        $this->assertCount(1, $this->store->all(null, FeedbackStore::RESOLVED));
        $this->assertSame([], $this->store->all('/contact', FeedbackStore::RESOLVED));
    }

    public function test_an_id_that_is_not_one_never_reaches_the_file_system(): void
    {
        $this->assertNull($this->store->find('../../.env'));
        $this->assertNull($this->store->resolve('../../.env', self::PERSON));
    }

    public function test_switched_off_the_widget_is_never_registered_and_every_route_is_a_404(): void
    {
        // The provider reads the switch as it boots, and it is off by default.
        $this->assertNotContains(InjectFeedbackWidget::class, $this->app['router']->getMiddlewareGroups()['statamic.web'] ?? []);

        config(['statamic-tools.feedback.enabled' => false]);
        $this->get('/!/statamic-tools/feedback/session')->assertNotFound();
        $this->get('/!/statamic-tools/feedback/loader.js')->assertNotFound();
    }

    public function test_the_loader_goes_before_the_closing_body_of_a_page_and_nowhere_else(): void
    {
        $middleware = new InjectFeedbackWidget;
        $page = fn (string $body, int $status = 200, string $type = 'text/html; charset=UTF-8') => fn () => new Response($body, $status, ['Content-Type' => $type]);

        $html = $middleware->handle(Request::create('/about'), $page('<html><body><p>Hi</p></body></html>'))->getContent();
        $this->assertMatchesRegularExpression('#<p>Hi</p><script defer src="/!/statamic-tools/feedback/loader\.js\?v=\w+" data-feedback="/!/statamic-tools/feedback"[^>]*></script>\s*</body>#', $html);

        $this->assertSame('{"a":1}', $middleware->handle(Request::create('/about'), $page('{"a":1}', 200, 'application/json'))->getContent());
        $this->assertSame('<body>missing</body>', $middleware->handle(Request::create('/gone'), $page('<body>missing</body>', 404))->getContent());
        $this->assertSame('<body>form</body>', $middleware->handle(Request::create('/about', 'POST'), $page('<body>form</body>'))->getContent());
    }

    public function test_signing_in_asks_for_the_password_when_one_is_set(): void
    {
        config(['statamic-tools.feedback.password' => 'kiwi']);

        $this->getJson('/!/statamic-tools/feedback/session')->assertOk()->assertJson(['viewer' => null, 'needs_password' => true]);
        $this->postJson('/!/statamic-tools/feedback/sign-in', ['name' => 'Jane', 'password' => 'nope'])->assertStatus(422);
        $this->postJson('/!/statamic-tools/feedback/sign-in', ['name' => 'Jane', 'password' => 'kiwi'])
            ->assertOk()->assertJson(['viewer' => ['name' => 'Jane']])->assertCookie('prototype');
    }

    public function test_commenting_needs_a_name_and_records_who_and_where(): void
    {
        $payload = ['url' => 'https://staging.example.com/about/?x=1', 'body' => 'Shorter heading', 'anchor' => ['selector' => 'h2', 'x' => 0.5, 'y' => 0.5, 'block' => 'text']];

        $this->postJson('/!/statamic-tools/feedback/comments', $payload)->assertStatus(401);

        $comment = $this->signedIn()->postJson('/!/statamic-tools/feedback/comments', $payload)->assertCreated()->json('comment');
        $this->assertSame('/about', $comment['url']);
        $this->assertSame('Jane Client', $comment['author']['name']);

        $this->signedIn()->postJson('/!/statamic-tools/feedback/comments/'.$comment['id'].'/resolve')->assertOk()->assertJsonPath('comment.status', 'resolved');
    }

    public function test_the_commands_routes_take_the_password_and_nothing_else(): void
    {
        $comment = $this->comment();

        $this->signedIn()->getJson('/!/statamic-tools/feedback/api/comments')->assertForbidden();

        config(['statamic-tools.feedback.password' => 'kiwi']);
        $this->getJson('/!/statamic-tools/feedback/api/comments', ['X-Feedback-Password' => 'nope'])->assertForbidden();
        $this->getJson('/!/statamic-tools/feedback/api/comments?scope=all', ['X-Feedback-Password' => 'kiwi'])->assertOk()->assertJsonCount(1, 'comments');
        $this->postJson('/!/statamic-tools/feedback/api/comments/'.$comment['id'].'/resolve', [], ['X-Feedback-Password' => 'kiwi', 'X-Feedback-Name' => 'Claude'])
            ->assertOk()->assertJsonPath('comment.resolved_by.name', 'Claude');
    }

    public function test_the_command_lists_open_feedback_in_words_and_resolves_it(): void
    {
        $comment = $this->comment();

        $this->assertSame(0, Artisan::call('avoca:feedback'));
        $output = Artisan::output();
        $this->assertStringContainsString($comment['id'].' open  /about  Text block, near “About us”', $output);
        $this->assertStringContainsString('Jane Client, ', $output);
        $this->assertStringContainsString('1440px wide (xl)', $output);
        $this->assertStringContainsString('  Make the heading shorter', $output);

        $this->artisan('avoca:feedback', ['--resolve' => [$comment['id']], '--as' => 'Claude'])->expectsOutput("Resolved {$comment['id']}.")->assertSuccessful();
        $this->assertSame('Claude', $this->store->find($comment['id'])['resolved_by']['name']);
        $this->artisan('avoca:feedback')->expectsOutput('No open feedback.')->assertSuccessful();
    }
}
