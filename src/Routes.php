<?php

namespace Hibira\Statamic;

use Hibira\Statamic\Http\Controllers\AuthController;
use Hibira\Statamic\Http\Controllers\CommentController;
use Hibira\Statamic\Http\Controllers\ConfigController;
use Hibira\Statamic\Http\Controllers\PreviewLinkController;
use Illuminate\Support\Facades\Route;

/** The addon's control panel routes, all behind Statamic's control panel sign-in. */
class Routes
{
    public const ENTRY = '__ENTRY__';

    public const COMMENT = '__COMMENT__';

    public static function register(): void
    {
        Route::prefix('hibira')->name('hibira.')->group(function () {
            Route::get('config', ConfigController::class)->name('config');
            Route::post('auth', AuthController::class)->name('auth');

            Route::prefix('entries/{hibiraEntry}')->group(function () {
                Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
                Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
                Route::post('comments/{hibiraComment}/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
                Route::delete('comments/{hibiraComment}', [CommentController::class, 'destroy'])->name('comments.destroy');
                Route::get('preview-link', PreviewLinkController::class)->name('preview-link');
            });
        });
    }

    /** @return array<string, string> URLs for the browser, with placeholders for the entry and comment IDs */
    public static function urls(): array
    {
        $entry = ['hibiraEntry' => static::ENTRY];
        $comment = $entry + ['hibiraComment' => static::COMMENT];

        return [
            'auth' => cp_route('hibira.auth'),
            'comments' => cp_route('hibira.comments.index', $entry),
            'comment' => cp_route('hibira.comments.destroy', $comment),
            'resolve' => cp_route('hibira.comments.resolve', $comment),
            'previewLink' => cp_route('hibira.preview-link', $entry),
        ];
    }
}
