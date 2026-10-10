<?php

use Avocadesign\StatamicTools\Http\Controllers\FeedbackController;
use Avocadesign\StatamicTools\Http\Middleware\FeedbackEnabled;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Feedback, under /!/statamic-tools/feedback: a 404 unless FEEDBACK_ENABLED is true. The widget signs in and writes
// with the session's token; avoca:feedback sends the password instead, from any machine, so its routes skip the token.
Route::middleware(FeedbackEnabled::class)->prefix('feedback')->name('statamic-tools.feedback.')->group(function () {
    Route::get('{file}.js', [FeedbackController::class, 'script'])->where('file', 'loader|widget')->name('script');
    Route::get('session', [FeedbackController::class, 'session'])->name('session');
    Route::post('sign-in', [FeedbackController::class, 'signIn'])->middleware('throttle:10,1')->name('sign-in');
    Route::post('sign-out', [FeedbackController::class, 'signOut'])->name('sign-out');
    Route::get('count', [FeedbackController::class, 'count'])->name('count');

    Route::middleware('throttle:60,1')->group(function () {
        Route::get('comments', [FeedbackController::class, 'index'])->name('index');
        Route::post('comments', [FeedbackController::class, 'store'])->name('store');
        Route::post('comments/{id}/replies', [FeedbackController::class, 'reply'])->name('reply');
        Route::post('comments/{id}/resolve', [FeedbackController::class, 'resolve'])->name('resolve');
        Route::post('comments/{id}/reopen', [FeedbackController::class, 'reopen'])->name('reopen');
        Route::post('comments/{id}/decision', [FeedbackController::class, 'decide'])->name('decide');
        Route::post('comments/{id}/anchor', [FeedbackController::class, 'move'])->name('move');
    });

    Route::prefix('api')->name('api.')->middleware('throttle:30,1')
        ->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class])
        ->group(function () {
            Route::get('comments', [FeedbackController::class, 'index'])->name('index');
            Route::post('comments/{id}/replies', [FeedbackController::class, 'reply'])->name('reply');
            Route::post('comments/{id}/resolve', [FeedbackController::class, 'resolve'])->name('resolve');
            Route::post('comments/{id}/reopen', [FeedbackController::class, 'reopen'])->name('reopen');
            Route::post('comments/{id}/decision', [FeedbackController::class, 'decide'])->name('decide');
        });
});
