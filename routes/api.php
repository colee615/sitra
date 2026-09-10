<?php

use App\Http\Controllers\Api\IpsController;
use App\Http\Controllers\Api\SqlServerExternalSearchAllController;
use App\Http\Controllers\Api\SqlServerExternalSearchBatchController;
use App\Http\Controllers\Api\SqlServerExternalSearchSafeController;
use App\Http\Controllers\Api\SqlServerPackageListController;
use App\Http\Middleware\AuthorizeIps;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/ips')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::middleware(AuthorizeIps::class.':ips.read')->group(function () {
        Route::get('catalogos', [IpsController::class, 'catalog']);
        Route::get('paquetes', [IpsController::class, 'index']);
        Route::get('paquetes/pendientes-entrega', [IpsController::class, 'index'])->defaults('ips_pending', true);
        Route::get('paquetes/{codigo}', [IpsController::class, 'show'])->where('codigo', '[A-Za-z0-9-]{1,35}');
    });
    Route::get('operaciones/{id}', [IpsController::class, 'operation'])->whereUuid('id')->middleware(AuthorizeIps::class.':ips.operations');
    Route::post('paquetes', [IpsController::class, 'write'])->defaults('ips_action', 'create')->middleware(AuthorizeIps::class.':ips.create');
    Route::post('paquetes/{codigo}/eventos', [IpsController::class, 'write'])->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeIps::class.':ips.events');
    Route::post('paquetes/{codigo}/entrega', [IpsController::class, 'write'])->where('codigo', '[A-Za-z0-9-]{1,35}')->defaults('ips_event', 'EMI')->middleware(AuthorizeIps::class.':ips.deliver');
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Ruta canonica de negocio: no expone el backend tecnico en la URL.
    Route::get('/tracking/eventos', SqlServerExternalSearchSafeController::class);
    Route::post('/tracking/eventos/batch', SqlServerExternalSearchBatchController::class);
    Route::get('/tracking/eventos-todos', SqlServerExternalSearchAllController::class);
    Route::get('/tracking/paquetes', SqlServerPackageListController::class);
});
