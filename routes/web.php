<?php

use Avocadesign\StatamicTools\Http\Controllers\PrototypeController;
use Avocadesign\StatamicTools\Http\Controllers\SiteContentController;
use Avocadesign\StatamicTools\Http\Controllers\SiteStyleController;
use Avocadesign\StatamicTools\Http\Middleware\PrototypeAvailable;
use Illuminate\Support\Facades\Route;

$prefix = config('statamic-tools.site.prefix', 'site');

Route::redirect($prefix, "/{$prefix}/content");
Route::get("{$prefix}/style", SiteStyleController::class)->name('statamic-tools.site.style');
Route::get("{$prefix}/content", SiteContentController::class)->name('statamic-tools.site.content');
Route::get("{$prefix}/content/render", [SiteContentController::class, 'render'])->name('statamic-tools.site.content.render');

// The discovery prototype: /prototype opens the default version, /prototype/2 that version. A 404 when switched off,
// which it is by default anywhere but local and staging, or when the site has no prototype folder. A site that still
// has its own copy of the prototype's controller, from before it moved into the add-on, keeps serving that instead.
if (! is_file(app_path('Http/Controllers/PrototypeController.php'))) {
    Route::middleware(PrototypeAvailable::class)->group(function () {
        Route::get('prototype', [PrototypeController::class, 'show'])->name('statamic-tools.prototype');
        Route::post('prototype', [PrototypeController::class, 'signIn'])->middleware('throttle:10,1');
        Route::get('prototype/sign-out', [PrototypeController::class, 'signOut']);
        Route::get('prototype/{version}', [PrototypeController::class, 'show'])->where('version', '[A-Za-z0-9-]+');
    });
}
