<?php

use App\Http\Controllers\Api\Internal\MediaChunkIngressController;
use App\Http\Controllers\Api\Internal\RealtimeProductQueryController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware([VerifyCsrfToken::class])
    ->prefix('/internal')
    ->group(function (): void {
        Route::post('/media/chunks', [MediaChunkIngressController::class, 'store']);
        Route::post('/realtime/product-query', [RealtimeProductQueryController::class, 'store']);
    });
