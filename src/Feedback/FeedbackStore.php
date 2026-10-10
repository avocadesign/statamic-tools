<?php

namespace Avocadesign\StatamicTools\Feedback;

use Illuminate\Support\Str;
use Statamic\Facades\YAML;

/**
 * Comments pinned to the site's pages, one YAML file each under storage/, holding the comment, where on the page it
 * was made, its replies and whether it is resolved. No database and no outside service: a review's comments stay on
 * the server they were made on, and avoca:feedback reads them.
 */
final class FeedbackStore
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** A decision still to make, and one made. */
    public const TO_DECIDE = 'open';

    public const DECIDED = 'decided';

    public function __construct(private string $directory)
    {
    }

    public static function make(): self
    {
        return new self(storage_path((string) config('statamic-tools.feedback.path', 'app/feedback')));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Every comment, newest first, optionally only those made on one page and only those in one state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $url = null, ?string $status = null): array
    {
        $comments = [];
        foreach (glob($this->directory.'/*.yaml') ?: [] as $file) {
            $comment = $this->read($file);
            if ($comment === null) {
                continue;
            }
            if ($url !== null && ($comment['url'] ?? null) !== $url) {
                continue;
            }
            if ($status !== null && ($comment['status'] ?? null) !== $status) {
                continue;
            }
            $comments[] = $comment;
        }

        usort($comments, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $comments;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->valid($id) ? $this->read($this->path($id)) : null;
    }

    /**
     * Records a new comment and returns it. Where it was made is the page and, on it, the element and the spot within
     * it, as the widget measured them. A comment on the prototype also records the version, the page's route key and
     * route, and the frame it was made in.
     *
     * @param  array<string, mixed>  $comment  url, entry, title, body, anchor, viewport; for the prototype, version, page, route, frame
     * @param  array{name: string, staff: bool}  $author
     * @return array<string, mixed>
     */
    public function create(array $comment, array $author): array
    {
        $prototype = ($comment['context'] ?? 'site') === 'prototype';
        $record = [
            'id' => (string) Str::ulid(),
            'context' => $prototype ? 'prototype' : 'site',
            'version' => $prototype ? (string) ($comment['version'] ?? '') : null,
            'page' => $prototype ? (string) ($comment['page'] ?? '') : null,
            'route' => $prototype ? (string) ($comment['route'] ?? '') : null,
            'frame' => $prototype ? (string) ($comment['frame'] ?? '') : null,
            'url' => (string) $comment['url'],
            'entry' => $comment['entry'] ?? null,
            'title' => $comment['title'] ?? null,
            'body' => (string) $comment['body'],
            'anchor' => $comment['anchor'] ?? [],
            'viewport' => $comment['viewport'] ?? [],
            'author' => $author,
            'created_at' => now()->toIso8601String(),
            'status' => self::OPEN,
            'resolved_by' => null,
            'resolved_at' => null,
            'replies' => [],
            'decision' => null,
        ];

        $this->write($record);

        return $record;
    }

    /** @param  array{name: string, staff: bool}  $author */
    public function reply(string $id, string $body, array $author): ?array
    {
        return $this->change($id, function (array $comment) use ($body, $author) {
            $comment['replies'][] = ['author' => $author, 'body' => $body, 'created_at' => now()->toIso8601String()];

            return $comment;
        });
    }

    /** @param  array{name: string, staff: bool}  $by */
    public function resolve(string $id, array $by): ?array
    {
        return $this->change($id, fn (array $comment) => [
            ...$comment,
            'status' => self::RESOLVED,
            'resolved_by' => $by,
            'resolved_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Raises a comment as a decision to make, records the decision made, or takes the decision off again. The team
     * does this, never the reviewers: who may is the controller's to check.
     *
     * @param  array{name: string, staff: bool}  $by
     */
    public function decide(string $id, string $state, array $by, ?string $outcome = null, ?string $who = null): ?array
    {
        return $this->change($id, function (array $comment) use ($state, $by, $outcome, $who) {
            if ($state === 'none') {
                return [...$comment, 'decision' => null];
            }

            $decision = (array) ($comment['decision'] ?? []);
            $decision['raised_by'] ??= $by;
            $decision['raised_at'] ??= now()->toIso8601String();
            if ($who !== null) {
                $decision['who'] = $who;
            }
            $decision['state'] = $state === self::DECIDED ? self::DECIDED : self::TO_DECIDE;
            if ($decision['state'] === self::DECIDED) {
                $decision['outcome'] = (string) $outcome;
                $decision['decided_by'] = $by;
                $decision['decided_at'] = now()->toIso8601String();
            } else {
                $decision['outcome'] = null;
                $decision['decided_by'] = null;
                $decision['decided_at'] = null;
            }

            return [...$comment, 'decision' => $decision];
        });
    }

    public function reopen(string $id): ?array
    {
        return $this->change($id, fn (array $comment) => [...$comment, 'status' => self::OPEN, 'resolved_by' => null, 'resolved_at' => null]);
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function change(string $id, callable $change): ?array
    {
        $comment = $this->find($id);
        if ($comment === null) {
            return null;
        }

        $comment = $change($comment);
        $this->write($comment);

        return $comment;
    }

    /** IDs are ULIDs: anything else never reaches the file system. */
    private function valid(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.$id.'.yaml';
    }

    /** @return array<string, mixed>|null */
    private function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $data = YAML::parse((string) file_get_contents($file));

        return is_array($data) && isset($data['id']) ? $data : null;
    }

    /** @param  array<string, mixed>  $comment */
    private function write(array $comment): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        file_put_contents($this->path($comment['id']), YAML::dump($comment), LOCK_EX);
    }
}
