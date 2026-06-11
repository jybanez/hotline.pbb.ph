<?php

use App\Http\Controllers\Api\Command\IncidentController;
use App\Http\Controllers\Api\Command\AlertLevelController;
use App\Http\Controllers\Api\Command\BroadcastController;
use App\Http\Controllers\Api\Command\SitrepController;
use App\Http\Controllers\Api\Command\SupportRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:command'])->prefix('/command')->group(function (): void {
    Route::post('/alert-level', [AlertLevelController::class, 'update']);
    Route::post('/broadcasts', [BroadcastController::class, 'store']);
    Route::get('/incidents', [IncidentController::class, 'index']);
    Route::post('/support-requests', [SupportRequestController::class, 'store']);
    Route::get('/sitreps', [SitrepController::class, 'index']);
    Route::post('/sitreps', [SitrepController::class, 'store']);
    Route::get('/sitreps/{sitrep}', [SitrepController::class, 'show']);
    Route::patch('/sitreps/{sitrep}', [SitrepController::class, 'update']);
});
