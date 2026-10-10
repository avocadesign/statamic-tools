<?php

namespace Avocadesign\StatamicTools\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The team's digest of new comments and replies, each with a link straight to it. */
class FeedbackDigest extends Mailable
{
    use Queueable;

    /** @param  array<int, array{kind: string, comment: array<string, mixed>, reply: ?array<string, mixed>, at: string}>  $items */
    public function __construct(public array $items)
    {
    }

    public function envelope(): Envelope
    {
        $comments = count(array_filter($this->items, fn ($item) => $item['kind'] === 'comment'));
        $replies = count($this->items) - $comments;
        $parts = array_filter([
            $comments ? $comments.' new '.($comments === 1 ? 'comment' : 'comments') : null,
            $replies ? $replies.' '.($replies === 1 ? 'reply' : 'replies') : null,
        ]);

        return new Envelope(subject: ucfirst(implode(' and ', $parts)).' on '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'statamic-tools::feedback.digest', text: 'statamic-tools::feedback.digest-text');
    }
}
