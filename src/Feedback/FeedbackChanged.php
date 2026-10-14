<?php

namespace Avocadesign\StatamicTools\Feedback;

use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Event;

/**
 * A comment's file was written or deleted: made, replied to, resolved, reopened, raised as a decision or decided, its
 * pin moved, its text edited, or deleted. Statamic's git integration listens for it, so on a server with automatic
 * commits a reviewer's comment is committed, and pushed where the server pushes, as a save in the control panel is.
 * Extending Statamic's event records who was logged in to the control panel, if anyone, as the commit's author.
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
