<?php

use App\Http\Controllers\Api\SqlServerSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:30,1'])->get('/sqlserver/busqueda', SqlServerSearchController::class);
