<?php

use App\Http\Controllers\Api\SqlServerExternalSearchSafeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    // Ruta canonica de negocio: no expone el backend tecnico en la URL.
    Route::get('/tracking/eventos', SqlServerExternalSearchSafeController::class);

    // Alias legado para no romper consumidores actuales.
    Route::get('/sqlserver/busqueda', SqlServerExternalSearchSafeController::class);
});
