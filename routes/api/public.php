<?php

use App\Http\Controllers\Api\Public\AlertLevelController;
use App\Http\Controllers\Api\Public\BootstrapController;
use App\Http\Controllers\Api\Public\CommunityStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', [BootstrapController::class, 'show']);
Route::get('/public/alert-level', [AlertLevelController::class, 'show']);
Route::get('/public/community-status', [CommunityStatusController::class, 'status']);
Route::get('/public/community-realtime', [CommunityStatusController::class, 'realtime']);
