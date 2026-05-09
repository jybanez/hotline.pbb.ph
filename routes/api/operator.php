<?php

use App\Http\Controllers\Api\Operator\CallAttemptOperatorAttemptController;
use App\Http\Controllers\Api\Operator\CallAttemptController;
use App\Http\Controllers\Api\Operator\CallSessionController;
use App\Http\Controllers\Api\Operator\CallSessionMediaController;
use App\Http\Controllers\Api\Operator\DashboardController;
use App\Http\Controllers\Api\Operator\IncidentController;
use App\Http\Controllers\Api\Operator\MediaLogController;
use App\Http\Controllers\Api\Operator\TeamAssignmentController;
use App\Http\Controllers\Api\Operator\TransferController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:operator'])->prefix('/operator')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::get('/activity', [DashboardController::class, 'activity']);
    Route::post('/call-attempts', [CallAttemptController::class, 'store']);
    Route::get('/incidents', [IncidentController::class, 'index']);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
    Route::post('/incidents/{incident}/status', [IncidentController::class, 'updateStatus']);
    Route::post('/incidents/{incident}/actual-citizen', [IncidentController::class, 'updateActualCaller']);
    Route::post('/incidents/{incident}/actual-caller', [IncidentController::class, 'updateActualCaller'])
        ->middleware('legacy.caller:operator.actual-caller');
    Route::post('/incidents/{incident}/other-details', [IncidentController::class, 'updateOtherDetails']);
    Route::post('/incidents/{incident}/intake', [IncidentController::class, 'updateIntake']);
    Route::post('/incidents/{incident}/citizen-address', [IncidentController::class, 'updateCallerAddress']);
    Route::post('/incidents/{incident}/caller-address', [IncidentController::class, 'updateCallerAddress'])
        ->middleware('legacy.caller:operator.caller-address');
    Route::post('/incidents/{incident}/citizen-location', [IncidentController::class, 'updateCallerLocation']);
    Route::post('/incidents/{incident}/caller-location', [IncidentController::class, 'updateCallerLocation'])
        ->middleware('legacy.caller:operator.caller-location');
    Route::get('/incidents/{incident}/citizen-locations', [IncidentController::class, 'callerLocations']);
    Route::get('/incidents/{incident}/caller-locations', [IncidentController::class, 'callerLocations'])
        ->middleware('legacy.caller:operator.caller-locations');
    Route::post('/incidents/{incident}/incident-types/{incidentType}', [IncidentController::class, 'attachIncidentType']);
    Route::delete('/incidents/{incident}/incident-types/{incidentType}', [IncidentController::class, 'removeIncidentType']);
    Route::post('/incidents/{incident}/incident-types/{incidentType}/details', [IncidentController::class, 'updateIncidentTypeDetail']);
    Route::post('/incidents/{incident}/incident-types/{incidentType}/resources/{resourceType}', [IncidentController::class, 'updateIncidentTypeResource']);
    Route::post('/incidents/{incident}/incident-type-details', [IncidentController::class, 'updateIncidentTypeDetails']);
    Route::post('/incidents/{incident}/transfers', [TransferController::class, 'store']);
    Route::post('/incidents/{incident}/team-assignments', [TeamAssignmentController::class, 'store']);
    Route::post('/call-attempt-operator-attempts/{attempt}/answer', [CallAttemptOperatorAttemptController::class, 'answer']);
    Route::post('/call-attempt-operator-attempts/{attempt}/decline', [CallAttemptOperatorAttemptController::class, 'decline']);
    Route::post('/call-attempt-operator-attempts/{attempt}/citizen-cancel', [CallAttemptOperatorAttemptController::class, 'cancelByCaller']);
    Route::post('/call-attempt-operator-attempts/{attempt}/caller-cancel', [CallAttemptOperatorAttemptController::class, 'cancelByCaller'])
        ->middleware('legacy.caller:operator.caller-cancel');
    Route::post('/call-sessions/{callSession}/answer', [CallSessionController::class, 'answer']);
    Route::post('/call-sessions/{callSession}/ready', [CallSessionController::class, 'ready']);
    Route::post('/call-sessions/{callSession}/hangup', [CallSessionController::class, 'hangup']);
    Route::post('/call-sessions/{callSession}/media', [CallSessionMediaController::class, 'store']);
    Route::post('/media/{media}/chunks', [CallSessionMediaController::class, 'storeChunk']);
    Route::post('/media/{media}/finalize', [CallSessionMediaController::class, 'finalize']);
    Route::post('/media/logs', [MediaLogController::class, 'store']);
    Route::post('/transfers/{transfer}/accept', [TransferController::class, 'accept']);
    Route::post('/transfers/{transfer}/reject', [TransferController::class, 'reject']);
    Route::post('/team-assignments/{assignment}', [TeamAssignmentController::class, 'update']);
    Route::post('/team-assignments/{assignment}/notes', [TeamAssignmentController::class, 'storeNote']);
    Route::delete('/team-assignments/{assignment}', [TeamAssignmentController::class, 'destroy']);
});
