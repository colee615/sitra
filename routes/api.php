<?php

use App\Http\Controllers\Api\SqlServerExternalSearchSafeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:30,1'])->get('/sqlserver/busqueda', SqlServerExternalSearchSafeController::class);
