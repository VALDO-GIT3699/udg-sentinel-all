<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\Reconciliation\InventoryReconciliationService;

final class InventoryReconciliationController extends Controller
{
    private InventoryReconciliationService $service;

    public function __construct(?InventoryReconciliationService $service = null)
    {
        $this->service = $service ?? new InventoryReconciliationService();
    }

    public function index()
    {
        return view('inventory::reconciliation.index', [
            'batches' => $this->service->getRecentBatches(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_file' => ['required', 'file', 'mimes:xlsx,xls,csv,md,markdown,txt'],
        ]);

        $batch = $this->service->importAndAnalyze($data['source_file'], $request->user()?->id);

        return redirect()
            ->route('inventory.reconciliation.index')
            ->with('status', 'Se analizó el inventario institucional y se generó una conciliación auditable.')
            ->with('batch_id', $batch->id);
    }
}
