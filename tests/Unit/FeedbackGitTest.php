<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Feedback\FeedbackChanged;
use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Statamic\Git\CommitJob;

/**
 * On a server with Statamic's automatic git, a change to a comment queues a commit, as a save in the control panel
 * does, so a reviewer's comment reaches the repository without anyone saving anything.
 */
class FeedbackGitTest extends TestCase
{
    private string $dir;

    private FeedbackStore $store;

    // Pro and git are set before the add-on boots, which is when it listens.
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.git.enabled', true);
        $app['config']->set('statamic.git.automatic', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/avoca-feedback-git-'.uniqid();
        $this->store = new FeedbackStore($this->dir);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function comment(): array
    {
        return $this->store->create(['url' => '/partners', 'body' => 'Which partners get a page?'], ['name' => 'Jane Client', 'staff' => false]);
    }

    public function test_a_comment_queues_a_commit_with_a_feedback_message(): void
    {
        $id = $this->comment()['id'];
        Bus::assertDispatched(CommitJob::class, fn (CommitJob $job) => $job->message === 'Feedback: comment on /partners');

        // A commit still waiting takes in the next change too, as it does for saves in the control panel: Statamic's
        // commit job is unique while it waits, and commits every tracked change when it runs.
        $this->store->resolve($id, ['name' => 'Brendyn', 'staff' => true]);
        Bus::assertDispatchedTimes(CommitJob::class, 1);
    }

    public function test_nothing_is_committed_when_git_is_not_automatic(): void
    {
        config(['statamic.git.automatic' => false]);
        $this->comment();

        Bus::assertNotDispatched(CommitJob::class);
    }

    public function test_a_site_can_leave_feedback_out_of_its_commits(): void
    {
        config(['statamic.git.ignored_events' => [FeedbackChanged::class]]);
        $this->comment();

        Bus::assertNotDispatched(CommitJob::class);
    }
}
