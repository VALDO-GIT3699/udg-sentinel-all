<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryReconciliationController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('inventories', InventoryController::class)->names('inventory');

    Route::prefix('inventory-reconciliation')->name('inventory.reconciliation.')->group(function (): void {
        Route::get('/', [InventoryReconciliationController::class, 'index'])->name('index');
        Route::post('/', [InventoryReconciliationController::class, 'store'])->name('store');
    });
});
