<?php

use App\Http\Controllers\Api\Citizen\CallAttemptController;
use App\Http\Controllers\Api\Citizen\HomeController;
use App\Http\Controllers\Api\Citizen\IncidentController;
use App\Http\Controllers\Api\Citizen\ReconnectController;
use Illuminate\Support\Facades\Route;

foreach (['citizen', 'caller'] as $prefix) {
    $middleware = ['auth', 'role:'.$prefix];

    if ($prefix === 'caller') {
        $middleware[] = 'legacy.caller:public-api';
    }

    Route::middleware($middleware)->prefix('/'.$prefix)->group(function (): void {
        Route::get('/home', [HomeController::class, 'show']);
        Route::post('/call-attempts', [CallAttemptController::class, 'store']);
        Route::post('/call-attempts/{attempt}/cancel', [CallAttemptController::class, 'cancel']);
        Route::post('/call-attempts/{attempt}/timeout', [CallAttemptController::class, 'timeout']);
        Route::post('/call-sessions/{callSession}/cancel', [ReconnectController::class, 'cancel']);
        Route::post('/call-sessions/{callSession}/hangup', [ReconnectController::class, 'hangup']);
        Route::get('/incidents/current', [IncidentController::class, 'current']);
        Route::get('/incidents/history', [IncidentController::class, 'history']);
        Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
        Route::post('/incidents/{incident}/reconnect', [ReconnectController::class, 'store']);
    });
}
