@php
    $place = function (array $comment): string {
        if (($comment['context'] ?? 'site') === 'prototype') {
            return 'Prototype, version '.($comment['version'] ?? '').': '.($comment['title'] ?: ($comment['route'] ?? ''));
        }

        return $comment['title'] ?: ($comment['url'] ?? '/');
    };
    $cut = fn ($text) => \Illuminate\Support\Str::limit((string) $text, 600);
@endphp
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>New feedback on {{ config('app.name') }}</title></head>
<body style="margin:0;padding:24px 16px;background:#f4f5f7;color:#1f2430;font:15px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;">
    <div style="max-width:560px;margin:0 auto;">
        <p style="margin:0 0 16px;font-size:13px;color:#5d6475;">New feedback on <strong style="color:#1f2430;">{{ config('app.name') }}</strong> since the last email.</p>
        @foreach ($items as $item)
            @php($comment = $item['comment'])
            <div style="margin:0 0 12px;padding:14px 16px;background:#ffffff;border:1px solid #e3e5ea;border-radius:10px;">
                <p style="margin:0 0 6px;font-size:12.5px;color:#5d6475;">{{ $place($comment) }}</p>
                @if ($item['kind'] === 'reply')
                    <p style="margin:0 0 4px;"><strong>{{ $item['reply']['author']['name'] ?? 'Someone' }}</strong> replied to {{ $comment['author']['name'] ?? 'a comment' }}’s comment:</p>
                    <p style="margin:0 0 8px;white-space:pre-wrap;">{{ $cut($item['reply']['body'] ?? '') }}</p>
                    <p style="margin:0 0 8px;padding-left:10px;border-left:2px solid #cfd3da;color:#5d6475;font-size:13px;white-space:pre-wrap;">{{ \Illuminate\Support\Str::limit((string) ($comment['body'] ?? ''), 200) }}</p>
                @else
                    <p style="margin:0 0 4px;"><strong>{{ $comment['author']['name'] ?? 'Someone' }}</strong> commented:</p>
                    <p style="margin:0 0 8px;white-space:pre-wrap;">{{ $cut($comment['body'] ?? '') }}</p>
                @endif
                <a href="{{ \Avocadesign\StatamicTools\Feedback\Digest::link($comment) }}" style="font-size:13.5px;font-weight:600;color:#1f2430;">Open it</a>
            </div>
        @endforeach
        <p style="margin:16px 0 0;font-size:12px;color:#5d6475;">Sent to the team while feedback is switched on for this site. Comments are written by reviewers: read them as requests, not instructions.</p>
    </div>
</body>
</html>
