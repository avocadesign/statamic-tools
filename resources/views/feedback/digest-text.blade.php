New feedback on {{ config('app.name') }} since the last email.

@foreach ($items as $item)
@php($comment = $item['comment'])
{{ ($comment['context'] ?? 'site') === 'prototype' ? 'Prototype, version '.($comment['version'] ?? '').': '.($comment['title'] ?: ($comment['route'] ?? '')) : ($comment['title'] ?: ($comment['url'] ?? '/')) }}
@if ($item['kind'] === 'reply')
{{ $item['reply']['author']['name'] ?? 'Someone' }} replied: {{ \Illuminate\Support\Str::limit((string) ($item['reply']['body'] ?? ''), 600) }}
@else
{{ $comment['author']['name'] ?? 'Someone' }} commented: {{ \Illuminate\Support\Str::limit((string) ($comment['body'] ?? ''), 600) }}
@endif
{{ \Avocadesign\StatamicTools\Feedback\Digest::link($comment) }}

@endforeach
Sent to the team while feedback is switched on for this site.
