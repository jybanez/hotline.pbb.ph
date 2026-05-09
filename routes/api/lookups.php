<?php

use App\Http\Controllers\Api\Lookup\LookupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('/lookups')->group(function (): void {
    Route::get('/incident-categories', [LookupController::class, 'incidentCategories']);
    Route::get('/incident-types', [LookupController::class, 'incidentTypes']);
    Route::get('/resource-types', [LookupController::class, 'resourceTypes']);
    Route::get('/team-categories', [LookupController::class, 'teamCategories']);
    Route::get('/teams', [LookupController::class, 'teams']);
});
