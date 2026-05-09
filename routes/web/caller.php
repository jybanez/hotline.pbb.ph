<?php

use App\Http\Controllers\Web\SurfaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:caller'])->group(function (): void {
    Route::get('/caller', [SurfaceController::class, 'caller'])->name('caller.home');
    Route::get('/caller/', [SurfaceController::class, 'caller']);
});

Route::view('/caller/offline', 'pages.caller.offline')->name('caller.offline');
