<?php

use Illuminate\Support\Facades\Route;
use Statamic\Sidecar\Drivers\Jigsaw\LivePreviewController as JigsawLivePreviewController;
use Statamic\Sidecar\Drivers\LaraDocs\LivePreviewController as LaraDocsLivePreviewController;

Route::get('jigsaw/live-preview', JigsawLivePreviewController::class)
    ->name('sidecar.jigsaw.live-preview');

Route::get('laradocs/live-preview', LaraDocsLivePreviewController::class)
    ->name('sidecar.laradocs.live-preview');
