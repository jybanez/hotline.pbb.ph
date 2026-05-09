<?php

use App\Http\Controllers\Web\SurfaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:operator'])->group(function (): void {
    Route::get('/operator', [SurfaceController::class, 'operator'])->name('operator.home');
});
