<?php

namespace Avocadesign\StatamicTools\Http\Middleware;

use Avocadesign\StatamicTools\Prototype\Prototype;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The prototype is a 404 when it is switched off, which it is by default anywhere but local and staging, and when the
 * site has no prototype folder. It is never indexed.
 */
class PrototypeAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Prototype::enabled() && is_dir(Prototype::folder()), 404);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
