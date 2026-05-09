<?php

use App\Http\Controllers\Api\IncidentMediaController;
use App\Http\Controllers\Api\IncidentMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/incidents/{incident}/messages', [IncidentMessageController::class, 'index']);
    Route::post('/incidents/{incident}/messages', [IncidentMessageController::class, 'store']);
    Route::post('/incidents/{incident}/messages/{message}/attachments', [IncidentMessageController::class, 'storeAttachment']);
    Route::get('/incidents/{incident}/media', [IncidentMediaController::class, 'index']);
});
