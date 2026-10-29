<?php

use App\Http\Controllers\Api\CdsDeclarationLookupController;
use App\Http\Controllers\Api\CustomsOperationsController;
use App\Http\Controllers\Api\IpsController;
use App\Http\Controllers\Api\SqlServerExternalDestinationsBatchController;
use App\Http\Controllers\Api\SqlServerExternalSearchAllController;
use App\Http\Controllers\Api\SqlServerExternalSearchBatchController;
use App\Http\Controllers\Api\SqlServerExternalSearchSafeController;
use App\Http\Controllers\Api\SqlServerPackageListController;
use App\Http\Middleware\AuthorizeIps;
use App\Http\Middleware\AuthorizeCustoms;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/ips')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::middleware(AuthorizeIps::class.':ips.read')->group(function () {
        Route::get('catalogos', [IpsController::class, 'catalog']);
        Route::get('catalogos/entrega', [IpsController::class, 'deliveryCatalog']);
        Route::get('usuarios', [IpsController::class, 'users']);
        Route::get('usuarios/{pid}', [IpsController::class, 'user'])->whereNumber('pid');
        Route::get('paquetes', [IpsController::class, 'index']);
        Route::get('paquetes/pendientes-entrega', [IpsController::class, 'index'])->defaults('ips_pending', true);
        Route::get('envases-pendientes-recepcion', [IpsController::class, 'expectedInboundReceptacles']);
        Route::get('envases-pendientes-apertura', [IpsController::class, 'pendingReceptacleOpenings']);
        Route::get('envases-abiertos-registro-paquetes', [IpsController::class, 'openedReceptaclesForPackageRegistration']);
        Route::get('paquetes/{codigo}', [IpsController::class, 'show'])->where('codigo', '[A-Za-z0-9-]{1,35}');
        Route::get('envases/{codigo}', [IpsController::class, 'showReceptacle'])->where('codigo', '[A-Za-z0-9-]{1,35}');
    });
    Route::get('operaciones/{id}', [IpsController::class, 'operation'])->whereUuid('id')->middleware(AuthorizeIps::class.':ips.operations');
    Route::post('paquetes', [IpsController::class, 'write'])->defaults('ips_action', 'create')->middleware(AuthorizeIps::class.':ips.create');
    Route::post('usuarios', [IpsController::class, 'createUser'])->middleware(AuthorizeIps::class.':ips.write');
    Route::post('paquetes/{codigo}/eventos', [IpsController::class, 'write'])->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeIps::class.':ips.events');
    Route::post('envases/{codigo}/eventos', [IpsController::class, 'write'])->where('codigo', '[A-Za-z0-9-]{1,35}')->defaults('ips_action', 'receptacle')->middleware(AuthorizeIps::class.':ips.events');
    Route::post('paquetes/{codigo}/entrega', [IpsController::class, 'write'])->where('codigo', '[A-Za-z0-9-]{1,35}')->defaults('ips_event', 'EMI')->middleware(AuthorizeIps::class.':ips.deliver');
});

Route::prefix('v1/postal')->middleware(['auth:sanctum', 'throttle:customs-api'])->group(function () {
    Route::get('consulta/{codigo}', [CustomsOperationsController::class, 'lookup'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.read');
    Route::get('paquetes/{codigo}', [CustomsOperationsController::class, 'package'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.read');
    Route::get('declaraciones/{codigo}', [CustomsOperationsController::class, 'declarations'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.read');
    Route::get('prealertas', [CustomsOperationsController::class, 'prealerts'])
        ->middleware(AuthorizeCustoms::class.':customs.prealerts.read');
    Route::get('prealertas/{codigo}', [CustomsOperationsController::class, 'prealert'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.prealerts.read');
    Route::get('despachos/{codigo}', [CustomsOperationsController::class, 'dispatch'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.read');
    Route::get('sacas/{codigo}', [CustomsOperationsController::class, 'receptacle'])
        ->where('codigo', '[A-Za-z0-9-]{1,35}')->middleware(AuthorizeCustoms::class.':customs.read');
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Ruta canonica de negocio: no expone el backend tecnico en la URL.
    Route::get('/tracking/eventos', SqlServerExternalSearchSafeController::class);
    Route::post('/tracking/eventos/batch', SqlServerExternalSearchBatchController::class);
    Route::post('/tracking/destinos/batch', SqlServerExternalDestinationsBatchController::class);
    Route::get('/tracking/eventos-todos', SqlServerExternalSearchAllController::class);
    Route::get('/tracking/paquetes', SqlServerPackageListController::class);
    Route::get('/tracking/cds/declaraciones', CdsDeclarationLookupController::class)
        ->middleware('throttle:30,1');
    Route::post('/tracking/cds/declaraciones', CdsDeclarationLookupController::class)
        ->middleware('throttle:30,1');
});
