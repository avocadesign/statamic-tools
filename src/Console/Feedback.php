<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Feedback\FeedbackStore;
use Avocadesign\StatamicTools\Site\Blocks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Reads and answers the comments people pinned to the site's pages, so a developer, or Claude working for one, can
 * act on them. With --from it reads a server's comments over HTTP instead of this site's own files, sending the
 * password, which is how comments made on staging reach a developer's machine.
 */
class Feedback extends Command
{
    protected $signature = 'avoca:feedback
        {--all : Include resolved comments}
        {--url= : Only the comments on one page, by its path}
        {--json : Print the comments as JSON}
        {--resolve=* : Resolve the comment with this ID}
        {--reopen=* : Reopen the comment with this ID}
        {--reply= : Reply to the comment with this ID, with --message}
        {--message= : The reply}
        {--as=Developer : The name a reply or a resolve is recorded under}
        {--from= : A server\'s address, such as https://staging.example.com, to work with its comments instead}
        {--password= : The server\'s PROTOTYPE_PASSWORD, when it differs from this site\'s}';

    protected $description = 'List, reply to and resolve the feedback pinned to the site\'s pages, here or on a server.';

    public function handle(): int
    {
        $from = $this->option('from');
        if (is_string($from) && $from !== '' && (string) ($this->option('password') ?: config('statamic-tools.feedback.password')) === '') {
            $this->error('Reading a server\'s feedback needs its PROTOTYPE_PASSWORD: pass --password, or set it in this site\'s .env.');

            return self::FAILURE;
        }

        foreach ((array) $this->option('resolve') as $id) {
            if (! $this->act($id, 'resolve')) {
                return self::FAILURE;
            }
        }
        foreach ((array) $this->option('reopen') as $id) {
            if (! $this->act($id, 'reopen')) {
                return self::FAILURE;
            }
        }
        if ($id = $this->option('reply')) {
            $message = trim((string) $this->option('message'));
            if ($message === '') {
                $this->error('A reply needs --message.');

                return self::FAILURE;
            }
            if (! $this->act((string) $id, 'reply', $message)) {
                return self::FAILURE;
            }
        }

        if ($this->option('resolve') || $this->option('reopen') || $this->option('reply')) {
            return self::SUCCESS;
        }

        $comments = $this->comments();
        if ($comments === null) {
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($comments === []) {
            $this->info($this->option('all') ? 'No feedback yet.' : 'No open feedback.');

            return self::SUCCESS;
        }

        foreach ($comments as $comment) {
            $this->printComment($comment);
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function comments(): ?array
    {
        $status = $this->option('all') ? null : FeedbackStore::OPEN;

        if (! $this->option('from')) {
            $url = $this->option('url') ? '/'.trim((string) $this->option('url'), '/') : null;
            $blocks = Blocks::pageBuilder();

            return array_map(function (array $comment) use ($blocks) {
                $handle = $comment['anchor']['block'] ?? null;
                $comment['anchor']['block_name'] = is_string($handle) && isset($blocks[$handle]) ? $blocks[$handle]['display'] : null;

                return $comment;
            }, app(FeedbackStore::class)->all($url, $status));
        }

        $response = $this->remote()->get($this->endpoint('api/comments'), array_filter([
            'scope' => $this->option('url') ? 'page' : 'all',
            'url' => $this->option('url'),
            'status' => $status,
        ]));
        if (! $response->successful()) {
            $this->error("The server answered {$response->status()}: ".($response->json('message') ?: 'is FEEDBACK_ENABLED on there, and the password right?'));

            return null;
        }

        return (array) $response->json('comments', []);
    }

    private function act(string $id, string $action, ?string $message = null): bool
    {
        $as = ['name' => (string) $this->option('as'), 'staff' => true];

        if ($this->option('from')) {
            $path = $action === 'reply' ? "api/comments/{$id}/replies" : "api/comments/{$id}/{$action}";
            $response = $this->remote()->post($this->endpoint($path), $message !== null ? ['body' => $message] : []);
            if (! $response->successful()) {
                $this->error("Couldn't {$action} {$id}: the server answered {$response->status()}.");

                return false;
            }
        } else {
            $store = app(FeedbackStore::class);
            $done = match ($action) {
                'resolve' => $store->resolve($id, $as),
                'reopen' => $store->reopen($id),
                'reply' => $store->reply($id, (string) $message, $as),
            };
            if ($done === null) {
                $this->error("There is no comment {$id}.");

                return false;
            }
        }

        $this->info(match ($action) {
            'resolve' => "Resolved {$id}.",
            'reopen' => "Reopened {$id}.",
            'reply' => "Replied to {$id}.",
        });

        return true;
    }

    /** @param  array<string, mixed>  $comment */
    private function printComment(array $comment): void
    {
        $anchor = (array) ($comment['anchor'] ?? []);
        $where = $this->where($anchor);
        $author = (array) ($comment['author'] ?? []);
        $status = ($comment['status'] ?? '') === FeedbackStore::RESOLVED ? '<fg=green>resolved</>' : '<fg=yellow>open</>';
        $viewport = (array) ($comment['viewport'] ?? []);

        $this->newLine();
        $this->line("<options=bold>{$comment['id']}</> {$status}  {$comment['url']}".($where !== '' ? "  <fg=gray>{$where}</>" : ''));
        $this->line('  '.($author['name'] ?? 'Someone').($author['staff'] ?? false ? ' (team)' : '').', '.$this->ago($comment['created_at'] ?? null)
            .(isset($viewport['width']) ? ", {$viewport['width']}px wide".(isset($viewport['breakpoint']) ? " ({$viewport['breakpoint']})" : '') : ''));
        foreach (preg_split('/\R/', (string) ($comment['body'] ?? '')) as $line) {
            $this->line('  '.$line);
        }
        foreach ((array) ($comment['replies'] ?? []) as $reply) {
            $this->line('    <fg=gray>↳ '.($reply['author']['name'] ?? 'Someone').', '.$this->ago($reply['created_at'] ?? null).':</> '.str_replace("\n", ' ', (string) $reply['body']));
        }
        if (! empty($anchor['selector'])) {
            $this->line('  <fg=gray>element: '.$anchor['selector'].'</>');
        }
        if (($comment['status'] ?? '') === FeedbackStore::RESOLVED && ! empty($comment['resolved_by']['name'])) {
            $this->line('  <fg=gray>resolved by '.$comment['resolved_by']['name'].', '.$this->ago($comment['resolved_at'] ?? null).'</>');
        }
    }

    /** Where on the page, in words: the block, then the nearest heading or the element's own text. */
    private function where(array $anchor): string
    {
        $block = $anchor['block_name'] ?? (isset($anchor['block']) ? ucfirst(str_replace('_', ' ', (string) $anchor['block'])) : null);
        $label = $anchor['label'] ?? null;

        return trim(($block ? "{$block} block" : '').($block && $label ? ', ' : '').($label ? (string) $label : ''));
    }

    private function ago(?string $time): string
    {
        return $time ? Carbon::parse($time)->diffForHumans() : 'some time ago';
    }

    private function remote(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()->timeout(20)->withHeaders([
            'X-Feedback-Password' => (string) ($this->option('password') ?: config('statamic-tools.feedback.password')),
            'X-Feedback-Name' => (string) $this->option('as'),
        ]);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) $this->option('from'), '/').'/'.trim((string) config('statamic.routes.action', '!'), '/').'/statamic-tools/feedback/'.$path;
    }
}
