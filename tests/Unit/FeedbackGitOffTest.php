<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Statamic\Git\CommitJob;

/**
 * A Pro site with git off, as every site is locally and many are on their servers, boots and takes comments as before,
 * and commits nothing. v0.1.53 built Statamic's Git facade to listen, and its constructor throws when git is off.
 */
class FeedbackGitOffTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.git.enabled', false);
    }

    public function test_a_pro_site_with_git_off_boots_and_takes_comments_without_committing(): void
    {
        Bus::fake();
        Process::fake();
        $this->withoutDefer();
        $dir = sys_get_temp_dir().'/avoca-feedback-git-off-'.uniqid();
        $store = new FeedbackStore($dir);

        $comment = $store->create(['url' => '/partners', 'body' => 'Which partners get a page?'], ['name' => 'Jane Client', 'staff' => false]);

        $this->assertNotNull($store->find($comment['id']));
        Bus::assertNotDispatched(CommitJob::class);
        Process::assertNothingRan();
        exec('rm -rf '.escapeshellarg($dir));
    }
}
