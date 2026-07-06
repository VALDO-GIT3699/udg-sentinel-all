<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Reports\Services\ExecutiveReportService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportsController extends Controller
{
    public function __construct(private readonly ExecutiveReportService $reportService)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('reports::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('reports::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('reports::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('reports::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}

    public function summary(Request $request): SymfonyResponse
    {
        return response()->json([
            'data' => $this->reportService->build($this->extractFilters($request)),
        ]);
    }

    public function exportExecutiveCsv(Request $request): SymfonyResponse
    {
        $csv = $this->reportService->toCsv($this->extractFilters($request));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=udg-sentinel-report.csv',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        return [
            'asset_type' => $request->string('asset_type')->toString() ?: 'all',
            'asset_role' => $request->string('asset_role')->toString() ?: 'all',
            'status' => $request->string('status')->toString() ?: 'all',
            'severity' => $request->string('severity')->toString() ?: 'all',
        ];
    }
}
