<?php

namespace Avocadesign\StatamicTools\Http\Middleware;

use Avocadesign\StatamicTools\Feedback\FeedbackSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every feedback route is a 404 unless feedback is on: FEEDBACK_ENABLED, and PROTOTYPE_PASSWORD anywhere but a local
 * machine. None of them is ever indexed.
 */
class FeedbackEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeedbackSettings::active(), 404);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
