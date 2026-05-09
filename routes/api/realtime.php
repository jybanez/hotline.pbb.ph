<?php

use App\Http\Controllers\Api\Realtime\AdmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:caller'])->post('/realtime/admission/caller', [AdmissionController::class, 'caller']);
Route::middleware(['auth', 'role:operator'])->post('/realtime/admission/operator', [AdmissionController::class, 'operator']);
Route::middleware(['auth', 'role:command'])->post('/realtime/admission/command', [AdmissionController::class, 'command']);
