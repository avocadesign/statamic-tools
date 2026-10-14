<?php

namespace Avocadesign\StatamicTools\Feedback;

use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Event;

/**
 * A comment's file was written or deleted: made, replied to, resolved, reopened, raised as a decision or decided, its
 * pin moved, its text edited, or deleted. CommitFeedback listens for it and commits the comment through the site's
 * server git script, and a site can listen for it too. Its commit message is the script's subject; a Pro site that
 * commits through Statamic's own git instead can pass it to Git::listen(). Extending Statamic's event records who was
 * logged in to the control panel, if anyone.
 */
class FeedbackChanged extends Event implements ProvidesCommitMessage
{
    /** @param  array<string, mixed>  $comment */
    public function __construct(public array $comment, public string $change)
    {
    }

    /** Such as "Feedback: comment on /partners" or "Feedback: resolved on /prototype/1/contact". */
    public function commitMessage()
    {
        $url = (string) ($this->comment['url'] ?? '');

        return 'Feedback: '.$this->change.($url !== '' ? ' on '.$url : '');
    }
}
