<?php

use App\Http\Controllers\Api\Internal\MediaChunkIngressController;
use App\Http\Controllers\Api\Internal\RealtimeProductQueryController;
use App\Http\Controllers\Api\Internal\SitrepMediaController;
use App\Http\Controllers\Api\Internal\SupportRequestUpdateController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware([VerifyCsrfToken::class])
    ->prefix('/internal')
    ->group(function (): void {
        Route::post('/media/chunks', [MediaChunkIngressController::class, 'store']);
        Route::post('/realtime/product-query', [RealtimeProductQueryController::class, 'store']);
        Route::post('/relay/support-request-updates', [SupportRequestUpdateController::class, 'store']);
        Route::post('/sitrep/media/manifest', [SitrepMediaController::class, 'manifest']);
        Route::get('/sitrep/media/{kind}/{id}', [SitrepMediaController::class, 'download'])
            ->whereIn('kind', ['incident_media', 'message_attachment'])
            ->whereNumber('id');
    });
