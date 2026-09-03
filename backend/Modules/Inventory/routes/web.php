<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryReconciliationController;

Route::middleware(['auth', 'verified'])->group(function () {
    // Sube un archivo y dispara un analisis de conciliacion contra el
    // inventario oficial -una accion real con efecto en disco/BD, no de
    // solo lectura. Antes bastaba con tener sesion iniciada, sin exigir
    // ningun permiso de monitoreo; se alinea con el mismo nivel que ya
    // protege otras acciones de mantenimiento (manage_settings).
    Route::prefix('inventory-reconciliation')
        ->name('inventory.reconciliation.')
        ->middleware('permission:monitoring.manage_settings')
        ->group(function (): void {
            Route::get('/', [InventoryReconciliationController::class, 'index'])->name('index');
            Route::post('/', [InventoryReconciliationController::class, 'store'])->name('store');
        });
});
