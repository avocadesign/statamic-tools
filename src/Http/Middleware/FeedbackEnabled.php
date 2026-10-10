<?php

namespace Avocadesign\StatamicTools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every feedback route is a 404 unless FEEDBACK_ENABLED is true, and none of them is ever indexed. */
class FeedbackEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('statamic-tools.feedback.enabled'), 404);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
