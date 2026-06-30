<?php

use App\Http\Controllers\Api\AccountAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware('account.admin')
    ->prefix('/account-admin')
    ->group(function (): void {
        Route::get('/meta', [AccountAdminController::class, 'meta']);
        Route::get('/users/{pbb_user_id}', [AccountAdminController::class, 'show']);
        Route::put('/users/{pbb_user_id}', [AccountAdminController::class, 'provision']);
        Route::patch('/users/{pbb_user_id}/role', [AccountAdminController::class, 'updateRole']);
        Route::patch('/users/{pbb_user_id}/status', [AccountAdminController::class, 'updateStatus']);
    });
