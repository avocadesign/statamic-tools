<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Feedback\Digest;
use Avocadesign\StatamicTools\Feedback\FeedbackSettings;
use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Mail\FeedbackDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the team one digest of the comments and replies reviewers made since the last one. Laravel's scheduler runs it
 * every few minutes while feedback is on, so a client who leaves twenty comments sends one email, not twenty. If the
 * email can't be sent, the next run tries again with everything since.
 */
class FeedbackNotify extends Command
{
    protected $signature = 'avoca:feedback:notify
        {--dry-run : Say who would be told what, and send nothing}';

    protected $description = 'Email the team a digest of new feedback since the last one.';

    public function handle(FeedbackStore $store): int
    {
        if (! FeedbackSettings::active()) {
            $this->line('Feedback is off on this site, so there is nothing to tell.');

            return self::SUCCESS;
        }

        $to = Digest::recipients();
        if ($to === []) {
            $this->warn('Nobody to tell: mark the team in resources/site/reviewers.yaml with team: true, or set FEEDBACK_NOTIFY.');

            return self::SUCCESS;
        }

        $digest = new Digest($store);
        // Comments keep their time to the second, so a digest covers whole seconds: one made during this second waits
        // for the next digest rather than slipping between the two.
        $until = now()->subSecond()->startOfSecond();
        $items = $digest->pending($digest->since(), $until);
        if ($items === []) {
            $this->line('Nothing new since the last digest.');

            return self::SUCCESS;
        }

        $summary = count($items).' new '.(count($items) === 1 ? 'item' : 'items').' for '.implode(', ', $to);
        if ($this->option('dry-run')) {
            $this->line("Would send {$summary}:");
            foreach ($items as $item) {
                $author = $item['kind'] === 'reply' ? ($item['reply']['author']['name'] ?? 'Someone') : ($item['comment']['author']['name'] ?? 'Someone');
                $this->line("  {$item['kind']} from {$author}: ".Digest::link($item['comment']));
            }

            return self::SUCCESS;
        }

        try {
            Mail::to($to)->send(new FeedbackDigest($items));
        } catch (Throwable $e) {
            $this->error('The digest could not be sent, so the next run tries again: '.$e->getMessage());

            return self::FAILURE;
        }

        $digest->markSent($until);
        $this->info("Sent {$summary}.");

        return self::SUCCESS;
    }
}
