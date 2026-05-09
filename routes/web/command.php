<?php

use App\Http\Controllers\Web\SitrepPageController;
use App\Http\Controllers\Web\SurfaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:command'])->group(function (): void {
    Route::get('/command', [SurfaceController::class, 'command'])->name('command.home');
    Route::get('/command/sitreps/{sitrep}/preview', [SitrepPageController::class, 'preview'])->name('sitrep.command.preview');
    Route::get('/command/sitreps/{sitrep}/download/{format}', [SitrepPageController::class, 'download'])
        ->whereIn('format', ['pdf', 'json', 'zip'])
        ->name('sitrep.command.download');
});
