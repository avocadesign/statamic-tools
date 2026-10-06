<?php

namespace Avocadesign\StatamicTools\Http\Controllers;

use Avocadesign\StatamicTools\Site\Layout;
use Illuminate\Http\Response;
use Statamic\Facades\User;
use Statamic\View\View;

abstract class SiteController
{
    protected function gate(): void
    {
        if (config('statamic-tools.site.protect_in_production') && app()->environment('production') && ! User::current()) {
            abort(404);
        }
    }

    protected function page(string $template, array $data): Response
    {
        $html = (new View)
            ->template("statamic-tools::site.{$template}")
            ->layout('statamic-tools::site.layout')
            ->with(['site_prefix' => config('statamic-tools.site.prefix', 'site'), ...self::frame(), ...$data])
            ->render();

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The site's page background and header, so the reference pages look like its pages: from config, or its layout. */
    private static function frame(): array
    {
        $cfg = config('statamic-tools.site');
        $header = $cfg['header'] ?? 'auto';

        return [
            'site_body_class' => $cfg['body_class'] ?? Layout::bodyClass(resource_path('views/'.($cfg['layout_template'] ?? 'layout').'.antlers.html')),
            'header_mode' => in_array($header, ['auto', 'flow', 'hidden'], true) ? $header : 'auto',
        ];
    }
}
