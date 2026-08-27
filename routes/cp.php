<?php

use Illuminate\Support\Facades\Route;
use Statamic\Sidecar\Http\Controllers\DocumentsController;
use Statamic\Sidecar\Http\Controllers\PreviewController;
use Statamic\Sidecar\Http\Controllers\SourcesController;
use Statamic\Sidecar\Http\Controllers\TreeController;

Route::prefix('sidecar')->name('sidecar.')->group(function () {
    Route::get('/', [SourcesController::class, 'index'])->name('index');

    Route::prefix('{source}')->group(function () {
        Route::get('/', [SourcesController::class, 'show'])->name('source.show');

        Route::get('tree', [TreeController::class, 'index'])->name('source.tree.index');
        Route::patch('tree', [TreeController::class, 'update'])->name('source.tree.update');

        Route::get('create', [DocumentsController::class, 'create'])->name('documents.create');
        Route::post('documents', [DocumentsController::class, 'store'])->name('documents.store');
        Route::post('preview', [PreviewController::class, 'create'])->name('documents.preview.create');

        Route::get('documents/{path}/edit', [DocumentsController::class, 'edit'])
            ->where('path', '.*')
            ->name('documents.edit');

        Route::post('documents/{path}/preview', [PreviewController::class, 'document'])
            ->where('path', '.*')
            ->name('documents.preview.edit');

        Route::get('documents/{path}/preview', [PreviewController::class, 'show'])
            ->where('path', '.*')
            ->name('documents.preview.popout');

        Route::patch('documents/{path}', [DocumentsController::class, 'update'])
            ->where('path', '.*')
            ->name('documents.update');

        Route::delete('documents/{path}', [DocumentsController::class, 'destroy'])
            ->where('path', '.*')
            ->name('documents.destroy');
    });
});
