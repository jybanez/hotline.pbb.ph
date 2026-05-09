<?php

use App\Http\Controllers\Web\SurfaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:admin'])->group(function (): void {
    Route::get('/admin', [SurfaceController::class, 'admin'])->name('admin.home');
});
