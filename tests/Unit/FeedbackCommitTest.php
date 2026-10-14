<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * A change to a comment runs the site's scripts/server-git.sh commit --now once the response has gone, so a comment
 * made on the server is committed within seconds, on Core as well as Pro.
 */
class FeedbackCommitTest extends TestCase
{
    private string $dir;

    private string $scripts;

    private string $script;

    private FeedbackStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/avoca-feedback-commit-'.uniqid();
        $this->store = new FeedbackStore($this->dir);

        // A scripts folder of the test's own inside the site, which is where the add-on looks.
        $this->scripts = 'avoca-test-scripts-'.uniqid();
        $this->script = base_path($this->scripts.'/server-git.sh');
        mkdir(dirname($this->script), 0755, true);
        file_put_contents($this->script, "#!/bin/sh\nexit 0\n");
        chmod($this->script, 0755);

        config([
            'statamic-tools.site.scripts_path' => $this->scripts,
            'statamic-tools.feedback.commit' => true,
            'statamic.git.enabled' => true,
        ]);
        Process::fake();
        $this->withoutDefer();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir).' '.escapeshellarg(base_path($this->scripts)));
        parent::tearDown();
    }

    private function comment(): array
    {
        return $this->store->create(['url' => '/partners', 'body' => 'Which partners get a page?'], ['name' => 'Jane Client', 'staff' => false]);
    }

    public function test_a_change_runs_the_server_script_with_a_feedback_subject(): void
    {
        $id = $this->comment()['id'];
        $this->store->resolve($id, ['name' => 'Brendyn', 'staff' => true]);

        $ran = fn (string $subject) => fn (PendingProcess $process) => $process->command === [$this->script, 'commit', '--now']
            && $process->path === base_path()
            && $process->environment['AVOCA_GIT_SUBJECT'] === $subject;
        Process::assertRan($ran('Feedback: comment on /partners'));
        Process::assertRan($ran('Feedback: resolved on /partners'));
    }

    public function test_nothing_runs_without_git_switched_on(): void
    {
        config(['statamic.git.enabled' => false]);
        $this->comment();

        Process::assertNothingRan();
    }

    public function test_nothing_runs_when_feedback_commits_are_switched_off(): void
    {
        config(['statamic-tools.feedback.commit' => false]);
        $this->comment();

        Process::assertNothingRan();
    }

    public function test_unset_it_commits_anywhere_but_a_local_machine(): void
    {
        config(['statamic-tools.feedback.commit' => null]);
        $this->app['env'] = 'local';
        $this->comment();
        Process::assertNothingRan();

        $this->app['env'] = 'production';
        $this->comment();
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === $this->script, 1);
    }

    public function test_nothing_runs_without_the_script_or_when_it_cannot_be_run(): void
    {
        chmod($this->script, 0644);
        $this->comment();
        Process::assertNothingRan();

        unlink($this->script);
        $this->comment();
        Process::assertNothingRan();
    }

    public function test_a_script_that_fails_is_logged_and_the_comment_still_saved(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Push to origin/main failed.', exitCode: 1)]);
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'server-git.sh commit --now exited with 1')
            && str_contains($context['output'] ?? '', 'Push to origin/main failed.'));

        $comment = $this->comment();

        $this->assertNotNull($this->store->find($comment['id']));
    }
}
