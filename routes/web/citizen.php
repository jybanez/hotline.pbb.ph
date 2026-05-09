<?php

use App\Http\Controllers\Web\SurfaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:citizen'])->group(function (): void {
    Route::get('/citizen', [SurfaceController::class, 'citizen'])->name('citizen.home');
    Route::get('/citizen/', [SurfaceController::class, 'citizen']);
});

Route::middleware(['role:caller'])->group(function (): void {
    Route::get('/caller', [SurfaceController::class, 'citizen'])->name('caller.home');
    Route::get('/caller/', [SurfaceController::class, 'citizen']);
});

Route::view('/citizen/offline', 'pages.citizen.offline')->name('citizen.offline');
Route::view('/caller/offline', 'pages.citizen.offline')->name('caller.offline');
