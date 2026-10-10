<?php

namespace Avocadesign\StatamicTools\Feedback;

use Illuminate\Support\Carbon;

/**
 * What the team hears about: comments and replies reviewers have made since the last digest, and who to tell. The
 * team's own comments and replies are left out, since they made them. The time of the last digest is kept beside the
 * comments, so nothing is told twice and nothing is missed between runs.
 */
final class Digest
{
    public function __construct(private FeedbackStore $store)
    {
    }

    /**
     * The reviewers marked team: true, and FEEDBACK_NOTIFY's addresses.
     *
     * @return array<int, string>
     */
    public static function recipients(): array
    {
        $team = array_map(fn ($person) => $person['email'], array_filter(Reviewers::all(), fn ($person) => $person['team']));
        $extra = array_map('trim', explode(',', (string) config('statamic-tools.feedback.notify')));
        $emails = array_filter([...$team, ...$extra], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        return array_values(array_unique(array_map('mb_strtolower', $emails)));
    }

    /** When the last digest went, or, before the first, one interval ago, so a site's first comments are told. */
    public function since(): Carbon
    {
        $state = is_file($this->statePath()) ? json_decode((string) file_get_contents($this->statePath()), true) : null;
        $sent = is_array($state) && is_string($state['sent_at'] ?? null) ? $state['sent_at'] : null;

        return $sent ? Carbon::parse($sent) : now()->subMinutes(self::minutes());
    }

    public function markSent(Carbon $until): void
    {
        if (! is_dir($this->store->directory())) {
            mkdir($this->store->directory(), 0755, true);
        }
        file_put_contents($this->statePath(), json_encode(['sent_at' => $until->toIso8601String()]), LOCK_EX);
    }

    /**
     * New comments and replies from reviewers, after $since and up to $until, oldest first.
     *
     * @return array<int, array{kind: string, comment: array<string, mixed>, reply: ?array<string, mixed>, at: string}>
     */
    public function pending(Carbon $since, Carbon $until): array
    {
        $within = fn ($at) => is_string($at) && Carbon::parse($at)->gt($since) && Carbon::parse($at)->lte($until);
        $reviewer = fn ($author) => ! ($author['staff'] ?? false);

        $items = [];
        foreach ($this->store->all() as $comment) {
            if ($within($comment['created_at'] ?? null) && $reviewer($comment['author'] ?? [])) {
                $items[] = ['kind' => 'comment', 'comment' => $comment, 'reply' => null, 'at' => $comment['created_at']];
            }
            foreach ((array) ($comment['replies'] ?? []) as $reply) {
                if ($within($reply['created_at'] ?? null) && $reviewer($reply['author'] ?? [])) {
                    $items[] = ['kind' => 'reply', 'comment' => $comment, 'reply' => $reply, 'at' => $reply['created_at']];
                }
            }
        }

        usort($items, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $items;
    }

    /** The address that opens a comment: in the prototype's version, or on the site's page. */
    public static function link(array $comment): string
    {
        if (($comment['context'] ?? 'site') === 'prototype') {
            return url('/prototype/'.rawurlencode((string) ($comment['version'] ?? ''))).'?comment='.$comment['id'];
        }

        return url((string) ($comment['url'] ?? '/')).'?feedback='.$comment['id'];
    }

    public static function minutes(): int
    {
        return max(1, (int) config('statamic-tools.feedback.digest_minutes', 10));
    }

    private function statePath(): string
    {
        return $this->store->directory().'/.digest.json';
    }
}
