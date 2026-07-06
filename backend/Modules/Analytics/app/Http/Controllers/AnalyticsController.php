<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Analytics\Services\AnalyticsService;

final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Analytics/Overview', [
            'summary' => $this->analyticsService->executiveSummary(),
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => $this->analyticsService->executiveSummary(),
        ]);
    }
}
