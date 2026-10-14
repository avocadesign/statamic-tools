<?php

namespace Avocadesign\StatamicTools\Feedback;

use Avocadesign\StatamicTools\Site\Scripts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Commits a comment as soon as it changes, through the site's own scripts/server-git.sh, so a comment made on the server
 * reaches the repository, and the remote where the server pushes, within seconds. Statamic commits only saves in the
 * control panel, and only with Pro; the script works on Core too, and its cron run catches anything missed here.
 *
 * It runs once the response has gone, so a reviewer never waits on git, and once per request however many changes the
 * request made. The script's lock turns away a second run while one is going, and a failure is logged, never shown.
 */
class CommitFeedback
{
    public const SCRIPT = 'server-git.sh';

    public function handle(FeedbackChanged $event): void
    {
        $script = Scripts::make()->file(self::SCRIPT);
        if (! FeedbackSettings::commits() || ! config('statamic.git.enabled') || ! is_file($script) || ! is_executable($script)) {
            return;
        }

        $subject = $event->commitMessage();
        defer(fn () => $this->commit($script, $subject), 'avoca-feedback-commit');
    }

    private function commit(string $script, string $subject): void
    {
        try {
            $result = Process::path(base_path())
                ->env([
                    'AVOCA_GIT_SUBJECT' => $subject,
                    // The PHP that reads the site's git config: the command line one, not the web server's.
                    'AVOCA_PHP' => (new PhpExecutableFinder)->find(false) ?: 'php',
                ])
                ->timeout(120)
                ->run([$script, 'commit', '--now']);

            if ($result->failed()) {
                Log::warning('Feedback was not committed: '.self::SCRIPT.' commit --now exited with '.$result->exitCode().'.', [
                    'output' => trim($result->output()."\n".$result->errorOutput()),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Feedback was not committed: '.$e->getMessage());
        }
    }
}
