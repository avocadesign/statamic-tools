<?php

use Avocadesign\StatamicTools\Http\Controllers\SiteContentController;
use Avocadesign\StatamicTools\Http\Controllers\SiteStyleController;
use Illuminate\Support\Facades\Route;

$prefix = config('statamic-tools.site.prefix', 'site');

Route::redirect($prefix, "/{$prefix}/content");
Route::get("{$prefix}/style", SiteStyleController::class)->name('statamic-tools.site.style');
Route::get("{$prefix}/content", SiteContentController::class)->name('statamic-tools.site.content');
Route::get("{$prefix}/content/render", [SiteContentController::class, 'render'])->name('statamic-tools.site.content.render');
