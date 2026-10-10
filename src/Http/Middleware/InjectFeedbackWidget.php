<?php

namespace Avocadesign\StatamicTools\Http\Middleware;

use Avocadesign\StatamicTools\Http\Controllers\FeedbackController;
use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\Data;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the feedback loader to the site's pages: one deferred script before </body>, with the page's entry, so a
 * comment stays with its page when the URL changes. The service provider only registers this while FEEDBACK_ENABLED
 * is true, so with feedback off a request never reaches it and no page changes by a byte.
 *
 * It runs outside Statamic's static cache, so a cached copy never holds the loader and switching feedback off needs no
 * cache clear. Full static caching serves pages without PHP, so those pages show no widget: review with half measure
 * or none. Nothing personal goes into the page either way; the widget asks who is viewing.
 */
class InjectFeedbackWidget
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->wanted($request, $response)) {
            return $response;
        }

        $content = (string) $response->getContent();
        $at = strripos($content, '</body>');
        if ($at === false) {
            return $response;
        }

        $response->setContent(substr($content, 0, $at).$this->tag($request).substr($content, $at));

        return $response;
    }

    private function wanted(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || $request->expectsJson()) {
            return false;
        }
        if (! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return false;
        }

        foreach ((array) config('statamic-tools.feedback.exclude', []) as $pattern) {
            if ($request->is(ltrim((string) $pattern, '/'))) {
                return false;
            }
        }

        return true;
    }

    private function tag(Request $request): string
    {
        $base = '/'.trim((string) config('statamic.routes.action', '!'), '/').'/statamic-tools/feedback';
        $version = substr(md5((string) @filemtime(FeedbackController::scriptPath('loader')).(string) @filemtime(FeedbackController::scriptPath('widget'))), 0, 10);
        $entry = Data::findByRequestUrl($request->url());
        $attributes = [
            'src' => "{$base}/loader.js?v={$version}",
            'data-feedback' => $base,
            'data-feedback-version' => $version,
            'data-feedback-entry' => $entry ? (string) $entry->id() : '',
        ];

        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.e($value).'"';
        }

        return "<script defer{$html}></script>\n";
    }
}
