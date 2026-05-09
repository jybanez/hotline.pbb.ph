<?php

use App\Http\Controllers\Api\Session\CurrentUserController;
use App\Http\Controllers\Api\Session\CsrfTokenController;
use App\Http\Controllers\Api\Session\LoginController;
use App\Http\Controllers\Api\Session\LogoutController;
use App\Http\Controllers\Api\Session\ReauthController;
use App\Http\Controllers\Api\Session\SessionPingController;
use Illuminate\Support\Facades\Route;

Route::get('/csrf-token', [CsrfTokenController::class, 'show']);
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
Route::post('/reauth', [ReauthController::class, 'store'])->middleware('throttle:6,1');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LogoutController::class, 'store']);
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::post('/user', [CurrentUserController::class, 'update']);
    Route::post('/user/password', [CurrentUserController::class, 'updatePassword']);
    Route::get('/session/ping', [SessionPingController::class, 'show']);
});
