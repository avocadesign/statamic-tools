<?php

namespace Avocadesign\StatamicTools\Http\Controllers;

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
            ->with(['site_prefix' => config('statamic-tools.site.prefix', 'site'), ...$data])
            ->render();

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
