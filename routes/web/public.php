<?php

use App\Http\Controllers\Web\SurfaceController;
use App\Http\Controllers\Web\SitrepPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SurfaceController::class, 'public'])->name('public.home');
Route::get('/sitrep/{sitrep}', [SitrepPageController::class, 'public'])->name('sitrep.public.show');
