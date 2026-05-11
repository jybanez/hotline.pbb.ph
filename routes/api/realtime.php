<?php

use App\Http\Controllers\Api\Realtime\AdmissionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:citizen'])->post('/realtime/admission/citizen', [AdmissionController::class, 'citizen']);
Route::middleware(['auth', 'role:caller', 'legacy.caller:realtime.admission'])->post('/realtime/admission/caller', [AdmissionController::class, 'caller']);
Route::middleware(['auth', 'role:operator'])->post('/realtime/admission/operator', [AdmissionController::class, 'operator']);
Route::middleware(['auth', 'role:command'])->post('/realtime/admission/command', [AdmissionController::class, 'command']);
