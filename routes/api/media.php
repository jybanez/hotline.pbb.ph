<?php

use App\Http\Controllers\Api\Media\AssemblyController;
use Illuminate\Support\Facades\Route;

Route::post('/media/assembly/complete', [AssemblyController::class, 'complete']);
